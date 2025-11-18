# Agent Guide: DataTables MCP Server

This is a PHP-based MCP (Model Context Protocol) server that provides searchable access to DataTables.net documentation for AI agents.

## Project Type

- **Language**: PHP 8.1+
- **Type**: MCP Server (stdio-based)
- **Package Manager**: Composer
- **Database**: SQLite with FTS5 (Full-Text Search)
- **Dependencies**: Guzzle HTTP client, Symfony DomCrawler

## Essential Commands

### Installation
```bash
composer install
```

### Index Documentation (Required First Run)
```bash
composer run index
# OR
php bin/datatables-mcp index
```

This scrapes DataTables.net documentation and stores it in SQLite database at `data/datatables.db`.

### Run MCP Server
```bash
composer run serve
# OR
php bin/datatables-mcp serve
```

Runs stdio-based MCP server (reads JSON-RPC from stdin, writes to stdout).

### Test Search Functionality
```bash
php bin/datatables-mcp search "query here"
```

### Database Statistics
```bash
php bin/datatables-mcp stats
```

### View All Commands
```bash
php bin/datatables-mcp help
```

## Project Structure

```
datatables-mcp/
├── bin/
│   └── datatables-mcp          # CLI entry point (executable)
├── src/
│   ├── McpServer.php           # MCP protocol handler (JSON-RPC 2.0 over stdio)
│   ├── DocumentationIndexer.php # Web scraper for DataTables.net
│   └── SearchEngine.php        # SQLite FTS5 search wrapper
├── data/
│   └── datatables.db           # SQLite database (created by indexer)
├── vendor/                     # Composer dependencies
├── composer.json               # Package definition and scripts
├── README.md                   # User-facing documentation
├── GUIDE.md                    # Complete technical guide
└── AGENTS.md                   # This file
```

## Architecture Overview

### MCP Server (stdio protocol)

