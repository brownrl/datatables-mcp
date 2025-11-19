<?php

namespace DataTablesMcp;

/**
 * MCP Server implementation using JSON-RPC 2.0 over stdio
 * 
 * How it works:
 * 1. Reads JSON-RPC requests from stdin (one per line)
 * 2. Dispatches to appropriate handler method
 * 3. Writes JSON-RPC responses to stdout
 * 4. Logs to stderr for debugging (doesn't interfere with protocol)
 */
class McpServer
{
    private SearchEngine $searchEngine;
    private bool $running = true;
    private bool $initialized = false;

    public function __construct(SearchEngine $searchEngine)
    {
        $this->searchEngine = $searchEngine;
    }

    /**
     * Main server loop - reads from stdin, processes requests, writes to stdout
     */
    public function run(): void
    {
        $this->log("DataTables MCP Server starting...");

        while ($this->running && !feof(STDIN)) {
            $line = fgets(STDIN);
            
            if ($line === false || trim($line) === '') {
                continue;
            }

            $this->log("Received: " . trim($line));
            
            try {
                $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $response = $this->handleRequest($request);
                
                if ($response !== null) {
                    $this->sendResponse($response);
                }
            } catch (\Throwable $e) {
                $this->log("Error: " . $e->getMessage());
                $this->sendError(-32700, "Parse error: " . $e->getMessage(), null);
            }
        }

        $this->log("Server shutting down");
    }

    /**
     * Route JSON-RPC request to appropriate handler
     */
    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        $this->log("Handling method: $method");

        // Handle notifications (no response required)
        if ($id === null) {
            if ($method === 'notifications/initialized') {
                $this->handleInitializedNotification();
            } else {
                $this->log("Unknown notification: $method");
            }
            return null;
        }

