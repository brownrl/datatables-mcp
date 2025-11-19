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
    private \PDO $db;
    private bool $running = true;
    private bool $initialized = false;

    public function __construct(SearchEngine $searchEngine)
    {
        $this->searchEngine = $searchEngine;
        $this->db = $searchEngine->getDb();
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
                ],
                [
                    'name' => 'get_function_details',
                    'description' => 'Get detailed information about a specific DataTables function, option, or event. Returns full structured details including parameters, return types, code examples, and related items.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Function, option, or event name (e.g., "ajax.reload", "columns.data", "initComplete")'
                            ]
                        ],
                        'required' => ['name']
                    ]
                ],
                [
                    'name' => 'search_by_example',
                    'description' => 'Search specifically within code examples. Useful for finding functions based on how they are used in practice. Returns functions with matching code examples.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search terms to find in code examples (e.g., "setInterval", "$.ajax", "className")'
                            ],
                            'language' => [
                                'type' => 'string',
                                'description' => 'Optional: Filter by language (javascript, html, css, sql)',
                                'enum' => ['javascript', 'html', 'css', 'sql']
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Maximum number of results to return (default: 10)',
                                'default' => 10
                            ]
                        ],
                        'required' => ['query']
                    ]
                ],
                [
                    'name' => 'search_by_topic',
                    'description' => 'Search DataTables documentation filtered by topic/category. Useful for focused searches within specific documentation areas.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query'
                            ],
                            'section' => [
                                'type' => 'string',
                                'description' => 'Filter by section (API, Options, Events, etc.)',
                                'enum' => ['API', 'Options', 'Events', 'Styling', 'Installation', 'Data', 'Ajax', 'Search', 'Server-side', 'Plug-ins']
                            ],
                            'doc_type' => [
                                'type' => 'string',
                                'description' => 'Filter by documentation type',
                                'enum' => ['reference', 'manual', 'example', 'extension']
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
        
        if ($toolName === 'get_function_details') {
            $name = $arguments['name'] ?? '';

            if (empty($name)) {
                throw new \Exception("Name parameter is required");
            }

            $content = $this->getFunctionDetails($name);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $content
                    ]
                ]
            ];
        }
        
        if ($toolName === 'search_by_example') {
            $query = $arguments['query'] ?? '';
            $language = $arguments['language'] ?? null;
            $limit = $arguments['limit'] ?? 10;

            if (empty($query)) {
                throw new \Exception("Query parameter is required");
            }

            $content = $this->searchByExample($query, $language, $limit);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $content
                    ]
                ]
            ];
        }
        
        if ($toolName === 'search_by_topic') {
            $query = $arguments['query'] ?? '';
            $section = $arguments['section'] ?? null;
            $docType = $arguments['doc_type'] ?? null;
            $limit = $arguments['limit'] ?? 10;

            if (empty($query)) {
                throw new \Exception("Query parameter is required");
            }

            $content = $this->searchByTopic($query, $section, $docType, $limit);

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
            
            // Show structured data if available
            if (!empty($result['parameters'])) {
                $output .= "\nParameters:\n";
                foreach ($result['parameters'] as $param) {
                    $optional = $param['optional'] ? ' (optional' : ' (required';
                    if (!empty($param['default_value'])) {
                        $optional .= ', default: ' . $param['default_value'];
                    }
                    $optional .= ')';
                    $output .= "  - {$param['name']}: {$param['type']}{$optional}\n";
                    if (!empty($param['description'])) {
                        $desc = $this->createExcerpt($param['description'], 100);
                        $output .= "    {$desc}\n";
                    }
                }
            }
            
            if (!empty($result['returns'])) {
                $output .= "\nReturns: {$result['returns']['type']}";
                if (!empty($result['returns']['description'])) {
                    $desc = $this->createExcerpt($result['returns']['description'], 100);
                    $output .= " - {$desc}";
                }
                $output .= "\n";
            }
            
            if (!empty($result['examples'])) {
                $exampleCount = count($result['examples']);
                $output .= "\nCode Examples: {$exampleCount} available\n";
                // Show first example title as preview
                if (!empty($result['examples'][0]['title'])) {
                    $output .= "  Example: {$result['examples'][0]['title']}\n";
                }
            }
            
            if (!empty($result['related'])) {
                $output .= "\nRelated:\n";
                foreach ($result['related'] as $category => $items) {
                    $itemList = implode(', ', array_slice($items, 0, 3));
                    if (count($items) > 3) {
                        $itemList .= ' (+' . (count($items) - 3) . ' more)';
                    }
                    $output .= "  {$category}: {$itemList}\n";
                }
            }
            
            // Show content excerpt only if no structured data
            if (empty($result['parameters']) && empty($result['examples']) && empty($result['related'])) {
                $excerpt = $this->createExcerpt($result['content'], 300);
                $output .= "\nContent: $excerpt\n";
            }
            
            $output .= "\n---\n\n";
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
     * Get detailed information about a specific function, option, or event
     */
    private function getFunctionDetails(string $name): string
    {
        // Search for exact title match first
        $stmt = $this->db->prepare("SELECT * FROM documentation WHERE title = ? LIMIT 1");
        $stmt->execute([$name]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // If no exact match, try case-insensitive
        if (!$doc) {
            $stmt = $this->db->prepare("SELECT * FROM documentation WHERE LOWER(title) = LOWER(?) LIMIT 1");
            $stmt->execute([$name]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        
        // If still no match, try searching title containing the name
        if (!$doc) {
            $stmt = $this->db->prepare("SELECT * FROM documentation WHERE title LIKE ? LIMIT 1");
            $stmt->execute(['%' . $name . '%']);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        
        if (!$doc) {
            return "No documentation found for: \"$name\"\n\nTry searching with search_datatables tool instead.";
        }
        
        // Enrich with structured data
        $enriched = $this->enrichWithStructuredData([$doc]);
        $doc = $enriched[0];
        
        // Format detailed output
        $output = "{$doc['title']}\n";
        $output .= str_repeat("=", strlen($doc['title'])) . "\n\n";
        
        $output .= "URL: {$doc['url']}\n";
        $output .= "Type: {$doc['doc_type']}";
        if (!empty($doc['section'])) {
            $output .= " | Section: {$doc['section']}";
        }
        $output .= "\n\n";
        
        // Parameters
        if (!empty($doc['parameters'])) {
            $output .= "Parameters:\n";
            foreach ($doc['parameters'] as $param) {
                $optional = $param['optional'] ? ' (optional' : ' (required';
                if (!empty($param['default_value'])) {
                    $optional .= ', default: ' . $param['default_value'];
                }
                $optional .= ')';
                $output .= "  {$param['name']}: {$param['type']}{$optional}\n";
                if (!empty($param['description'])) {
                    $output .= "    {$param['description']}\n";
                }
                $output .= "\n";
            }
        }
        
        // Return type
        if (!empty($doc['returns'])) {
            $output .= "Returns:\n";
            $output .= "  {$doc['returns']['type']}\n";
            if (!empty($doc['returns']['description'])) {
                $output .= "  {$doc['returns']['description']}\n";
            }
            $output .= "\n";
        }
        
        // Description
        if (!empty($doc['content'])) {
            $output .= "Description:\n";
            $output .= $this->createExcerpt($doc['content'], 500) . "\n\n";
        }
        
        // Code examples
        if (!empty($doc['examples'])) {
            $output .= "Code Examples (" . count($doc['examples']) . "):\n\n";
            foreach ($doc['examples'] as $i => $example) {
                $num = $i + 1;
                if (!empty($example['title'])) {
                    $output .= "Example {$num}: {$example['title']}\n";
                }
                $lang = !empty($example['language']) ? $example['language'] : 'javascript';
                $output .= "```{$lang}\n";
                $output .= $example['code'] . "\n";
                $output .= "```\n\n";
            }
        }
        
        // Related items
        if (!empty($doc['related'])) {
            $output .= "Related:\n";
            foreach ($doc['related'] as $category => $items) {
                $output .= "  {$category}:\n";
                foreach ($items as $item) {
                    $output .= "    - {$item}\n";
                }
            }
            $output .= "\n";
        }
        
        return $output;
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
     * Search code examples
     */
    private function searchByExample(string $query, ?string $language, int $limit): string
    {
        // Build SQL query
        $sql = "
            SELECT DISTINCT 
                d.id,
                d.title,
                d.url,
                d.section,
                ce.title as example_title,
                ce.code,
                ce.language
            FROM code_examples ce
            JOIN documentation d ON d.id = ce.doc_id
            WHERE ce.code LIKE :query
        ";
        
        if ($language !== null) {
            $sql .= " AND ce.language = :language";
        }
        
        $sql .= " ORDER BY d.title LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%', \PDO::PARAM_STR);
        
        if ($language !== null) {
            $stmt->bindValue(':language', $language, \PDO::PARAM_STR);
        }
        
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Format output
        if (empty($results)) {
            $langFilter = $language ? " (language: $language)" : "";
            return "No code examples found matching \"$query\"$langFilter";
        }
        
        $langFilter = $language ? " (filtered by language: $language)" : "";
        $output = "Found " . count($results) . " code examples matching \"$query\"$langFilter:\n\n";
        
        // Group by documentation page
        $grouped = [];
        foreach ($results as $result) {
            $docId = $result['id'];
            if (!isset($grouped[$docId])) {
                $grouped[$docId] = [
                    'title' => $result['title'],
                    'url' => $result['url'],
                    'section' => $result['section'],
                    'examples' => []
                ];
            }
            $grouped[$docId]['examples'][] = [
                'title' => $result['example_title'],
                'code' => $result['code'],
                'language' => $result['language']
            ];
        }
        
        $num = 1;
        foreach ($grouped as $doc) {
            $output .= "[$num] {$doc['title']}\n";
            $output .= "URL: {$doc['url']}\n";
            
            if (!empty($doc['section'])) {
                $output .= "Section: {$doc['section']}\n";
            }
            
            $output .= "\nMatching Examples (" . count($doc['examples']) . "):\n\n";
            
            foreach ($doc['examples'] as $i => $example) {
                $exampleNum = $i + 1;
                $output .= "  Example $exampleNum: {$example['title']}\n";
                $output .= "  ```{$example['language']}\n";
                $output .= "  " . str_replace("\n", "\n  ", trim($example['code'])) . "\n";
                $output .= "  ```\n\n";
            }
            
            $output .= "\n";
            $num++;
        }
        
        return $output;
    }
    
    /**
     * Search by topic/section
     */
    private function searchByTopic(string $query, ?string $section, ?string $docType, int $limit): string
    {
        // Build SQL with filters
        $sql = "
            SELECT d.title, d.url, d.content, d.section, d.doc_type, fts.rank
            FROM documentation_fts fts
            JOIN documentation d ON d.id = fts.rowid
            WHERE documentation_fts MATCH :query
        ";
        
        if ($section !== null) {
            $sql .= " AND d.section = :section";
        }
        
        if ($docType !== null) {
            $sql .= " AND d.doc_type = :doc_type";
        }
        
        $sql .= " ORDER BY rank LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':query', $query, \PDO::PARAM_STR);
        
        if ($section !== null) {
            $stmt->bindValue(':section', $section, \PDO::PARAM_STR);
        }
        
        if ($docType !== null) {
            $stmt->bindValue(':doc_type', $docType, \PDO::PARAM_STR);
        }
        
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Enrich and format
        $enriched = $this->enrichWithStructuredData($results);
        
        // Custom header with filters
        $filters = [];
        if ($section) $filters[] = "section: $section";
        if ($docType) $filters[] = "type: $docType";
        $filterText = !empty($filters) ? " (" . implode(", ", $filters) . ")" : "";
        
        if (empty($enriched)) {
            return "No results found for \"$query\"$filterText";
        }
        
        $output = "Found " . count($enriched) . " results for \"$query\"$filterText:\n\n";
        $output .= $this->formatSearchResults($enriched, $query);
        
        return $output;
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
