# Configuración

Todo se configura con variables de entorno o `.env` (las reales tienen prioridad). Plantilla: [`.env.example`](../.env.example). **No suba `.env` al repositorio** (ya está en `.gitignore`).

| Variable | Defecto | Descripción |
|---|---|---|
| `APP_ENV` | `production` | `production` exige HTTPS en `APP_URL` y prohíbe `OAUTH_IDP=dev` |
| `APP_KEY` | — (obligatoria) | ≥ 32 caracteres. Clave del HMAC de tokens personales. **Si cambia, todos los tokens personales dejan de ser válidos** |
| `APP_URL` | `http://localhost:8787` | URL pública base. Emisor OAuth = `APP_URL`; recurso MCP = `APP_URL/mcp` |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASSWORD` | — | Conexión MySQL/MariaDB (usuario con privilegios mínimos) |
| `LOG_PATH` | `storage/logs` | `app.log` y `security.log` |
| `LOG_LEVEL` | `info` | debug/info/warning/error |
| `SESSION_PATH` | `storage/sessions` | Sesiones MCP HTTP y del flujo web OAuth |
| `MCP_ACCESS_TOKEN` | — | Token personal que usa `bin/mcp-server` (STDIO) |
| `TOKEN_MAX_TTL_DAYS` | `90` | Vigencia máxima de un token personal |
| `MCP_HTTP_ALLOW_PERSONAL_TOKENS` | `false` | Acepta tokens `mcp_…` también en HTTP. **No es OAuth**; úselo solo si un cliente no soporta OAuth |
| `MCP_ALLOWED_HOSTS` | `localhost,127.0.0.1,[::1]` | Hosts válidos (anti DNS-rebinding). Se añade automáticamente el host de `APP_URL` |
| `MCP_ALLOWED_ORIGINS` | vacío | Orígenes CORS/Origin permitidos (clientes en navegador) |
| `OAUTH_PRIVATE_KEY` / `OAUTH_PUBLIC_KEY` | `storage/keys/*.pem` | Par RSA (`php bin/console oauth:keys`). La privada: `chmod 600`, fuera del web root |
| `OAUTH_ENCRYPTION_KEY` | — (obligatoria en HTTP) | ≥ 32 caracteres; cifra códigos y refresh tokens |
| `OAUTH_ACCESS_TOKEN_TTL` | `3600` | segundos |
| `OAUTH_REFRESH_TOKEN_TTL` | `2592000` | 30 días |
| `OAUTH_AUTH_CODE_TTL` | `600` | segundos |
| `OAUTH_DCR_ENABLED` / `OAUTH_DCR_MAX_PER_HOUR` | `true` / `60` | Registro dinámico de clientes y tope por hora (HTTP 429 al superarlo) |
| `OAUTH_IDP` | `google` | `google`, `microsoft`, `generic`, `dev` |
| `OAUTH_IDP_CLIENT_ID` / `_SECRET` | — | Credenciales de **este servidor** como cliente del IdP |
| `OAUTH_IDP_AUTHORIZE_URL` `_TOKEN_URL` `_USERINFO_URL` `_SCOPES` `_NAME` | — | Solo `generic` (Keycloak, Auth0, Okta…) |

## Credenciales y secretos

- `.env` con permisos `600`; en contenedores use secretos/variables del orquestador.
- Los tokens personales y de OAuth **nunca** se guardan en claro (hash HMAC / JWT firmado + `jti`); los secretos de clientes confidenciales, con `password_hash`.
- Los eventos de seguridad redactan automáticamente claves como `token`, `secret`, `password`.

## Transporte MCP

- **STDIO**: `php bin/mcp-server`; stdout es el canal del protocolo (todo log va a archivo/stderr).
- **Streamable HTTP**: `POST /mcp` (JSON-RPC), sesiones con cabecera `Mcp-Session-Id`, autenticación `Authorization: Bearer`.

## Registrar la aplicación en el IdP

URI de redirección autorizada en Google/Microsoft/Keycloak: `{APP_URL}/oauth/callback`. Alcances solicitados: `openid email profile`. El proveedor debe devolver `sub`, `email` y `email_verified` (Google lo hace; para Microsoft el correo se considera verificado).
