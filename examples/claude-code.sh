#!/usr/bin/env bash
# Claude Code (CLI). Ajuste las rutas y exporte las variables antes de ejecutar.
set -euo pipefail

# STDIO
claude mcp add php-crud \
  -e APP_KEY="$MCP_APP_KEY" -e DB_NAME=mcp_server -e DB_USER=mcp_user -e DB_PASSWORD="$MCP_DB_PASSWORD" \
  -e MCP_ACCESS_TOKEN="$MCP_ACCESS_TOKEN" \
  -- php /RUTA/ABSOLUTA/mcp_test_php_version/bin/mcp-server

# Streamable HTTP con OAuth (luego, dentro de Claude Code: /mcp -> php-crud-http -> Authenticate)
claude mcp add --transport http php-crud-http https://mcp.tudominio.example/mcp
