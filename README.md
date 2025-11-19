# DataTables MCP Server

[![License](https://img.shields.io/github/license/brownrl/datatables-mcp)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/brownrl/datatables-mcp)](https://github.com/brownrl/datatables-mcp/releases)

MCP server providing AI agents with searchable access to DataTables.net documentation (196 pages: manual, examples, extensions).

**Requirements**: PHP 8.1+ with SQLite FTS5

## Quick Install

```bash
composer config repositories.datatables-mcp vcs https://github.com/brownrl/datatables-mcp
composer require brownrl/datatables-mcp:^1.0
```

Or for latest development version:
```bash
composer require brownrl/datatables-mcp:dev-main
```

## Configuration

### Claude Desktop

Edit config file:
- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "datatables": {
      "type": "stdio",
      "command": "/absolute/path/to/your-project/vendor/bin/datatables-mcp",
      "args": ["serve"]
    }
  }
}
```

Restart Claude Desktop after saving.

### Claude Code (Windsurf)

Add to your MCP settings in Claude Code:

```json
{
  "mcpServers": {
    "datatables": {
      "type": "stdio",
      "command": "/absolute/path/to/your-project/vendor/bin/datatables-mcp",
      "args": ["serve"]
    }
  }
}
```

### Charm Crush

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

**Note**: Crush runs from your project directory, so relative paths work. Restart Crush after saving.

## Test It

```bash
# Search documentation
vendor/bin/datatables-mcp search "ajax server-side"

# View stats
vendor/bin/datatables-mcp stats

# Run server (for debugging)
vendor/bin/datatables-mcp serve
```

Or ask your AI agent:
- "How do I enable server-side processing in DataTables?"
- "Show me examples of row grouping"
- "What are the FixedColumns extension options?"

## Update

```bash
composer update brownrl/datatables-mcp
```

## Standalone Install

For use outside a project:

```bash
git clone https://github.com/brownrl/datatables-mcp.git
cd datatables-mcp
composer install
```

Configure with: `/path/to/datatables-mcp/bin/datatables-mcp`

## Available Tools

The server provides **5 specialized tools** for comprehensive DataTables documentation assistance:

### 1. search_datatables
General documentation search across all content.

**Use when**: You need to find information about any DataTables feature.

```
Ask: "How do I enable server-side processing?"
Returns: Documentation pages with parameters, examples, and related items
```

### 2. get_function_details
Get complete details about a specific function, option, or event.

**Use when**: You need deep information about a specific API method or option.

```
Ask: "Tell me everything about ajax.reload()"
Returns: All parameters, return types, code examples, related functions
```

### 3. search_by_example
Search specifically within code examples to find usage patterns.

**Use when**: You want to see how to use a specific feature in practice.

```
Ask: "Show me examples using setInterval"
Returns: Functions with code examples containing setInterval
```

### 4. search_by_topic
Filter searches by documentation section or type.

**Use when**: You need focused results within a specific area (API, Options, Events, etc.).

```
Ask: "Find all ajax-related API methods"
Returns: Results filtered to API section only
```

### 5. get_related_items
Navigate relationships between functions, options, and events.

**Use when**: You want to discover complementary features.

```
Ask: "What's related to columns option?"
Returns: Related API methods, options, and events grouped by category
```

## What's Included

- **1,206 searchable documents**: Manual pages, API reference, examples, extensions
- **Structured data**: 221 parameters, 1,276 code examples, 1,761 relationships
- **Pre-indexed SQLite database**: Ready to use, no indexing needed
- **Full-text search**: Fast FTS5 search engine with structured parsing
- **Incremental updates**: Re-index only new content with `vendor/bin/datatables-mcp index`

## Troubleshooting

**MCP not appearing**: Use absolute paths, restart AI client completely

**No results**: Check database exists: `ls -lh vendor/brownrl/datatables-mcp/data/datatables.db`

**Database missing**: Clone repo or check Composer installed correctly

## Documentation

- **INSTALL.md**: Detailed installation guide
- **GUIDE.md**: Complete technical documentation
- **AGENTS.md**: Development guide for AI agents

## License

MIT License - see [LICENSE](LICENSE) file
