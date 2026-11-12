# MCP PHP CRUD Server

Servidor **MCP (Model Context Protocol)** en **PHP 8.3** que permite a agentes de IA (Claude Desktop, OpenCode, Claude Code, Cursor, VS Code…) hacer **CRUD completo** sobre una base de datos **MariaDB/MySQL**, con autenticación por tokens, autorización por roles/scopes y **OAuth 2.1** para el transporte HTTP.

Implementación de referencia: usa el SDK oficial [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) (no hay protocolo propio ni endpoints simulados).

## Características

- Transportes **STDIO** y **Streamable HTTP** (SDK oficial; negociación de capacidades, `tools/list`, `tools/call`, validación JSON Schema).
- 6 herramientas: `list_records`, `get_record`, `search_records`, `create_record`, `update_record`, `delete_record` (paginación, orden, selección de campos, filtros por texto/fechas/números/estado/NULL).
- **Seguridad**: PDO con consultas preparadas, lista blanca de columnas, validación estricta, borrado en dos pasos (`confirm=true`), errores sin datos internos, eventos de seguridad en BD y log.
- **Autenticación**: tokens personales (solo se guarda su HMAC-SHA256) con expiración y revocación — para STDIO. *No son OAuth.*
- **OAuth 2.1 (HTTP)**: servidor MCP como *resource server* + servidor de autorización embebido (`league/oauth2-server`): authorization code + **PKCE S256 obligatorio**, refresh con rotación, registro dinámico de clientes (RFC 7591), metadatos RFC 8414 y RFC 9728, indicador de recurso (RFC 8707, audiencia del token = `/mcp`), revocación (RFC 7009). La identidad del usuario la aporta un proveedor externo (Google, Microsoft, Keycloak… o `dev` solo en desarrollo) y se **vincula** a la lista de usuarios permitidos.
- **Extensible**: una herramienta nueva = una clase + una línea en `config/tools.php`; otra tabla = otra `EntityDefinition`.
- **Pruebas**: 99 unitarias + 131 de integración (MariaDB real, proceso STDIO real, servidor HTTP real con flujo OAuth completo).

## Requisitos

PHP ≥ 8.3 (`pdo_mysql`, `openssl`, `mbstring`, `curl`), Composer 2, MariaDB 10.6+/MySQL 8+. Alternativa sin instalar nada: Docker (incluido `docker-compose.yml`).

## Inicio rápido (Docker)

```bash
docker compose build app && docker compose up -d db
docker compose run --rm app composer install
cp .env.example .env            # edite APP_KEY y OAUTH_ENCRYPTION_KEY:  docker compose run --rm app php bin/console app:generate-key
docker compose run --rm app php bin/console db:install          # tablas + 30 registros + usuarios demo
docker compose run --rm app php bin/console token:create --email=admin@example.com
docker compose run --rm app vendor/bin/phpunit                  # todas las pruebas
```

Instalación nativa (sin Docker): ver [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Documentación

| Documento | Contenido |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Arquitectura, componentes, flujos, diagramas |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Instalación desde cero |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Variables de entorno |
| [docs/MCP_TOOLS.md](docs/MCP_TOOLS.md) | Herramientas, parámetros, respuestas, errores |
| [docs/AGENTS.md](docs/AGENTS.md) | Claude Desktop, OpenCode y otros clientes (STDIO/HTTP) |
| [docs/AUTHENTICATION.md](docs/AUTHENTICATION.md) | Tokens, roles, OAuth 2.1, seguridad |
| [docs/USAGE.md](docs/USAGE.md) | Ejemplos de uso con agentes |
| [docs/TESTING.md](docs/TESTING.md) | Pruebas |
| [docs/MAINTENANCE.md](docs/MAINTENANCE.md) | Respaldos, logs, diagnóstico |

Configuraciones listas para adaptar: [`examples/`](examples/).

## Estructura

```
bin/            mcp-server (STDIO) · console (administración)
public/         index.php (HTTP: /mcp, /oauth/*, /.well-known/*)
src/            Core · Mcp · Tools · Database · Authentication · Authorization · OAuth · Tokens
                Repositories · Services · Validators · Domain · Exceptions · Logging · Http · Console
config/tools.php  registro de herramientas
database/       00..05_*.sql (BD, tablas, índices, relaciones, usuarios, datos de prueba)
tests/          Unit · Integration · Support
docs/ examples/
```

## Estado y límites

- El SDK `mcp/sdk` es 0.x: fije la versión en `composer.lock` y pruebe antes de actualizar.
- Verificado contra el protocolo con clientes JSON-RPC/HTTP propios; la conexión con aplicaciones concretas (Claude Desktop, OpenCode…) sigue sus documentaciones oficiales, ver [docs/AGENTS.md](docs/AGENTS.md).
- El proveedor `dev` es solo para desarrollo y se niega a arrancar con `APP_ENV=production`.
- En producción **HTTPS es obligatorio** para HTTP/OAuth.

Licencia: Apache-2.0 (ver `LICENSE`).
