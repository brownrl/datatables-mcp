# Quick Start Guide

Get the DataTables MCP server running in 5 minutes.

## Step 1: Install Dependencies

```bash
composer install
```

## Step 2: Index Documentation

```bash
composer run index
```

This scrapes DataTables.net and creates a searchable database. Takes ~10 seconds.

## Step 3: Test Search

```bash
php bin/datatables-mcp search "ajax options"
```

You should see search results from the DataTables documentation.

## Step 4: Test MCP Protocol

```bash
./test_mcp.sh
```

This sends JSON-RPC requests to the server and shows responses.

## Step 5: Configure Claude Desktop

1. Find your Claude config file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
   - **Linux**: `~/.config/Claude/claude_desktop_config.json`

2. Add this configuration (replace `/ABSOLUTE/PATH/TO` with your actual path):

```json
{
  "mcpServers": {
    "datatables": {
      "command": "php",
      "args": [
        "/ABSOLUTE/PATH/TO/datatables-mcp/bin/datatables-mcp",
        "serve"
      ]
    }
  }
}
```

3. **Restart Claude Desktop completely** (quit and reopen)

## Step 6: Use in Claude

Ask Claude:
> "Search for information about DataTables ajax options"

Claude will automatically use the `search_datatables` tool to find relevant documentation!

## What You Can Search For

Try these queries:
- "ajax options"
- "server-side processing"
- "column rendering"
- "event handling"
- "styling bootstrap"
- "react component"
- "sorting and ordering"

## Commands Reference

```bash
# Index documentation
composer run index
php bin/datatables-mcp index

# Run MCP server
composer run serve
php bin/datatables-mcp serve

# Test search
php bin/datatables-mcp search "query"

# Show stats
php bin/datatables-mcp stats

# Test MCP protocol
./test_mcp.sh

# Show help
php bin/datatables-mcp help
```

## Troubleshooting

### "Database not found"

Run the indexer first:
```bash
composer run index
```

### Claude Desktop not working

1. Check config file path is correct
2. Use **absolute paths** (not relative)
3. Restart Claude Desktop completely
4. Check Claude's logs (in same directory as config)

### No search results

Check database has content:
```bash
php bin/datatables-mcp stats
```

Should show ~26 documents. If 0, re-run indexer.

## Next Steps

- Read **GUIDE.md** for detailed technical explanation
- Read **AGENTS.md** for development reference
- Check **README.md** for architecture details

## Need Help?

The code is heavily commented. Start with:
1. `src/McpServer.php` - See how protocol works
2. `src/SearchEngine.php` - See how search works
3. `src/DocumentationIndexer.php` - See how scraping works
