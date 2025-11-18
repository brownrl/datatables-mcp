# MCP Protocol Lifecycle Fix - Crush v0.18.2 Compatibility

## Problem Fixed
The datatables-mcp server was failing to load in Charm Crush v0.18.2 with error:
```
"Initialized mcp client","name":"datatables"
"error listing tools","error":"calling \"tools/list\": invalid request"
```

## Root Cause
The server was not implementing the MCP protocol lifecycle correctly:
1. **Missing protocol version update**: Used `2024-11-05` instead of `2025-06-18`
2. **No `initialized` notification handler**: Server didn't handle `notifications/initialized`
3. **No state tracking**: Server accepted `tools/list` immediately after `initialize` without waiting for the lifecycle notification

## Changes Made

### 1. Added State Tracking
```php
private bool $initialized = false;
```

### 2. Updated Protocol Version
Changed from `2024-11-05` to `2025-06-18` in `handleInitialize()`:
```php
'protocolVersion' => '2025-06-18'
```

### 3. Implemented Notification Handler
Added handling for `notifications/initialized`:
```php
private function handleInitializedNotification(): void
{
    $this->log("Client sent initialized notification - server is now ready");
    $this->initialized = true;
}
```

### 4. Added Lifecycle Validation
Modified `handleRequest()` to:
- Detect notifications (requests with no `id` field)
- Handle `notifications/initialized` notification
- Reject non-`initialize` requests before `initialized` notification received
- Return proper JSON-RPC error code `-32002` for premature requests

## MCP Protocol Lifecycle (Correct Flow)

```
Client                          Server
  |                               |
  |--- initialize request ------->|
  |<-- initialize response -------|
  |                               |
  |--- notifications/initialized->|  (notification, no response)
  |                               | (server sets initialized=true)
  |                               |
  |--- tools/list request ------->|
  |<-- tools/list response -------|
  |                               |
  |--- tools/call request ------->|
  |<-- tools/call response -------|
```

## Testing

### Manual Protocol Test
```bash
(
  echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"crush","version":"0.18.2"}}}'
  sleep 0.2
  echo '{"jsonrpc":"2.0","method":"notifications/initialized"}'
  sleep 0.2
  echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
  sleep 0.2
) | php bin/datatables-mcp serve
```

**Expected output:**
- `initialize` response with protocol version `2025-06-18`
- No response to `notifications/initialized` (it's a notification)
- `tools/list` response with search tool definition

### Automated Test Script
Run `./test-crush-protocol.sh` to verify the complete lifecycle.

## Verification in Crush

1. Add to `.crush.json` in your Laravel project:
```json
{
  "datatables": {
    "type": "stdio",
    "command": "php",
    "args": ["/absolute/path/to/datatables-mcp/bin/datatables-mcp", "serve"],
    "timeout": 120,
    "disabled": false
  }
}
```

2. Restart Crush

3. Check `.crush/logs/crush.log`:
```
✅ Should see: "Initialized mcp client","name":"datatables"
✅ Should NOT see: "error listing tools"
```

## Protocol Compliance

The fix ensures compliance with:
- **MCP Specification v2025-06-18**: https://modelcontextprotocol.io/specification/2025-06-18/basic/lifecycle
- **JSON-RPC 2.0**: https://www.jsonrpc.org/specification

## Files Modified

1. **src/McpServer.php**:
   - Added `private bool $initialized = false;` property
   - Updated protocol version to `2025-06-18`
   - Added `handleInitializedNotification()` method
   - Modified `handleRequest()` to detect notifications and validate state
   
2. **AGENTS.md**:
   - Updated with lifecycle flow documentation
   - Added state tracking information

3. **test-crush-protocol.sh** (new):
   - Test script that mimics Crush protocol flow

## Backward Compatibility

✅ All existing functionality preserved:
- `search` command works: `php bin/datatables-mcp search "ajax"`
- Manual JSON-RPC tests work
- Database indexing works

## Summary

The server now correctly implements the MCP protocol lifecycle by:
1. Waiting for `notifications/initialized` before accepting requests
2. Using the correct protocol version (`2025-06-18`)
3. Properly handling notifications (no response)
4. Rejecting premature requests with appropriate error codes

This ensures compatibility with Crush v0.18.2 and adherence to the MCP specification.
