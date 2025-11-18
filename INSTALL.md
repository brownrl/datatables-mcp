# Installation Guide

## Quick Install

### Method 1: One-Line Install
```bash
composer config repositories.datatables-mcp vcs https://github.com/brownrl/datatables-mcp && composer require brownrl/datatables-mcp:^1.0
```

### Method 2: Add to composer.json
Edit your `composer.json`:

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

Then run:
```bash
composer install
```

## Version Options

**Stable Release (Recommended)**
```bash
composer require brownrl/datatables-mcp:^1.0
```

**Latest Development**
```bash
composer require brownrl/datatables-mcp:dev-main
```

**Specific Version**
```bash
composer require brownrl/datatables-mcp:v1.0.0
```

## Configure AI Client

### Claude Desktop

After installation, add to Claude Desktop config:

**macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

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

**Example**: If your project is at `/Users/john/my-app`:
```json
{
  "mcpServers": {
    "datatables": {
      "type": "stdio",
      "command": "/Users/john/my-app/vendor/bin/datatables-mcp",
      "args": ["serve"]
    }
  }
}
```

Restart Claude Desktop after saving.

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

## Test Installation

```bash
# View statistics
vendor/bin/datatables-mcp stats

# Search documentation
vendor/bin/datatables-mcp search "ajax server-side"

# Test MCP server (Ctrl+C to stop)
vendor/bin/datatables-mcp serve
```

## Updating

**Update to latest stable:**
```bash
composer update brownrl/datatables-mcp
```

**Switch to development version:**
```bash
composer require brownrl/datatables-mcp:dev-main
```

## Troubleshooting

### Database not found
If you see "Database not found", ensure the package was fully extracted:
```bash
ls -la vendor/brownrl/datatables-mcp/data/
```

You should see `datatables.db` (~4.4MB).

### Permission errors
Ensure the binary is executable:
```bash
chmod +x vendor/bin/datatables-mcp
```

### FTS5 not available
Check if SQLite FTS5 is enabled:
```bash
php -r "echo extension_loaded('pdo_sqlite') ? 'SQLite OK' : 'SQLite missing';"
```

If missing, install/enable the `pdo_sqlite` PHP extension.

## Why GitHub instead of Packagist?

This package is distributed directly from GitHub to avoid potential issues with:
- Corporate firewalls blocking packagist.org
- Private network restrictions
- Packagist downtime
- Additional third-party dependencies

GitHub VCS repositories work anywhere Git works, making this more accessible in restricted environments.
