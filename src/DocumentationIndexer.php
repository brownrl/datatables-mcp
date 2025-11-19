<?php

namespace DataTablesMcp;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scrapes and indexes DataTables.net documentation
 * 
 * Process:
 * 1. Fetch manual index page to get all manual sections
 * 2. Fetch each manual section's full content
 * 3. Fetch examples index to get all examples
 * 4. Fetch each example's full content
 * 5. Store everything in SQLite with FTS5 for searching
 */
class DocumentationIndexer
{
    private Client $client;
    private \PDO $db;
    private string $baseUrl = 'https://datatables.net';

    public function __construct(string $dbPath)
    {
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'DataTablesMCP/1.0 Documentation Indexer'
            ]
        ]);

        $this->initializeDatabase($dbPath);
    }

    /**
     * Initialize SQLite database with FTS5
     */
    private function initializeDatabase(string $dbPath): void
    {
        // Create data directory if needed
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new \PDO("sqlite:$dbPath");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create main documentation table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS documentation (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                url TEXT UNIQUE NOT NULL,
                content TEXT NOT NULL,
                section TEXT,
                doc_type TEXT NOT NULL,
                indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create FTS5 virtual table for full-text search
        // FTS5 is SQLite's powerful full-text search extension
        $this->db->exec("
            CREATE VIRTUAL TABLE IF NOT EXISTS documentation_fts USING fts5(
                title,
                url,
                content,
                section,
                doc_type,
                content='documentation',
                content_rowid='id'
            )
        ");

        // Create triggers to keep FTS5 in sync with main table
        $this->db->exec("
            CREATE TRIGGER IF NOT EXISTS documentation_ai AFTER INSERT ON documentation BEGIN
                INSERT INTO documentation_fts(rowid, title, url, content, section, doc_type)
                VALUES (new.id, new.title, new.url, new.content, new.section, new.doc_type);
            END;
        ");

        $this->db->exec("
            CREATE TRIGGER IF NOT EXISTS documentation_ad AFTER DELETE ON documentation BEGIN
                DELETE FROM documentation_fts WHERE rowid = old.id;
            END;
        ");

        $this->db->exec("
            CREATE TRIGGER IF NOT EXISTS documentation_au AFTER UPDATE ON documentation BEGIN
                UPDATE documentation_fts 
                SET title = new.title, url = new.url, content = new.content, 
                    section = new.section, doc_type = new.doc_type
                WHERE rowid = new.id;
            END;
        ");

        echo "Database initialized at $dbPath\n";
    }

    /**
     * Main indexing function - indexes everything
     */
    public function indexAll(): void
    {
        echo "Starting DataTables documentation indexing...\n\n";

        // Don't clear - keep existing data
        echo "Indexing only new/missing content...\n\n";

        // Check what's already indexed
        $stmt = $this->db->query("SELECT doc_type, COUNT(*) as count FROM documentation GROUP BY doc_type");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            echo "  Already indexed: {$row['doc_type']} ({$row['count']} documents)\n";
        }
        echo "\n";

        // Index missing content
        $this->indexExamples();  // Will skip already-indexed examples
        $this->indexReference();  // Will skip already-indexed reference pages

        // Show statistics
        $this->showStats();
    }

    /**
     * Index all manual sections and their subsections
     */
    private function indexManual(): void
    {
        echo "Indexing manual sections...\n";

        $manualSections = [
            'installation' => 'Installation',
            'data' => 'Data',
            'ajax' => 'Ajax',
            'options' => 'Options',
            'api' => 'API',
            'search' => 'Search',
            'styling' => 'Styling',
            'events' => 'Events',
            'server-side' => 'Server-side processing',
            'i18n' => 'Internationalisation',
            'security' => 'Security',
            'react' => 'React',
            'vue' => 'Vue',
            'plug-ins' => 'Plug-in development',
            'tech-notes' => 'Technical notes',
            'development' => 'Development',
            'case-study' => 'Case Study'
        ];

        // First pass: discover all subsections
        $allPages = [];
        foreach ($manualSections as $slug => $section) {
            $allPages[] = [
                'url' => "{$this->baseUrl}/manual/$slug",
                'section' => $section,
                'subsection' => null
            ];
            
            try {
                echo "  Discovering subsections in: $section...\n";
                $url = "{$this->baseUrl}/manual/$slug";
                $html = $this->fetchUrl($url);
                
                $subsections = $this->extractManualSubsections($html, $slug);
                foreach ($subsections as $subsection) {
                    $allPages[] = $subsection;
                }
                
                if (count($subsections) > 0) {
                    echo "    Found " . count($subsections) . " subsections\n";
                }
                
                usleep(500000); // 0.5 seconds between discovery
            } catch (\Exception $e) {
                echo "    Error discovering subsections: " . $e->getMessage() . "\n";
            }
        }

        echo "\n  Total manual pages discovered: " . count($allPages) . "\n\n";

        // Second pass: scrape all pages
        $total = count($allPages);
        $current = 0;

        foreach ($allPages as $page) {
            try {
                $current++;
                $displayName = $page['subsection'] 
                    ? "{$page['section']} - {$page['subsection']}" 
                    : $page['section'];
                
                echo "  [{$current}/{$total}] Fetching $displayName...\n";
                
                $html = $this->fetchUrl($page['url']);
                $this->parseAndStoreManualPage(
                    $html, 
                    $page['url'], 
                    $page['section'],
                    $page['subsection']
                );
                
                // Be polite to the server
                usleep(1500000); // 1.5 seconds
            } catch (\Exception $e) {
                echo "    Error indexing $displayName: " . $e->getMessage() . "\n";
            }
        }

        echo "Manual indexing complete\n\n";
    }

    /**
     * Index all examples - discovers and indexes individual example pages
     */
    private function indexExamples(): void
    {
        echo "Indexing examples...\n";

        // Example categories to scrape
        $categories = [
            'basic_init' => 'Basic initialisation',
            'advanced_init' => 'Advanced initialisation',
            'data_sources' => 'Data sources',
            'i18n' => 'Internationalisation',
            'datetime' => 'DateTime',
            'plug-ins' => 'Plug-ins',
            'styling' => 'Styling',
            'layout' => 'Layout',
            'api' => 'API',
            'ajax' => 'Ajax',
            'server_side' => 'Server-side'
        ];

        $totalExamples = 0;
        $processedExamples = 0;

        // First pass: discover all individual examples
        $allExamples = [];
        foreach ($categories as $categorySlug => $categoryName) {
            try {
                echo "  Discovering examples in: $categoryName...\n";
                $categoryUrl = "{$this->baseUrl}/examples/$categorySlug";
                $html = $this->fetchUrl($categoryUrl);
                
                $examples = $this->extractIndividualExamples($html, $categorySlug, $categoryName);
                $allExamples = array_merge($allExamples, $examples);
                echo "    Found " . count($examples) . " examples\n";
                
                usleep(1000000); // 1 second between category pages
            } catch (\Exception $e) {
                echo "    Error discovering $categoryName: " . $e->getMessage() . "\n";
            }
        }

        $totalExamples = count($allExamples);
        echo "\n  Total examples discovered: $totalExamples\n\n";

        // Second pass: scrape each individual example
        $startTime = time();
        foreach ($allExamples as $example) {
            try {
                // Skip if already indexed
                if ($this->isUrlIndexed($example['url'])) {
                    echo "  [SKIP] {$example['category']} - {$example['title']} (already indexed)\n";
                    continue;
                }
                
                $processedExamples++;
                $elapsed = time() - $startTime;
                $avgTime = $processedExamples > 0 ? $elapsed / $processedExamples : 0;
                $remaining = ($totalExamples - $processedExamples) * $avgTime;
                
                echo sprintf(
                    "  [%d/%d] %s - %s (ETA: %ds)\n",
                    $processedExamples,
                    $totalExamples,
                    $example['category'],
                    $example['title'],
                    (int)$remaining
                );
                
                $exampleHtml = $this->fetchUrl($example['url']);
                $this->parseAndStoreExample(
                    $exampleHtml,
                    $example['url'],
                    $example['title'],
                    $example['category']
                );
                
                // Be polite to the server
                usleep(1500000); // 1.5 seconds
            } catch (\Exception $e) {
                echo "    Error: " . $e->getMessage() . "\n";
            }
        }

        echo "Examples indexing complete\n\n";
    }

    /**
     * Index all reference documentation (options, API, events, etc.)
     */
    private function indexReference(): void
    {
        echo "Indexing reference documentation...\n";

        // Reference categories from datatables.net/reference
        $categories = [
            'option' => 'Options',
            'api' => 'API',
            'event' => 'Events',
            'button' => 'Buttons',
            'feature' => 'Features',
            'type' => 'Types',
            'content' => 'Content'
        ];

        $totalPages = 0;
        $processedPages = 0;

        // First pass: discover all reference pages
        $allPages = [];
        foreach ($categories as $categorySlug => $categoryName) {
            try {
                echo "  Discovering pages in: $categoryName...\n";
                $categoryUrl = "{$this->baseUrl}/reference/$categorySlug";
                $html = $this->fetchUrl($categoryUrl);
                
                $pages = $this->extractReferencePages($html, $categorySlug, $categoryName);
                $allPages = array_merge($allPages, $pages);
                echo "    Found " . count($pages) . " pages\n";
                
                usleep(1000000); // 1 second between category pages
            } catch (\Exception $e) {
                echo "    Error discovering $categoryName: " . $e->getMessage() . "\n";
            }
        }

        $totalPages = count($allPages);
        echo "\n  Total reference pages discovered: $totalPages\n\n";

        // Second pass: scrape each reference page
        $startTime = time();
        foreach ($allPages as $page) {
            try {
                // Skip if already indexed
                if ($this->isUrlIndexed($page['url'])) {
                    echo "  [SKIP] {$page['category']} - {$page['title']} (already indexed)\n";
                    continue;
                }
                
                $processedPages++;
                $elapsed = time() - $startTime;
                $avgTime = $processedPages > 0 ? $elapsed / $processedPages : 0;
                $remaining = ($totalPages - $processedPages) * $avgTime;
                
                echo sprintf(
                    "  [%d/%d] %s - %s (ETA: %ds)\n",
                    $processedPages,
                    $totalPages,
                    $page['category'],
                    $page['title'],
                    (int)$remaining
                );
                
                $pageHtml = $this->fetchUrl($page['url']);
                $this->parseAndStoreReferencePage(
                    $pageHtml,
                    $page['url'],
                    $page['title'],
                    $page['category']
                );
                
                // Be polite to the server
                usleep(1500000); // 1.5 seconds
            } catch (\Exception $e) {
                echo "    Error: " . $e->getMessage() . "\n";
            }
        }

        echo "Reference indexing complete\n\n";
    }

    /**
     * Extract reference page URLs from a category page
     */
    private function extractReferencePages(string $html, string $categorySlug, string $categoryName): array
    {
        $crawler = new Crawler($html);
        $pages = [];

        // Reference pages follow patterns like:
        // //datatables.net/reference/option/ajax
        // //datatables.net/reference/api/ajax()
        // //datatables.net/reference/event/draw
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$pages, $categorySlug, $categoryName) {
            $href = $node->attr('href');
            
            // Match reference pages in this category
            // Handle both protocol-relative URLs (//datatables.net/...) and absolute paths (/reference/...)
            if (preg_match("#^(?://datatables\.net)?/reference/$categorySlug/([^/\#\?]+)#", $href, $matches)) {
                $title = trim($node->text());
                
                // Convert protocol-relative URLs to https
                if (str_starts_with($href, '//')) {
                    $fullUrl = "https:" . $href;
                } elseif (str_starts_with($href, '/')) {
                    $fullUrl = "{$this->baseUrl}{$href}";
                } else {
                    $fullUrl = $href;
                }
                
                // Avoid duplicates and skip if no title
                $key = md5($fullUrl);
                if (!isset($pages[$key]) && !empty($title)) {
                    $pages[$key] = [
                        'url' => $fullUrl,
                        'title' => $title,
                        'category' => $categoryName
                    ];
                }
            }
        });

        return array_values($pages);
    }

    /**
     * Parse and store a reference page
     */
    private function parseAndStoreReferencePage(string $html, string $url, string $title, string $category): void
    {
        $crawler = new Crawler($html);

        // Extract description/content
        $content = $crawler->filter('.doc-content, article, .reference-content, main');
        
        if ($content->count() === 0) {
            $content = $crawler->filter('body');
        }

        $contentText = $content->count() > 0 ? $this->extractText($content) : '';

        // Also extract any code examples
        $codeBlocks = $crawler->filter('pre code, .code-example');
        $codeText = '';
        
        $codeBlocks->each(function (Crawler $node) use (&$codeText) {
            $codeText .= "\n\nCode example:\n" . trim($node->text()) . "\n";
        });

        $fullContent = $contentText . $codeText;

        if (empty($fullContent)) {
            echo "      Warning: No content extracted\n";
            return;
        }

        $this->storeDocument($title, $url, $fullContent, $category, 'reference');
    }

    /**
     * Index all DataTables extensions
     */
    private function indexExtensions(): void
    {
        echo "Indexing extensions...\n";

        $extensions = [
            'autofill' => 'AutoFill',
            'buttons' => 'Buttons',
            'colreorder' => 'ColReorder',
            'fixedcolumns' => 'FixedColumns',
            'fixedheader' => 'FixedHeader',
            'keytable' => 'KeyTable',
            'responsive' => 'Responsive',
            'rowgroup' => 'RowGroup',
            'rowreorder' => 'RowReorder',
            'scroller' => 'Scroller',
            'searchbuilder' => 'SearchBuilder',
            'searchpanes' => 'SearchPanes',
            'select' => 'Select',
            'staterestore' => 'StateRestore'
        ];

        // First pass: discover all extension pages
        $allPages = [];
        foreach ($extensions as $slug => $extensionName) {
            try {
                echo "  Discovering pages in: $extensionName...\n";
                $extensionUrl = "{$this->baseUrl}/extensions/$slug";
                $html = $this->fetchUrl($extensionUrl);
                
                // Add the main extension page
                $allPages[] = [
                    'url' => $extensionUrl,
                    'extension' => $extensionName,
                    'subsection' => null
                ];
                
                // Look for subsections (examples, manual pages, etc.)
                $subsections = $this->extractExtensionSubsections($html, $slug, $extensionName);
                $allPages = array_merge($allPages, $subsections);
                
                echo "    Found " . (count($subsections) + 1) . " pages (1 main + " . count($subsections) . " subsections)\n";
                
                usleep(500000); // 0.5 seconds between discovery
            } catch (\Exception $e) {
                echo "    Error discovering $extensionName: " . $e->getMessage() . "\n";
            }
        }

        echo "\n  Total extension pages discovered: " . count($allPages) . "\n\n";

        // Second pass: scrape all pages
        $total = count($allPages);
        $current = 0;

        foreach ($allPages as $page) {
            try {
                $current++;
                $displayName = $page['subsection'] 
                    ? "{$page['extension']} - {$page['subsection']}" 
                    : $page['extension'];
                
                // Skip if already indexed
                if ($this->isUrlIndexed($page['url'])) {
                    echo "  [{$current}/{$total}] Skipping $displayName (already indexed)\n";
                    continue;
                }
                
                echo "  [{$current}/{$total}] Fetching $displayName...\n";
                
                $html = $this->fetchUrl($page['url']);
                $this->parseAndStoreExtensionPage(
                    $html, 
                    $page['url'], 
                    $page['extension'],
                    $page['subsection']
                );
                
                // Be polite to the server
                usleep(1500000); // 1.5 seconds
            } catch (\Exception $e) {
                echo "    Error indexing $displayName: " . $e->getMessage() . "\n";
            }
        }

        echo "Extensions indexing complete\n\n";
    }

    /**
     * Extract extension subsection URLs
     */
    private function extractExtensionSubsections(string $html, string $extensionSlug, string $extensionName): array
    {
        $crawler = new Crawler($html);
        $subsections = [];

        // Look for links to extension pages
        // Patterns:
        // - /extensions/{extension}/examples.html
        // - /extensions/{extension}/{page-name}.html
        // - /extensions/{extension}/examples/{example-name}.html
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$subsections, $extensionSlug, $extensionName) {
            $href = $node->attr('href');
            
            // Convert relative URLs to absolute
            if (str_starts_with($href, './')) {
                $href = "/extensions/$extensionSlug/" . ltrim($href, './');
            } elseif (str_starts_with($href, '../')) {
                // Skip parent directory references
                return;
            }
            
            // Match extension pages
            // Pattern 1: /extensions/{extension}/{page}.html
            // Pattern 2: /extensions/{extension}/examples/{example}.html
            if (preg_match("#^/extensions/$extensionSlug/([^/\#\?]+\.html)#", $href, $matches) ||
                preg_match("#^/extensions/$extensionSlug/examples/([^/\#\?]+\.html)#", $href, $matches)) {
                
                $title = trim($node->text());
                $fullUrl = "{$this->baseUrl}{$href}";
                
                // Avoid duplicates and skip if no title
                $key = md5($fullUrl);
                if (!isset($subsections[$key]) && !empty($title)) {
                    $subsections[$key] = [
                        'url' => $fullUrl,
                        'extension' => $extensionName,
                        'subsection' => $title
                    ];
                }
            }
        });

        return array_values($subsections);
    }

    /**
     * Parse and store an extension page
     */
    private function parseAndStoreExtensionPage(string $html, string $url, string $extension, ?string $subsection = null): void
    {
        $crawler = new Crawler($html);

        // Extract title
        $title = $crawler->filter('h1')->first();
        $titleText = $title->count() > 0 ? trim($title->text()) : ($subsection ?? $extension);

        // Extract main content
        $content = $crawler->filter('.doc-content, article, .extension-content, main');
        
        if ($content->count() === 0) {
            // Fallback: try to get any meaningful content
            $content = $crawler->filter('body');
        }

        $contentText = $content->count() > 0 ? $this->extractText($content) : '';

        // Also extract code examples if present
        $codeBlocks = $crawler->filter('pre code, .code-example');
        $codeText = '';
        
        $codeBlocks->each(function (Crawler $node) use (&$codeText) {
            $codeText .= "\n\nCode example:\n" . trim($node->text()) . "\n";
        });

        $fullContent = $contentText . $codeText;

        if (empty($fullContent)) {
            echo "    Warning: No content extracted from $url\n";
            return;
        }

        $displaySection = $subsection ? "$extension - $subsection" : $extension;
        $this->storeDocument($titleText, $url, $fullContent, $displaySection, 'extension');
    }

    /**
     * Fetch URL content
     */
    private function fetchUrl(string $url): string
    {
        $response = $this->client->get($url);
        return (string) $response->getBody();
    }

    /**
     * Parse and store a manual page
     */
    private function parseAndStoreManualPage(string $html, string $url, string $section, ?string $subsection = null): void
    {
        $crawler = new Crawler($html);

        // Extract title
        $title = $crawler->filter('h1')->first();
        $titleText = $title->count() > 0 ? trim($title->text()) : ($subsection ?? $section);

        // Extract main content
        $content = $crawler->filter('.doc-content, article, .manual-content, main');
        
        if ($content->count() === 0) {
            // Fallback: try to get any meaningful content
            $content = $crawler->filter('body');
        }

        $contentText = $content->count() > 0 ? $this->extractText($content) : '';

        if (empty($contentText)) {
            echo "    Warning: No content extracted from $url\n";
            return;
        }

        $displaySection = $subsection ? "$section - $subsection" : $section;
        $this->storeDocument($titleText, $url, $contentText, $displaySection, 'manual');
    }

    /**
     * Extract manual subsection URLs from a section page
     */
    private function extractManualSubsections(string $html, string $sectionSlug): array
    {
        $crawler = new Crawler($html);
        $subsections = [];

        // Look for links that are subsections of this manual section
        // Pattern: /manual/{section-slug}/{subsection-slug}
        $crawler->filter('a[href^="/manual/"]')->each(function (Crawler $node) use (&$subsections, $sectionSlug) {
            $href = $node->attr('href');
            
            // Parse the URL path
            // Example: /manual/data/orthogonal-data
            if (preg_match('#^/manual/([^/]+)/([^/\#\?]+)#', $href, $matches)) {
                $urlSection = $matches[1];
                $subsectionSlug = $matches[2];
                
                // Only include if it's a subsection of the current section
                if ($urlSection === $sectionSlug) {
                    $title = trim($node->text());
                    $fullUrl = "{$this->baseUrl}{$href}";
                    
                    // Avoid duplicates
                    $key = md5($fullUrl);
                    if (!isset($subsections[$key]) && !empty($title)) {
                        $subsections[$key] = [
                            'url' => $fullUrl,
                            'section' => ucfirst(str_replace('-', ' ', $sectionSlug)),
                            'subsection' => $title
                        ];
                    }
                }
            }
        });

        return array_values($subsections);
    }

    /**
     * Extract individual example URLs from a category page
     */
    private function extractIndividualExamples(string $html, string $categorySlug, string $categoryName): array
    {
        $crawler = new Crawler($html);
        $examples = [];

        // DataTables uses relative links like ./zero_configuration.html
        // We need to find all .html links and resolve them
        $crawler->filter('a[href$=".html"]')->each(function (Crawler $node) use (&$examples, $categoryName, $categorySlug) {
            $href = $node->attr('href');
            
            // Skip if it has anchors or is not a relative link
            if (strpos($href, '#') !== false || !str_starts_with($href, './')) {
                return;
            }

            // Skip index.html as it's just the category page
            if ($href === './index.html') {
                return;
            }

            $title = trim($node->text());
            
            if (!empty($title) && !empty($href)) {
                // Convert relative URL to absolute
                // ./zero_configuration.html -> https://datatables.net/examples/basic_init/zero_configuration.html
                $cleanHref = ltrim($href, './');
                $fullUrl = "{$this->baseUrl}/examples/$categorySlug/$cleanHref";
                
                // Avoid duplicates
                $key = md5($fullUrl);
                if (!isset($examples[$key])) {
                    $examples[$key] = [
                        'url' => $fullUrl,
                        'title' => $title,
                        'category' => $categoryName
                    ];
                }
            }
        });

        return array_values($examples);
    }

    /**
     * Parse and store an example page
     */
    private function parseAndStoreExample(string $html, string $url, string $title, string $category): void
    {
        $crawler = new Crawler($html);

        // Extract description/content
        $content = $crawler->filter('.demo-description, .example-description, article, main');
        
        if ($content->count() === 0) {
            $content = $crawler->filter('body');
        }

        $contentText = $content->count() > 0 ? $this->extractText($content) : '';

        // Also try to extract any code examples
        $codeBlocks = $crawler->filter('pre code, .code-example');
        $codeText = '';
        
        $codeBlocks->each(function (Crawler $node) use (&$codeText) {
            $codeText .= "\n\nCode example:\n" . trim($node->text()) . "\n";
        });

        $fullContent = $contentText . $codeText;

        if (empty($fullContent)) {
            echo "      Warning: No content extracted\n";
            return;
        }

        $this->storeDocument($title, $url, $fullContent, $category, 'example');
    }

    /**
     * Extract clean text from HTML crawler node
     */
    private function extractText(Crawler $crawler): string
    {
        // Remove script and style tags
        $crawler->filter('script, style, nav, .navigation, .sidebar')->each(function (Crawler $node) {
            foreach ($node as $domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });

        $text = $crawler->text();
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Check if URL is already indexed
     */
    private function isUrlIndexed(string $url): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM documentation WHERE url = :url");
        $stmt->execute(['url' => $url]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Store document in database
     */
    private function storeDocument(string $title, string $url, string $content, string $section, string $docType): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO documentation (title, url, content, section, doc_type)
            VALUES (:title, :url, :content, :section, :doc_type)
        ");

        $stmt->execute([
            'title' => $title,
            'url' => $url,
            'content' => $content,
            'section' => $section,
            'doc_type' => $docType
        ]);
    }

    /**
     * Show indexing statistics
     */
    private function showStats(): void
    {
        $stmt = $this->db->query("
            SELECT 
                doc_type,
                COUNT(*) as count
            FROM documentation
            GROUP BY doc_type
        ");

        echo "Indexing Statistics:\n";
        echo "-------------------\n";
        
        $total = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $count = $row['count'];
            $total += $count;
            echo "  {$row['doc_type']}: $count documents\n";
        }
        
        echo "  TOTAL: $total documents\n";
        echo "\nIndexing complete! Database ready for searching.\n";
    }
}
