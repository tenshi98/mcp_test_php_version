# Integración con agentes y clientes MCP

## STDIO vs Streamable HTTP

| | STDIO | Streamable HTTP |
|---|---|---|
| Quién ejecuta el servidor | El cliente lanza `php bin/mcp-server` como subproceso local | Servidor independiente (`/mcp`) accesible por red |
| Autenticación | Token personal en `MCP_ACCESS_TOKEN` (la especificación indica que STDIO toma credenciales del entorno; no usa OAuth) | **OAuth 2.1**: el cliente descubre, registra, autoriza (PKCE) y envía `Authorization: Bearer` |
| Identidad | Un usuario fijo por proceso (revalidado en cada llamada) | El usuario humano que autoriza (vía IdP externo) |
| Requisitos | PHP + acceso a la BD en la máquina del cliente | HTTPS público, IdP configurado |
| Ideal para | Desarrollo y uso personal | Equipos, conectores remotos |

Primero cree el usuario y el token (STDIO): `php bin/console token:create --email=usted@empresa.cl --days=30` → copie `MCP_ACCESS_TOKEN=…` (se muestra una vez).

> Los archivos de `examples/` están listos para copiar; reemplace `/RUTA/ABSOLUTA/…`, `APP_KEY`, `DB_PASSWORD` y el token (no se incluyen credenciales reales). Si el binario `php` no está en el PATH del cliente, use su ruta completa (`which php`).

## Claude Desktop

**STDIO** — editar `claude_desktop_config.json` (Linux `~/.config/Claude/`, macOS `~/Library/Application Support/Claude/`, Windows `%APPDATA%\Claude\`; también Ajustes → Developer → Edit Config) con [`examples/claude_desktop_config.stdio.json`](../examples/claude_desktop_config.stdio.json):

```json
{ "mcpServers": { "php-crud": {
  "command": "php",
  "args": ["/RUTA/ABSOLUTA/mcp_test_php_version/bin/mcp-server"],
  "env": { "APP_KEY": "…", "DB_HOST": "127.0.0.1", "DB_NAME": "mcp_server", "DB_USER": "mcp_user", "DB_PASSWORD": "…", "MCP_ACCESS_TOKEN": "mcp_…" } } } }
```
Reinicie Claude Desktop por completo. **Verificar**: icono de conectores del cuadro de entrada → "Manage connectors" → `php-crud` debe listar 6 herramientas. Logs: `~/.config/Claude/logs/mcp-server-php-crud.log` (stderr del servidor).

**HTTP** — con la UI de conectores personalizados (Ajustes → Connectors → añadir por URL `https://mcp.tudominio.example/mcp`; requiere HTTPS accesible) Claude hace el flujo OAuth por usted. Alternativa por puente local: [`examples/claude_desktop_config.http-bridge.json`](../examples/claude_desktop_config.http-bridge.json) (`npx -y mcp-remote <url>`; abre el navegador para autorizar).

## OpenCode

Archivo `opencode.json` (proyecto) o `~/.config/opencode/opencode.json`. Según la documentación de OpenCode los servidores van en `mcp.servers`; **compruebe el esquema de su versión** (`$schema`: https://opencode.ai/config.json).

**STDIO** — [`examples/opencode.stdio.json`](../examples/opencode.stdio.json): `type: "local"`, `command` como array, `environment` (admite `{env:NOMBRE}` para no escribir secretos en el archivo; exporte antes `MCP_ACCESS_TOKEN`, `MCP_APP_KEY`, `MCP_DB_PASSWORD`).

**HTTP + OAuth** — [`examples/opencode.http-oauth.json`](../examples/opencode.http-oauth.json): `type: "remote"`, `url`. OAuth es el valor por defecto; autorice con:
```bash
opencode mcp auth php-crud-http
opencode mcp list          # estado de conexión
```
Con cliente pre-registrado (`php bin/console oauth:client:create "OpenCode" --redirect-uri=http://127.0.0.1:19876/callback`): `"oauth": {"client_id": "…", "callback_port": 19876, "redirect_uri": "http://127.0.0.1:19876/callback"}`.

## Otros clientes

| Cliente | Archivo / comando | Ejemplo |
|---|---|---|
| Claude Code | `claude mcp add …` | [`examples/claude-code.sh`](../examples/claude-code.sh); verificar con `/mcp` |
| VS Code (Copilot) | `.vscode/mcp.json` | [`examples/vscode.mcp.json`](../examples/vscode.mcp.json) |
| Cursor | `.cursor/mcp.json` | [`examples/cursor.mcp.json`](../examples/cursor.mcp.json) |
| MCP Inspector | `npx @modelcontextprotocol/inspector php bin/mcp-server` | [`examples/inspector.sh`](../examples/inspector.sh) |

Cualquier cliente compatible con MCP funciona: STDIO (comando + env) o Streamable HTTP (URL + OAuth).

## Cliente HTTP sin OAuth (último recurso)

`MCP_HTTP_ALLOW_PERSONAL_TOKENS=true` permite `Authorization: Bearer mcp_…` con un token personal. **No es OAuth 2.1**; úselo solo en redes de confianza y con HTTPS.

## Verificación de herramientas

Debe ver exactamente: `list_records`, `get_record`, `search_records`, `create_record`, `update_record`, `delete_record`. Por línea de comandos (STDIO):
```bash
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}' \
 '{"jsonrpc":"2.0","method":"notifications/initialized"}' '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
 | MCP_ACCESS_TOKEN=mcp_… php bin/mcp-server
```

## Ejemplos de interacción

> "Lista los 5 registros más caros de la categoría tecnología" → `search_records {category:"tecnologia", sort_by:"price", sort_dir:"desc", per_page:5}`
> "Crea un registro para Ana Soto…" → `create_record`. "Cambia la cantidad del 31 a 7" → `update_record`. "Elimina el 31" → `delete_record` (pide confirmación; el agente debe repetir con `confirm:true` tras su aprobación).
