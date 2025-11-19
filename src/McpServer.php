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
                ],
                [
                    'name' => 'get_related_items',
                    'description' => 'Find related functions, options, and events for a given DataTables item. Useful for discovering complementary features and understanding connections between different parts of the API.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Name of the function, option, or event to find relationships for (e.g., "ajax.reload", "columns", "draw")'
                            ],
                            'category' => [
                                'type' => 'string',
                                'description' => 'Optional: filter by relationship category',
                                'enum' => ['API', 'Options', 'Events']
                            ]
                        ],
                        'required' => ['name']
                    ]
                ],
                [
                    'name' => 'analyze_error',
                    'description' => 'Analyze DataTables error messages and warnings. Provides explanations, common causes, and solutions based on official documentation. Covers warnings like "Cannot reinitialise DataTable", "Invalid JSON response", "Ajax error", and more.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'error_message' => [
                                'type' => 'string',
                                'description' => 'The error or warning message from DataTables (e.g., "DataTables warning: table id=example - Invalid JSON response", "Cannot reinitialise DataTable")'
                            ]
                        ],
                        'required' => ['error_message']
                    ]
                ],
                [
                    'name' => 'validate_config',
                    'description' => 'Validate a DataTables configuration object. Checks option names, detects common mistakes, and suggests corrections. Helps catch typos and invalid configurations before runtime.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'config' => [
                                'type' => 'string',
                                'description' => 'DataTables configuration as JSON string (e.g., \'{"ajax": "data.json", "paging": true, "searching": false}\')'
                            ]
                        ],
                        'required' => ['config']
                    ]
                ],
                [
                    'name' => 'generate_code',
                    'description' => 'Generate working DataTables initialization code based on requirements. Creates complete, ready-to-use JavaScript code with proper configuration, HTML table structure, and includes relevant examples from documentation.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'requirements' => [
                                'type' => 'string',
                                'description' => 'Description of what you need (e.g., "server-side processing with ajax", "sortable columns with search", "responsive table with export buttons")'
                            ],
                            'table_id' => [
                                'type' => 'string',
                                'description' => 'HTML table ID (default: "example")',
                                'default' => 'example'
                            ]
                        ],
                        'required' => ['requirements']
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
        
        if ($toolName === 'get_related_items') {
            $name = $arguments['name'] ?? '';
            $category = $arguments['category'] ?? null;

            if (empty($name)) {
                throw new \Exception("Name parameter is required");
            }

            $content = $this->getRelatedItems($name, $category);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $content
                    ]
                ]
            ];
        }
        
        if ($toolName === 'analyze_error') {
            $errorMessage = $arguments['error_message'] ?? '';

            if (empty($errorMessage)) {
                throw new \Exception("error_message parameter is required");
            }

            $content = $this->analyzeError($errorMessage);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $content
                    ]
                ]
            ];
        }
        
        if ($toolName === 'validate_config') {
            $config = $arguments['config'] ?? '';

            if (empty($config)) {
                throw new \Exception("config parameter is required");
            }

            $content = $this->validateConfig($config);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $content
                    ]
                ]
            ];
        }
        
        if ($toolName === 'generate_code') {
            $requirements = $arguments['requirements'] ?? '';
            $tableId = $arguments['table_id'] ?? 'example';
            
            if (empty($requirements)) {
                throw new \Exception("requirements parameter is required");
            }
            
            $content = $this->generateCode($requirements, $tableId);
            
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
     * Get related items for a function, option, or event
     */
    private function getRelatedItems(string $name, ?string $category = null): string
    {
        $db = $this->searchEngine->getDb();
        
        // First, find the documentation item by exact or partial name match
        $sql = "
            SELECT id, title, url, section, doc_type
            FROM documentation
            WHERE title = :name
            OR title LIKE :name_wildcard
            ORDER BY 
                CASE 
                    WHEN title = :name THEN 0
                    WHEN title LIKE :name THEN 1
                    ELSE 2
                END
            LIMIT 1
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':name', $name, \PDO::PARAM_STR);
        $stmt->bindValue(':name_wildcard', "%$name%", \PDO::PARAM_STR);
        $stmt->execute();
        
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$doc) {
            return "No documentation found for \"$name\". Try searching with search_datatables first.";
        }
        
        // Get related items
        $sql = "
            SELECT related_doc_title, category
            FROM related_items
            WHERE doc_id = :doc_id
        ";
        
        if ($category !== null) {
            $sql .= " AND category = :category";
        }
        
        $sql .= " ORDER BY category, related_doc_title";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':doc_id', $doc['id'], \PDO::PARAM_INT);
        
        if ($category !== null) {
            $stmt->bindValue(':category', $category, \PDO::PARAM_STR);
        }
        
        $stmt->execute();
        $related = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Format output
        $categoryFilter = $category ? " (category: $category)" : "";
        $output = "Related items for \"{$doc['title']}\"$categoryFilter:\n";
        $output .= "URL: {$doc['url']}\n";
        $output .= "Type: {$doc['doc_type']} | Section: {$doc['section']}\n\n";
        
        if (empty($related)) {
            $output .= "No related items found.";
            return $output;
        }
        
        // Group by category
        $grouped = [];
        foreach ($related as $item) {
            $cat = $item['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $item['related_doc_title'];
        }
        
        $output .= "Found " . count($related) . " related items:\n\n";
        
        foreach ($grouped as $cat => $items) {
            $output .= "### $cat\n";
            foreach ($items as $item) {
                $output .= "  - $item\n";
            }
            $output .= "\n";
        }
        
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
     * Analyze error messages and provide solutions
     */
    private function analyzeError(string $errorMessage): string
    {
        $db = $this->searchEngine->getDb();
        
        // Extract error patterns
        $patterns = [
            'Invalid JSON response' => '1',
            'Non-table node initialisation' => '2',
            'Cannot reinitialise DataTable' => '3',
            'Requested unknown parameter' => '4',
            'Unknown paging action' => '5',
            'Possible column misalignment' => '6',
            'Ajax error' => '7',
            'Unable to automatically determine field' => '11',
            'system error has occurred' => '12',
            'Unable to find row identifier' => '14',
            'DateTime library is required' => '15',
            'Field is still processing' => '16',
            'Formatted date without' => '17',
            'Incorrect column count' => '18',
            'DataTables library not set' => ['19', '23'],
            'i18n file loading error' => '21'
        ];
        
        // Find matching tech note
        $techNoteNumber = null;
        foreach ($patterns as $pattern => $number) {
            if (stripos($errorMessage, $pattern) !== false) {
                $techNoteNumber = is_array($number) ? $number[0] : $number;
                break;
            }
        }
        
        $output = "Error Analysis\n";
        $output .= str_repeat('=', 50) . "\n\n";
        $output .= "Error Message:\n\"$errorMessage\"\n\n";
        
        if ($techNoteNumber) {
            // Get specific tech note
            $stmt = $db->prepare("
                SELECT title, url, content 
                FROM documentation 
                WHERE section LIKE 'Tech notes%' AND title LIKE :pattern
                LIMIT 1
            ");
            $stmt->execute([':pattern' => "$techNoteNumber.%"]);
            $techNote = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($techNote) {
                $output .= "Identified Issue:\n{$techNote['title']}\n\n";
                $output .= "Documentation: {$techNote['url']}\n\n";
                
                // Extract key information from content
                $content = strip_tags($techNote['content']);
                $content = preg_replace('/\s+/', ' ', $content);
                $excerpt = substr($content, 0, 800);
                
                $output .= "Explanation:\n$excerpt...\n\n";
                $output .= "For complete details and solutions, see: {$techNote['url']}\n";
            }
        } else {
            // General search for error terms
            $searchTerms = preg_replace('/[^a-z0-9 ]/i', ' ', $errorMessage);
            $searchTerms = trim(preg_replace('/\s+/', ' ', $searchTerms));
            
            try {
                $results = $this->searchEngine->search($searchTerms, 3);
                
                if (!empty($results)) {
                    $output .= "Related Documentation:\n\n";
                    foreach ($results as $i => $result) {
                        $num = $i + 1;
                        $output .= "[$num] {$result['title']}\n";
                        $output .= "    {$result['url']}\n";
                        if (!empty($result['section'])) {
                            $output .= "    Section: {$result['section']}\n";
                        }
                        $output .= "\n";
                    }
                } else {
                    $output .= "No specific documentation found for this error.\n\n";
                    $output .= "General troubleshooting steps:\n";
                    $output .= "1. Check the browser console for additional details\n";
                    $output .= "2. Verify DataTables is properly initialized\n";
                    $output .= "3. Check for JavaScript errors before DataTables loads\n";
                    $output .= "4. Review the DataTables debugging guide: https://datatables.net/manual/tech-notes/10\n";
                }
            } catch (\Exception $e) {
                $output .= "Unable to search documentation. Please check: https://datatables.net/manual/tech-notes/\n";
            }
        }
        
        return $output;
    }
    
    /**
     * Validate DataTables configuration
     */
    private function validateConfig(string $configJson): string
    {
        $db = $this->searchEngine->getDb();
        
        // Parse JSON
        try {
            $config = json_decode($configJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return "Invalid JSON: {$e->getMessage()}\n\nPlease provide valid JSON configuration.";
        }
        
        if (!is_array($config)) {
            return "Configuration must be a JSON object.";
        }
        
        // Get all valid option names from database
        $stmt = $db->query("
            SELECT DISTINCT title 
            FROM documentation 
            WHERE section = 'Options' AND doc_type = 'reference'
        ");
        $validOptions = array_map(function($row) {
            return $row['title'];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        
        $output = "Configuration Validation\n";
        $output .= str_repeat('=', 50) . "\n\n";
        
        $issues = [];
        $warnings = [];
        $valid = [];
        
        foreach ($config as $key => $value) {
            // Check if option exists (exact match)
            if (in_array($key, $validOptions)) {
                $valid[] = $key;
                continue;
            }
            
            // Check for close matches (typos)
            $closeMatches = [];
            foreach ($validOptions as $validOption) {
                $distance = levenshtein(strtolower($key), strtolower($validOption));
                if ($distance <= 2) {
                    $closeMatches[] = $validOption;
                }
            }
            
            if (!empty($closeMatches)) {
                $issues[] = [
                    'option' => $key,
                    'type' => 'typo',
                    'suggestions' => $closeMatches
                ];
            } else {
                // Check if it's a nested option (e.g., ajax.url)
                if (strpos($key, '.') !== false) {
                    $baseOption = substr($key, 0, strpos($key, '.'));
                    if (in_array($baseOption, $validOptions)) {
                        $warnings[] = [
                            'option' => $key,
                            'type' => 'nested',
                            'message' => "Nested option - verify sub-property '$key' is valid for '$baseOption'"
                        ];
                    } else {
                        $issues[] = [
                            'option' => $key,
                            'type' => 'unknown',
                            'suggestions' => []
                        ];
                    }
                } else {
                    $issues[] = [
                        'option' => $key,
                        'type' => 'unknown',
                        'suggestions' => []
                    ];
                }
            }
        }
        
        // Report results
        if (empty($issues) && empty($warnings)) {
            $output .= "✓ All options are valid!\n\n";
            $output .= "Valid options (" . count($valid) . "):\n";
            foreach ($valid as $opt) {
                $output .= "  - $opt\n";
            }
        } else {
            if (!empty($issues)) {
                $output .= "Issues Found:\n\n";
                foreach ($issues as $issue) {
                    if ($issue['type'] === 'typo') {
                        $output .= "✗ '{$issue['option']}' - Possible typo\n";
                        $output .= "  Did you mean: " . implode(', ', $issue['suggestions']) . "?\n\n";
                    } else {
                        $output .= "✗ '{$issue['option']}' - Unknown option\n";
                        $output .= "  This option is not recognized in DataTables documentation.\n\n";
                    }
                }
            }
            
            if (!empty($warnings)) {
                $output .= "Warnings:\n\n";
                foreach ($warnings as $warning) {
                    $output .= "⚠ '{$warning['option']}' - {$warning['message']}\n\n";
                }
            }
            
            if (!empty($valid)) {
                $output .= "Valid options (" . count($valid) . "):\n";
                foreach ($valid as $opt) {
                    $output .= "  ✓ $opt\n";
                }
                $output .= "\n";
            }
        }
        
        $output .= "\nFor detailed option documentation, use get_function_details with the option name.\n";
        
        return $output;
    }
    
    /**
     * Generate working DataTables code from requirements
     */
    private function generateCode(string $requirements, string $tableId): string
    {
        $db = $this->searchEngine->getDb();
        
        // Parse requirements for keywords
        $keywords = $this->extractKeywords($requirements);
        
        // Search for relevant examples
        $examples = $this->findRelevantExamples($keywords, $db);
        
        // Build configuration
        $config = $this->buildConfigFromKeywords($keywords, $examples);
        
        // Generate output
        $output = "DataTables Code Generator\n";
        $output .= str_repeat('=', 50) . "\n\n";
        $output .= "Requirements: $requirements\n\n";
        
        if (!empty($keywords)) {
            $output .= "Detected features: " . implode(', ', $keywords) . "\n\n";
        }
        
        $output .= "HTML:\n```html\n";
        $output .= $this->generateHtmlTable($tableId);
        $output .= "```\n\n";
        
        $output .= "JavaScript:\n```javascript\n";
        $output .= $this->generateJavaScript($tableId, $config, $keywords);
        $output .= "```\n\n";
        
        $output .= "Documentation:\n";
        $output .= $this->generateDocLinks($keywords, $examples, $db);
        
        return $output;
    }
    
    /**
     * Extract keywords from requirements string
     */
    private function extractKeywords(string $requirements): array
    {
        $keywords = [];
        $req = strtolower($requirements);
        
        // Feature detection
        $featureMap = [
            'ajax' => ['ajax', 'remote', 'dynamic'],
            'serverSide' => ['server-side', 'server side', 'serverside'],
            'responsive' => ['responsive', 'mobile'],
            'buttons' => ['button', 'export', 'csv', 'pdf', 'print', 'excel'],
            'searching' => ['search', 'filter'],
            'paging' => ['paging', 'pagination', 'page'],
            'ordering' => ['sort', 'order', 'sortable'],
            'scrollX' => ['scroll', 'horizontal'],
            'select' => ['select', 'checkbox', 'row selection'],
            'fixedHeader' => ['fixed header', 'sticky header'],
            'colReorder' => ['reorder', 'drag column'],
            'rowGroup' => ['group', 'grouping'],
            'stateSave' => ['state', 'remember', 'persist']
        ];
        
        foreach ($featureMap as $feature => $patterns) {
            foreach ($patterns as $pattern) {
                if (stripos($req, $pattern) !== false) {
                    $keywords[] = $feature;
                    break;
                }
            }
        }
        
        return array_unique($keywords);
    }
    
    /**
     * Find relevant examples from database
     */
    private function findRelevantExamples(array $keywords, \PDO $db): array
    {
        if (empty($keywords)) {
            return [];
        }
        
        // Build search query for examples
        $searchTerms = implode(' OR ', $keywords);
        
        $stmt = $db->prepare("
            SELECT ce.*, d.title, d.url
            FROM code_examples ce
            JOIN documentation d ON ce.doc_id = d.id
            WHERE ce.language = 'javascript'
            AND ce.code LIKE :search
            LIMIT 5
        ");
        
        $search = '%' . $keywords[0] . '%';
        $stmt->execute([':search' => $search]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Build configuration object from keywords
     */
    private function buildConfigFromKeywords(array $keywords, array $examples): array
    {
        $config = [];
        
        foreach ($keywords as $keyword) {
            switch ($keyword) {
                case 'ajax':
                    $config['ajax'] = 'data.json';
                    $config['processing'] = true;
                    break;
                    
                case 'serverSide':
                    $config['serverSide'] = true;
                    $config['ajax'] = [
                        'url' => 'server_processing.php',
                        'type' => 'POST'
                    ];
                    $config['processing'] = true;
                    break;
                    
                case 'responsive':
                    $config['responsive'] = true;
                    break;
                    
                case 'buttons':
                    $config['dom'] = 'Bfrtip';
                    $config['buttons'] = ['copy', 'csv', 'excel', 'pdf', 'print'];
                    break;
                    
                case 'searching':
                    $config['searching'] = true;
                    break;
                    
                case 'paging':
                    $config['paging'] = true;
                    $config['pageLength'] = 10;
                    break;
                    
                case 'ordering':
                    $config['ordering'] = true;
                    break;
                    
                case 'scrollX':
                    $config['scrollX'] = true;
                    break;
                    
                case 'select':
                    $config['select'] = true;
                    break;
                    
                case 'fixedHeader':
                    $config['fixedHeader'] = true;
                    break;
                    
                case 'colReorder':
                    $config['colReorder'] = true;
                    break;
                    
                case 'rowGroup':
                    $config['rowGroup'] = ['dataSrc' => 0];
                    break;
                    
                case 'stateSave':
                    $config['stateSave'] = true;
                    break;
            }
        }
        
        return $config;
    }
    
    /**
     * Generate HTML table structure
     */
    private function generateHtmlTable(string $tableId): string
    {
        $html = "<table id=\"$tableId\" class=\"display\" style=\"width:100%\">\n";
        $html .= "  <thead>\n";
        $html .= "    <tr>\n";
        $html .= "      <th>Name</th>\n";
        $html .= "      <th>Position</th>\n";
        $html .= "      <th>Office</th>\n";
        $html .= "      <th>Age</th>\n";
        $html .= "      <th>Start date</th>\n";
        $html .= "      <th>Salary</th>\n";
        $html .= "    </tr>\n";
        $html .= "  </thead>\n";
        $html .= "  <tbody>\n";
        $html .= "    <!-- Data rows here -->\n";
        $html .= "  </tbody>\n";
        $html .= "</table>\n";
        
        return $html;
    }
    
    /**
     * Generate JavaScript initialization code
     */
    private function generateJavaScript(string $tableId, array $config, array $keywords): string
    {
        $js = "$(document).ready(function() {\n";
        $js .= "  $('#$tableId').DataTable({\n";
        
        if (empty($config)) {
            $js .= "    // Basic initialization with default settings\n";
        } else {
            $indent = "    ";
            $items = [];
            
            foreach ($config as $key => $value) {
                $comment = $this->getOptionComment($key, $keywords);
                if ($comment) {
                    $items[] = "$indent// $comment";
                }
                
                if (is_array($value)) {
                    $items[] = "$indent$key: " . json_encode($value, JSON_UNESCAPED_SLASHES);
                } elseif (is_bool($value)) {
                    $items[] = "$indent$key: " . ($value ? 'true' : 'false');
                } elseif (is_string($value)) {
                    $items[] = "$indent$key: '$value'";
                } else {
                    $items[] = "$indent$key: $value";
                }
            }
            
            $js .= implode(",\n", $items) . "\n";
        }
        
        $js .= "  });\n";
        $js .= "});\n";
        
        return $js;
    }
    
    /**
     * Get helpful comment for an option
     */
    private function getOptionComment(string $option, array $keywords): ?string
    {
        $comments = [
            'ajax' => 'Load data from remote source',
            'serverSide' => 'Enable server-side processing for large datasets',
            'processing' => 'Show processing indicator during Ajax requests',
            'responsive' => 'Automatically adapt table layout for smaller screens',
            'dom' => 'Define table control elements layout (B=Buttons, f=Filter, r=pRocessing, t=Table, i=Info, p=Pagination)',
            'buttons' => 'Export and print buttons',
            'searching' => 'Enable search/filter functionality',
            'paging' => 'Enable pagination',
            'pageLength' => 'Number of rows per page',
            'ordering' => 'Enable column sorting',
            'scrollX' => 'Enable horizontal scrolling',
            'select' => 'Enable row selection',
            'fixedHeader' => 'Keep header visible when scrolling',
            'colReorder' => 'Allow columns to be reordered by drag and drop',
            'rowGroup' => 'Group rows by column data',
            'stateSave' => 'Save table state (pagination, search, etc.) in localStorage'
        ];
        
        return $comments[$option] ?? null;
    }
    
    /**
     * Generate documentation links
     */
    private function generateDocLinks(array $keywords, array $examples, \PDO $db): string
    {
        $output = "";
        
        // Add links for detected features
        if (!empty($keywords)) {
            $output .= "Feature documentation:\n";
            
            foreach ($keywords as $keyword) {
                // Search for option documentation
                $stmt = $db->prepare("
                    SELECT title, url
                    FROM documentation
                    WHERE section = 'Options'
                    AND title = :keyword
                    LIMIT 1
                ");
                
                $stmt->execute([':keyword' => $keyword]);
                $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($doc) {
                    $output .= "  - {$doc['title']}: {$doc['url']}\n";
                }
            }
            
            $output .= "\n";
        }
        
        // Add example links
        if (!empty($examples)) {
            $output .= "Related examples:\n";
            
            foreach (array_slice($examples, 0, 3) as $example) {
                $output .= "  - {$example['title']}: {$example['url']}\n";
            }
        }
        
        if (empty($keywords) && empty($examples)) {
            $output .= "  - Getting started: https://datatables.net/manual/\n";
            $output .= "  - Basic initialization: https://datatables.net/examples/basic_init/\n";
        }
        
        return $output;
    }
    
    /**
     * Log to stderr (doesn't interfere with stdio protocol)
     */
    private function log(string $message): void
    {
        fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] $message\n");
    }
}