        // Validate initialization state for non-initialize requests
        if ($method !== 'initialize' && !$this->initialized) {
            $this->log("Rejecting $method - not initialized yet");
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32002,
                    'message' => 'Server not initialized. Send initialize request first.'
                ]
            ];
        }

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params),
                'resources/list' => $this->handleResourcesList(),
                'prompts/list' => $this->handlePromptsList(),
                default => throw new \Exception("Unknown method: $method")
            };

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result
            ];
        } catch (\Throwable $e) {
            $this->log("Method error: " . $e->getMessage());
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Initialize handshake with client
     */
    private function handleInitialize(array $params): array
    {
        $this->log("Client initializing: " . ($params['clientInfo']['name'] ?? 'unknown'));
        
        return [
            'protocolVersion' => '2025-06-18',
            'serverInfo' => [
                'name' => 'datatables-mcp',
                'version' => '1.0.0'
            ],
            'capabilities' => [
                'tools' => (object)[],
                'resources' => (object)[],
                'prompts' => (object)[]
            ]
        ];
    }

    /**
     * Handle initialized notification from client
     */
    private function handleInitializedNotification(): void
    {
        $this->log("Client sent initialized notification - server is now ready");
        $this->initialized = true;
    }

    /**
     * List available tools
     */
    private function handleToolsList(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'search_datatables',
                    'description' => 'Search DataTables.net documentation and examples. Returns relevant documentation sections with titles, URLs, and content excerpts.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query (e.g., "ajax options", "server-side processing", "column rendering")'
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Maximum number of results to return (default: 10)',
                                'default' => 10
                            ]
                        ],
                        'required' => ['query']
                    ]
                ]
            ]
        ];
    }

    /**
     * Execute a tool call
     */
    private function handleToolsCall(array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $this->log("Tool call: $toolName with args: " . json_encode($arguments));

        if ($toolName === 'search_datatables') {
            $query = $arguments['query'] ?? '';
            $limit = $arguments['limit'] ?? 10;

            if (empty($query)) {
                throw new \Exception("Query parameter is required");
            }

            $results = $this->searchEngine->search($query, $limit);
            
            // Enrich results with structured data
            $enrichedResults = $this->enrichWithStructuredData($results);
            
            // Format results as text content
            $content = $this->formatSearchResults($enrichedResults, $query);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $content
                    ]
                ]
            ];
        }

        throw new \Exception("Unknown tool: $toolName");
    }

    /**
     * Format search results for display
     */
    private function formatSearchResults(array $results, string $query): string
    {
        if (empty($results)) {
            return "No results found for query: \"$query\"";
        }

        $output = "Found " . count($results) . " results for \"$query\":\n\n";
        
        foreach ($results as $i => $result) {
            $num = $i + 1;
            $output .= "[$num] {$result['title']}\n";
            $output .= "URL: {$result['url']}\n";
            $output .= "Type: {$result['doc_type']}";
            
            if (!empty($result['section'])) {
                $output .= " | Section: {$result['section']}";
            }
            
            $output .= "\n";
            
            // Show content excerpt
            $excerpt = $this->createExcerpt($result['content'], 300);
            $output .= "Content: $excerpt\n\n";
            $output .= "---\n\n";
        }

        return $output;
    }

    /**
     * Enrich search results with structured data
     */
    private function enrichWithStructuredData(array $results): array
    {
        foreach ($results as &$result) {
            $docId = $result['id'] ?? null;
            if (!$docId) continue;
            
            // Get parameters
            $stmt = $this->db->prepare("SELECT name, type, optional, default_value, description FROM parameters WHERE doc_id = ? ORDER BY position");
            $stmt->execute([$docId]);
            $result['parameters'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Get code examples
            $stmt = $this->db->prepare("SELECT title, code, language FROM code_examples WHERE doc_id = ?");
            $stmt->execute([$docId]);
            $result['examples'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Get related items
            $stmt = $this->db->prepare("SELECT category, related_doc_title FROM related_items WHERE doc_id = ?");
            $stmt->execute([$docId]);
            $related = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $result['related'] = [];
            foreach ($related as $item) {
                $category = $item['category'];
                if (!isset($result['related'][$category])) {
                    $result['related'][$category] = [];
                }
                $result['related'][$category][] = $item['related_doc_title'];
            }
            
            // Get return type
            $stmt = $this->db->prepare("SELECT type, description FROM return_types WHERE doc_id = ? LIMIT 1");
            $stmt->execute([$docId]);
            $result['returns'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        
        return $results;
    }
    
    /**
     * Create content excerpt with reasonable length
     */
    private function createExcerpt(string $content, int $maxLength = 300): string
    {
        $content = trim($content);
        
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        // Try to cut at sentence boundary
        $excerpt = substr($content, 0, $maxLength);
        $lastPeriod = strrpos($excerpt, '.');
        $lastSpace = strrpos($excerpt, ' ');

        if ($lastPeriod !== false && $lastPeriod > $maxLength * 0.7) {
            return substr($content, 0, $lastPeriod + 1);
        } elseif ($lastSpace !== false) {
            return substr($content, 0, $lastSpace) . '...';
        }

        return $excerpt . '...';
    }

    /**
     * List available resources (currently none)
     */
    private function handleResourcesList(): array
    {
        return ['resources' => []];
    }

    /**
     * List available prompts (currently none)
     */
    private function handlePromptsList(): array
    {
        return ['prompts' => []];
    }

    /**
     * Send JSON-RPC response to stdout
     */
    private function sendResponse(array $response): void
    {
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $this->log("Sending: $json");
        fwrite(STDOUT, $json . "\n");
        fflush(STDOUT);
    }

    /**
     * Send JSON-RPC error response
     */
    private function sendError(int $code, string $message, $id): void
    {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
        $this->sendResponse($response);
    }

    /**
     * Log to stderr (doesn't interfere with stdio protocol)
     */
    private function log(string $message): void
    {
        fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] $message\n");
    }
}
