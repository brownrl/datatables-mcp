# DataTables MCP Server

An MCP (Model Context Protocol) server that provides AI agents with searchable access to DataTables.net documentation and examples.

## What is MCP?

MCP (Model Context Protocol) is a protocol that allows AI assistants to access external tools and data. This server exposes DataTables.net documentation through a searchable interface that AI agents can query.

## Features

- 🔍 **Full-text search** across 196 DataTables documentation pages
- 📚 **Complete coverage**: Manual (62 pages), Examples (120 pages), Extensions (14 pages)
- ⚡ **Fast SQLite FTS5** search engine
- 🤖 **Ready-to-use**: Pre-indexed database included
- 🔌 **MCP compatible**: Works with Claude Desktop and other MCP clients

## Quick Start

### 1. Clone and Install

```bash
git clone https://github.com/brownrl/datatables-mcp.git
cd datatables-mcp
composer install
```

**Note**: PHP 8.1+ required with SQLite FTS5 support

### 2. Configure Claude Desktop

Add to your Claude Desktop config:

**macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "datatables": {
      "command": "php",
      "args": ["/absolute/path/to/datatables-mcp/bin/datatables-mcp", "serve"]
    }
  }
}
```

Replace `/absolute/path/to/datatables-mcp` with your actual path.

### 3. Restart Claude Desktop

Restart Claude Desktop to load the MCP server.

### 4. Test It

In Claude Desktop, ask:
- "How do I enable server-side processing in DataTables?"
- "Show me examples of row grouping"
- "What are the options for FixedColumns extension?"

Claude will now search the DataTables documentation to answer your questions!

## Manual Testing

Test search functionality without an MCP client:

```bash
# Search documentation
php bin/datatables-mcp search "ajax server-side"

# View database statistics
php bin/datatables-mcp stats

# Run MCP server (for debugging)
php bin/datatables-mcp serve
```

## Database

The repository includes a **pre-indexed database** (`data/datatables.db`) with:
- 62 manual pages (Installation, Data, Ajax, API, Options, etc.)
- 120 examples (Basic/Advanced init, Styling, Layout, API, Ajax, Server-side, etc.)
- 14 extensions (FixedColumns, Responsive, Buttons, RowGroup, etc.)

**Total**: 196 fully searchable documents

## Re-indexing (Optional)

To update the documentation (e.g., when DataTables.net releases updates):

```bash
composer run index
```

This will:
- Fetch latest documentation from datatables.net
- Only index new/missing content (incremental indexing)
- Take ~5-10 minutes with 1.5s delays between requests

## Requirements

- **PHP**: 8.1 or higher
- **Extensions**: 
  - `pdo_sqlite` with FTS5 support
  - `mbstring`
  - `json`
- **Composer**: For dependency management

Check if FTS5 is available:

```bash
php -r "echo extension_loaded('pdo_sqlite') ? 'SQLite OK' : 'SQLite missing';"
```

## How It Works

### MCP Tools

The server exposes these tools to AI agents:

- **search_datatables**: Full-text search across DataTables documentation
  - Parameters: `query` (string) - search terms
  - Returns: Relevant documentation with titles, URLs, and content excerpts

### Architecture

1. **McpServer** (`src/McpServer.php`): JSON-RPC 2.0 over stdio
2. **DocumentationIndexer** (`src/DocumentationIndexer.php`): Web scraper with incremental indexing
3. **SearchEngine** (`src/SearchEngine.php`): SQLite FTS5 full-text search
4. **Database** (`data/datatables.db`): Pre-indexed SQLite database

### Database Schema

```sql
CREATE TABLE documentation (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    url TEXT UNIQUE NOT NULL,
    content TEXT NOT NULL,
    section TEXT,
    doc_type TEXT,  -- 'manual', 'example', 'extension'
    indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE VIRTUAL TABLE documentation_fts USING fts5(
    title, url, content, section, doc_type,
    content='documentation',
    content_rowid='id'
);
```

## Project Structure

```
datatables-mcp/
├── bin/
│   └── datatables-mcp          # CLI entry point
├── src/
│   ├── McpServer.php           # MCP protocol handler
│   ├── DocumentationIndexer.php # Web scraper
│   └── SearchEngine.php        # FTS5 search
├── data/
│   └── datatables.db           # Pre-indexed SQLite database (4.4MB)
├── composer.json
├── README.md
├── GUIDE.md                    # Complete technical guide
└── AGENTS.md                   # Agent/AI development guide
```

## Troubleshooting

### MCP Server Not Appearing in Claude

1. Check config path is correct (use absolute paths)
2. Restart Claude Desktop completely
3. Check stderr logs: `php bin/datatables-mcp serve 2> debug.log`

### Search Returns No Results

1. Verify database exists: `ls -lh data/datatables.db`
2. Check database stats: `php bin/datatables-mcp stats`
3. Test search directly: `php bin/datatables-mcp search "test query"`

### Re-index If Database Missing

If `data/datatables.db` is missing or corrupted:

```bash
rm -f data/datatables.db
composer run index
```

## Documentation

- **README.md**: Quick start guide (this file)
- **GUIDE.md**: Complete technical documentation
- **AGENTS.md**: Development guide for AI agents
- **QUICKSTART.md**: 5-minute setup guide

## Contributing

Contributions welcome! Please:
1. Test with `php bin/datatables-mcp search "test"`
2. Verify MCP protocol compatibility
3. Include documentation updates

## License

MIT License - see LICENSE file for details

## Credits

- DataTables.net for excellent documentation
- MCP Protocol by Anthropic
- Built with PHP 8.1+ and SQLite FTS5
