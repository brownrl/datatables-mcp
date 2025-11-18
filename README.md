# DataTables MCP Server

[![License](https://img.shields.io/github/license/brownrl/datatables-mcp)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/brownrl/datatables-mcp)](https://github.com/brownrl/datatables-mcp/releases)

An MCP (Model Context Protocol) server that provides AI agents with searchable access to DataTables.net documentation and examples.

## What is MCP?

MCP (Model Context Protocol) is a protocol that allows AI assistants to access external tools and data. This server exposes DataTables.net documentation through a searchable interface that AI agents can query.

## Features

- 🔍 **Full-text search** across 196 DataTables documentation pages
- 📚 **Complete coverage**: Manual (62 pages), Examples (120 pages), Extensions (14 pages)
- ⚡ **Fast SQLite FTS5** search engine
- 🤖 **Ready-to-use**: Pre-indexed database included
- 🔌 **MCP compatible**: Works with Claude Desktop and other MCP clients
- 📦 **Easy install**: Install directly from GitHub via Composer

## Installation

### As a Composer Package (Recommended)

Add the GitHub repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/brownrl/datatables-mcp"
        }
    ],
    "require": {
        "brownrl/datatables-mcp": "^1.0"
    }
}
```

Then install:

```bash
composer install
```

**Or install directly via command line:**

```bash
composer config repositories.datatables-mcp vcs https://github.com/brownrl/datatables-mcp
composer require brownrl/datatables-mcp:^1.0
```

**To use the latest development version:**

```bash
composer require brownrl/datatables-mcp:dev-main
```

The package includes a **pre-indexed database** with 196 DataTables documents ready to search. No indexing required!

**Requirements**: PHP 8.1+ with SQLite FTS5 support

### Installation Examples

**Example 1: Add to existing project**
```bash
cd /path/to/your-project
composer config repositories.datatables-mcp vcs https://github.com/brownrl/datatables-mcp
composer require brownrl/datatables-mcp:^1.0
```

**Example 2: New project with stable version**
```bash
mkdir my-ai-project && cd my-ai-project
composer init --no-interaction
composer config repositories.datatables-mcp vcs https://github.com/brownrl/datatables-mcp
composer require brownrl/datatables-mcp:^1.0
```

**Example 3: Use development version (latest changes)**
```bash
cd /path/to/your-project
composer config repositories.datatables-mcp vcs https://github.com/brownrl/datatables-mcp
composer require brownrl/datatables-mcp:dev-main
```

### Configure Claude Desktop

Add to your Claude Desktop config file:

**macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`  
**Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "datatables": {
      "command": "/absolute/path/to/your-project/vendor/bin/datatables-mcp",
      "args": ["serve"]
    }
  }
}
```

**Important**: Replace `/absolute/path/to/your-project` with the actual path to your project directory.

**Example** (if your project is at `/Users/john/projects/my-app`):
```json
{
  "mcpServers": {
    "datatables": {
      "command": "/Users/john/projects/my-app/vendor/bin/datatables-mcp",
      "args": ["serve"]
    }
  }
}
```

### Restart Claude Desktop

Restart Claude Desktop to load the MCP server.

### Test It

In Claude Desktop, ask:
- "How do I enable server-side processing in DataTables?"
- "Show me examples of row grouping"
- "What are the options for FixedColumns extension?"

Claude will search the DataTables documentation to answer!

## Manual Testing

Test search functionality from your project directory:

```bash
# Search documentation
vendor/bin/datatables-mcp search "ajax server-side"

# View database statistics
vendor/bin/datatables-mcp stats

# Run MCP server (for debugging)
vendor/bin/datatables-mcp serve
```

## Database

The repository includes a **pre-indexed database** (`data/datatables.db`) with:
- 62 manual pages (Installation, Data, Ajax, API, Options, etc.)
- 120 examples (Basic/Advanced init, Styling, Layout, API, Ajax, Server-side, etc.)
- 14 extensions (FixedColumns, Responsive, Buttons, RowGroup, etc.)

**Total**: 196 fully searchable documents

## Alternative: Standalone Installation

If you want to use this outside a project or contribute to development:

```bash
git clone https://github.com/brownrl/datatables-mcp.git
cd datatables-mcp
composer install
php bin/datatables-mcp serve
```

Then configure Claude Desktop with the path to the cloned directory:
```json
{
  "mcpServers": {
    "datatables": {
      "command": "/path/to/datatables-mcp/bin/datatables-mcp",
      "args": ["serve"]
    }
  }
}
```

## Updating the Package

To get the latest version with updated documentation:

**For stable releases:**
```bash
composer update brownrl/datatables-mcp
```

**For development version (dev-main):**
```bash
composer require brownrl/datatables-mcp:dev-main
```

This pulls the latest pre-indexed database from GitHub.

## Re-indexing (Optional)

The database is pre-indexed and ready to use. To manually re-index (e.g., to get the absolute latest from DataTables.net):

```bash
# If installed via Composer
cd your-project
vendor/bin/datatables-mcp index

# If cloned standalone
php bin/datatables-mcp index
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
