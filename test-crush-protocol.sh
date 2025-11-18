#!/bin/bash
# Test script that mimics Crush v0.18.2 MCP protocol flow
# This should work without any errors now that lifecycle is implemented

echo "Testing MCP Protocol Lifecycle (Crush v0.18.2 compatible)"
echo "==========================================================="
echo ""

# Run protocol test
(
  echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"crush","version":"0.18.2"}}}'
  sleep 0.1
  echo '{"jsonrpc":"2.0","method":"notifications/initialized"}'
  sleep 0.1
  echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
  sleep 0.1
  echo '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search_datatables","arguments":{"query":"ajax","limit":1}}}'
  sleep 0.1
) | php bin/datatables-mcp serve 2> /tmp/mcp-test.log

echo ""
echo "Server Log Output:"
echo "=================="
cat /tmp/mcp-test.log

echo ""
echo "If you see initialize -> initialized notification -> tools/list -> search"
echo "without any errors, the protocol is working correctly!"