**How it works:**
1. Reads JSON-RPC 2.0 requests from stdin (one per line)
2. Routes to handler methods based on `method` field
3. Executes searches via SearchEngine
4. Writes JSON-RPC 2.0 responses to stdout
5. Logs debug info to stderr (doesn't interfere with protocol)

**MCP Protocol Lifecycle (v2025-06-18):**
1. Client sends `initialize` request → Server responds with capabilities
2. Client sends `notifications/initialized` notification (no response)
3. Server marks itself as ready
4. Client can now call `tools/list`, `tools/call`, etc.

**Key methods handled:**
- `initialize` - Handshake with client (returns protocol version, capabilities)
- `notifications/initialized` - Client notification that initialization is complete
- `tools/list` - Returns available tools (only after initialized)
- `tools/call` - Executes tool (search_datatables)
- `resources/list` - Returns available resources (currently none)
- `prompts/list` - Returns available prompts (currently none)

**State tracking:**
- Server tracks `initialized` state (defaults to false)
- All requests except `initialize` are rejected until `notifications/initialized` is received
- Ensures proper protocol compliance with MCP specification

### Database Schema

```sql
-- Main table
CREATE TABLE documentation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    url TEXT UNIQUE NOT NULL,
    content TEXT NOT NULL,
    section TEXT,
    doc_type TEXT NOT NULL,  -- 'manual' or 'example'
    indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- FTS5 virtual table (full-text search index)
CREATE VIRTUAL TABLE documentation_fts USING fts5(
    title, url, content, section, doc_type,
    content='documentation',
    content_rowid='id'
);

-- Triggers keep FTS5 in sync with main table
-- (3 triggers: INSERT, UPDATE, DELETE)
```

### Search Engine (FTS5)

Uses SQLite FTS5 for fast full-text search with ranking.

**FTS5 query syntax:**
- `ajax options` - implicit AND (both words required)
- `ajax OR server` - either word
- `"exact phrase"` - exact match
- `ajax NOT deprecated` - exclude word
- `ajax*` - prefix match

**Search implementation:**
```php
$searchEngine->search("ajax options", $limit = 10);
```

Returns array of matches ordered by relevance (rank).

### Documentation Indexer

**Scraping process:**
1. Fetches manual sections from datatables.net/manual/
2. Fetches examples from datatables.net/examples/
3. Parses HTML with Symfony DomCrawler
4. Extracts clean text (removes nav, scripts, styles)
5. Stores in SQLite
6. Triggers automatically populate FTS5 index

**Manual sections indexed:**
- Installation, Data, Ajax, Options, API, Search, Styling, Events
- Server-side processing, Internationalisation, Security
- React, Vue, Plug-in development, Technical notes

**Example categories:**
- Basic/Advanced initialisation, Data sources, Styling, Layout
- API, Ajax, Server-side processing, and more

## Code Conventions

### PHP Standards

- **PHP Version**: 8.1+ (uses match expressions)
- **Namespace**: `DataTablesMcp\`
- **PSR-4 Autoloading**: `src/` maps to `DataTablesMcp\`
- **Error Handling**: Uses exceptions, PDO error mode set to EXCEPTION
- **Type Hints**: Used throughout (return types, parameter types)

### Database Patterns

- **Always use prepared statements**: No raw SQL with user input
- **PDO error mode**: `ERRMODE_EXCEPTION` set on all connections
- **Transactions**: Not currently used (single inserts are fine for indexing)
- **FTS5 sync**: Triggers keep FTS5 in sync automatically

### MCP Protocol Patterns

- **stdin/stdout**: Protocol communication only
- **stderr**: Debug logging (safe to use, doesn't interfere)
- **One message per line**: Each JSON-RPC message ends with `\n`
- **Response includes request ID**: Echo back the `id` from request
- **Error codes**: Use standard JSON-RPC error codes (-32700, -32603, etc.)

### Code Style

- **Indentation**: 4 spaces
- **Braces**: Opening brace on same line
- **Comments**: PHPDoc blocks on classes and public methods
- **Logging**: Use `log()` method which writes to stderr
- **No trailing whitespace**: Clean up before committing

## Testing Approach

### Manual Testing

**Test search locally (bypasses MCP):**
```bash
php bin/datatables-mcp search "ajax server-side"
```

**Test MCP protocol directly:**
```bash
# Create test file with JSON-RPC requests (one per line)
echo '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"test"}},"id":1}' > test.json
echo '{"jsonrpc":"2.0","method":"tools/list","id":2}' >> test.json
echo '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"search_datatables","arguments":{"query":"ajax"}},"id":3}' >> test.json

# Run through server
cat test.json | php bin/datatables-mcp serve

# Pretty print with jq
cat test.json | php bin/datatables-mcp serve | jq .
```

**Test with Claude Desktop:**

Add to `~/Library/Application Support/Claude/claude_desktop_config.json`:
```json
{
  "mcpServers": {
    "datatables": {
      "type": "stdio",
      "command": "php",
      "args": [
        "/absolute/path/to/datatables-mcp/bin/datatables-mcp",
        "serve"
      ]
    }
  }
}
```

**Test with Charm Crush:**

Add to `.crush.json` in your project root:
```json
{
  "mcpServers": {
    "datatables": {
      "type": "stdio",
      "command": "php",
      "args": [
        "vendor/bin/datatables-mcp",
        "serve"
      ],
      "timeout": 120,
      "disabled": false,
      "env": {}
    }
  }
}
```

### No Automated Tests

Currently no PHPUnit tests. Testing is manual via CLI commands.

To add tests in the future:
1. Add `phpunit/phpunit` to composer.json require-dev
2. Create `tests/` directory
3. Test SearchEngine with known queries
4. Mock HTTP responses for DocumentationIndexer
5. Test McpServer request/response handling

## Important Gotchas

### Database Must Exist Before Serving

**Problem**: Running `php bin/datatables-mcp serve` without indexing first will fail.

**Solution**: Always run `composer run index` after fresh install:
```bash
composer install
composer run index  # Creates data/datatables.db
composer run serve  # Now this works
```

### stdio Protocol is Strict

**Problem**: Any stray output to stdout breaks the protocol.

**Solutions:**
- Use stderr for all debugging: `fwrite(STDERR, "debug\n")`
- Use the `log()` method in McpServer (writes to stderr)
- Never use `echo` or `print` in server code
- Redirect stderr in production: `php bin/datatables-mcp serve 2> /dev/null`

### FTS5 Must Be Available

**Problem**: Some SQLite builds don't include FTS5.

**Check if available:**
```bash
php -r "echo (new PDO('sqlite::memory:'))->query('pragma compile_options')->fetchAll(PDO::FETCH_COLUMN)[0];"
```

Look for "ENABLE_FTS5" in output.

**Solution if missing**: Install SQLite3 PHP extension properly, or use a different PHP build.

### Scraping Can Fail

**Problem**: DataTables.net might change their HTML structure.

**Symptoms**: Indexer runs but extracts empty content, or very short content.

**Debug:**
```bash
php bin/datatables-mcp stats
# Should show ~26 documents
# If 0 or very few, scraping failed

# Check database
sqlite3 data/datatables.db "SELECT title, length(content) FROM documentation LIMIT 10;"
```

**Solution**: Update CSS selectors in `DocumentationIndexer.php`:
```php
// Currently tries: .doc-content, article, .manual-content, main
// Add more fallbacks if structure changes
$content = $crawler->filter('.new-selector, .doc-content, article, main');
```

### Rate Limiting

**Problem**: Too many rapid requests to datatables.net might get rate limited.

**Current protection**: 0.5 second delay between requests (usleep(500000))

**If scraped fails with 429 errors**: Increase delay in DocumentationIndexer.php

### Hyphenated Search Terms

**Problem**: Searching for hyphenated terms like "server-side" used to fail with "no such column: side".

**Root cause**: FTS5's default `unicode61` tokenizer treats hyphens as separators, so "server-side" is indexed as two tokens: "server" and "side". Without sanitization, FTS5 interprets the hyphen as a minus operator.

**Solution (implemented)**: `SearchEngine::sanitizeQuery()` automatically converts hyphenated terms to phrase queries:
- Input: `server-side processing`
- Sanitized: `"server side" processing`

**Supported query types:**
- Simple hyphenated: `server-side` → works
- Multiple hyphens: `server-side client-side` → works
- Mixed: `ajax server-side processing` → works
- Preserves FTS5 operators: `ajax AND options` → unchanged
- Preserves quoted phrases: `"exact phrase"` → unchanged

**Testing**: Run `php test-search-sanitization.php` to verify all query patterns work.

### FTS5 Query Syntax Errors

**Problem**: Invalid FTS5 syntax can throw exceptions.

**Common errors:**
- Unbalanced quotes: `"ajax` instead of `"ajax"` or `ajax`
- Invalid operators: `ajax && server` (use `ajax AND server`)

**Solution**: The `sanitizeQuery()` method handles most common cases automatically. For advanced FTS5 syntax (AND, OR, NOT, quoted phrases), the query is passed through unchanged.

## Common Modification Tasks

### Add More Documentation Sources

Edit `DocumentationIndexer.php`:

```php
public function indexAll(): void
{
    $this->indexManual();
    $this->indexExamples();
    $this->indexApiReference();  // Add new method
}

private function indexApiReference(): void
{
    // Scrape https://datatables.net/reference/api/
    // Parse and store similar to indexManual()
}
```

### Add Search Filters

Edit `SearchEngine.php` to add type/section filtering:

```php
public function search(string $query, int $limit = 10, ?string $docType = null): array
{
    $sql = "
        SELECT d.title, d.url, d.content, d.section, d.doc_type, fts.rank
        FROM documentation_fts fts
        JOIN documentation d ON d.id = fts.rowid
        WHERE documentation_fts MATCH :query
    ";
    
    if ($docType !== null) {
        $sql .= " AND d.doc_type = :doc_type";
    }
    
    $sql .= " ORDER BY rank LIMIT :limit";
    
    $stmt = $this->db->prepare($sql);
    $stmt->bindValue(':query', $query, \PDO::PARAM_STR);
    
    if ($docType !== null) {
        $stmt->bindValue(':doc_type', $docType, \PDO::PARAM_STR);
    }
    
    $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
```

Then update tool schema in `McpServer.php` to accept `doc_type` parameter.

### Add New MCP Tool

Edit `McpServer.php`:

1. Add to `handleToolsList()`:
```php
[
    'name' => 'get_example_code',
    'description' => 'Get full code for a specific DataTables example',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'Example URL from datatables.net'
            ]
        ],
        'required' => ['url']
    ]
]
```

2. Handle in `handleToolsCall()`:
```php
if ($toolName === 'get_example_code') {
    $url = $arguments['url'] ?? '';
    // Fetch and parse code from URL
    // Return formatted code
}
```

### Add Resources

Resources are static/dynamic content AI can read (not tools).

Edit `handleResourcesList()` in `McpServer.php`:

```php
private function handleResourcesList(): array
{
    return [
        'resources' => [
            [
                'uri' => 'datatables://quick-start',
                'name' => 'DataTables Quick Start Guide',
                'description' => 'Step-by-step guide to get started',
                'mimeType' => 'text/plain'
            ]
        ]
    ];
}
```

Then implement `resources/read` handler.

### Improve Search Results

**Add context/snippets:**

Modify `formatSearchResults()` to show query term context:

```php
// Extract 200 chars around first occurrence of query term
$pos = stripos($content, $queryFirstWord);
if ($pos !== false) {
    $start = max(0, $pos - 100);
    $excerpt = substr($content, $start, 200);
}
```

**Highlight search terms:**

Use FTS5 `highlight()` function:

```sql
SELECT highlight(documentation_fts, 2, '<b>', '</b>') as content_highlighted
FROM documentation_fts
WHERE documentation_fts MATCH :query
```

## Debugging Tips

### Enable Logging

Run server with stderr redirected to file:
```bash
php bin/datatables-mcp serve 2> server.log

# In another terminal
tail -f server.log
```

### Inspect Database

```bash
sqlite3 data/datatables.db

# Check content
SELECT title, length(content), doc_type FROM documentation LIMIT 10;

# Test FTS5 search
SELECT title, rank FROM documentation_fts 
WHERE documentation_fts MATCH 'ajax' 
ORDER BY rank LIMIT 5;

# Check if FTS5 is in sync
SELECT COUNT(*) FROM documentation;
SELECT COUNT(*) FROM documentation_fts;  -- Should match

# View FTS5 terms
SELECT term FROM documentation_fts_vocab('col', 'content') LIMIT 20;
```

### Test JSON-RPC Messages

Use `jq` to validate and pretty-print:
```bash
echo '{"jsonrpc":"2.0","method":"tools/list","id":1}' | \
  php bin/datatables-mcp serve 2>/dev/null | jq .
```

### Check Protocol Compliance

Each message must:
- Be valid JSON
- End with newline (`\n`)
- Include `jsonrpc: "2.0"`
- Include `method` (requests) or `result`/`error` (responses)
- Include `id` field (except notifications)

### Performance Profiling

Time search queries:
```bash
time php bin/datatables-mcp search "ajax options"
```

Check database size:
```bash
ls -lh data/datatables.db
```

Analyze FTS5 performance:
```sql
EXPLAIN QUERY PLAN 
SELECT * FROM documentation_fts 
WHERE documentation_fts MATCH 'ajax';
```

## Security Considerations

### Input Validation

- **Search queries**: FTS5 uses prepared statements (safe)
- **Tool parameters**: Validated before use
- **File paths**: Only one hardcoded database path used
- **No code execution**: Server only reads database

### Network Exposure

- **None**: stdio protocol only
- Server doesn't listen on any network port
- No HTTP endpoints to secure
- Client must have shell access to run server

### Data Privacy

- **Public data only**: Scrapes public DataTables.net documentation
- **No user data**: Doesn't collect or store user information
- **No tracking**: No analytics or telemetry

### Future Considerations

If extending the server:
- Always use prepared statements for SQL
- Validate and sanitize all user inputs
- Never execute user-provided code
- Be careful with file system access
- Consider adding authentication if exposing over network

## Performance Characteristics

### Current Stats

- **Database size**: ~500KB for 26 documents
- **Index time**: ~10 seconds (with 0.5s delays between requests)
- **Search time**: < 10ms for typical queries
- **Memory usage**: ~10MB for server process

### Scalability

FTS5 can handle:
- Millions of documents efficiently
- Gigabytes of text content
- Sub-second search times

To scale further:
- Add pagination to search results
- Cache frequently searched terms
- Add database indexes on doc_type, section
- Consider splitting into multiple databases by category

## Resources

- **MCP Specification**: https://modelcontextprotocol.io/
- **SQLite FTS5**: https://www.sqlite.org/fts5.html
- **JSON-RPC 2.0**: https://www.jsonrpc.org/specification
- **DataTables.net**: https://datatables.net/
- **Symfony DomCrawler**: https://symfony.com/doc/current/components/dom_crawler.html
- **Guzzle HTTP**: https://docs.guzzlephp.org/

## Quick Reference

```bash
# First time setup
composer install
composer run index

# Run server
composer run serve

# Test
php bin/datatables-mcp search "query"
php bin/datatables-mcp stats

# Debug
php bin/datatables-mcp serve 2> server.log
sqlite3 data/datatables.db

# Re-index (if DataTables.net updates)
composer run index
```

## File Modification Guide

| Task | Files to Edit |
|------|---------------|
| Add new MCP tool | `src/McpServer.php` (handleToolsList, handleToolsCall) |
| Add new doc source | `src/DocumentationIndexer.php` (indexAll, add new method) |
| Change search logic | `src/SearchEngine.php` (search method) |
| Add CLI command | `bin/datatables-mcp` (add new case in switch) |
| Change DB schema | `src/DocumentationIndexer.php` (initializeDatabase) |
| Add dependencies | `composer.json` (require section) |
| Change scraping targets | `src/DocumentationIndexer.php` (manualSections array, CSS selectors) |

## Summary

This is a complete, working MCP server that demonstrates:
- stdio-based JSON-RPC 2.0 protocol
- SQLite FTS5 full-text search
- Web scraping with Symfony DomCrawler
- Proper separation of concerns (Server, Indexer, SearchEngine)
- Clean CLI interface

The code is well-structured and ready to extend with additional tools, resources, or documentation sources.
