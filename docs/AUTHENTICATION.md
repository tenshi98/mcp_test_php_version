# Autenticación, autorización y seguridad

## Usuarios permitidos y roles

`allowed_users` decide **quién** puede entrar. Estado `active` obligatorio (`suspended`/`disabled` bloquean tokens existentes de inmediato).

| Rol | Permisos |
|---|---|
| `viewer` | `records:read` |
| `editor` | + `records:write` |
| `admin` | + `records:delete` |

**Permisos efectivos = permisos del rol ∩ scopes del token.** Un token nunca eleva el rol y un admin con un token `records:read` solo lee. Administración: `user:create`, `user:list`, `user:status active|suspended|disabled --email=…` (al suspender revoca tokens personales y OAuth).

## Tokens personales (STDIO / automatización) — *no es OAuth*

- Formato `mcp_` + 256 bits aleatorios. En BD solo `HMAC-SHA256(token, APP_KEY)`; el valor se muestra una vez.
- Vigencia obligatoria (`--days`, máx. `TOKEN_MAX_TTL_DAYS`), `scopes` opcionales, `last_used_at`.
- Validación en cada llamada: formato → hash → estado (`active`/`revoked`) → expiración → usuario activo. Mensaje de error idéntico para todos los motivos (el motivo real va solo al log de seguridad).
- `token:create`, `token:list`, `token:revoke <id>`, `maintenance:purge`.

## OAuth 2.1 (transporte HTTP)

Roles según la especificación MCP: el servidor MCP es *resource server*; el servidor de autorización es el embebido (`league/oauth2-server`). La **identidad** del humano la prueba un IdP externo.

Cumple/implementa:
- **PKCE S256 obligatorio** para todos los clientes (sin PKCE o con `plain` → error). Solo `authorization_code` y `refresh_token`; sin implicit ni password.
- **Refresh tokens con rotación**: reutilizar uno viejo falla.
- **Audiencia (RFC 8707)**: el JWT lleva `aud = APP_URL/mcp`; un `resource` distinto se rechaza (`invalid_target`); tokens para otro recurso no son aceptados.
- **Validación del Bearer**: firma RS256, `iss`, `aud`, `exp/nbf/iat`, no revocado (tabla `oauth_access_tokens`), usuario activo. 401 con `WWW-Authenticate: Bearer resource_metadata="…"` (RFC 9728/6750).
- **Metadatos**: `/.well-known/oauth-protected-resource/mcp` y `/.well-known/oauth-authorization-server`.
- **Registro de clientes (RFC 7591)** en `POST /oauth/register`: redirect URIs solo `https`, `http` en loopback o esquemas privados de apps nativas; sin fragmentos; sin `javascript:`/`file:`…; tope por hora. También manual: `oauth:client:create`.
- **Redirect URI exacta** (con puerto libre en loopback, RFC 8252), `state` devuelto, consentimiento con CSRF, cookie de sesión `HttpOnly`/`SameSite=Lax`/`Secure` (HTTPS).
- **Scopes recortados por rol** al emitir y al refrescar.
- **Revocación (RFC 7009)** `POST /oauth/revoke` (access o refresh; revoca también el par). Administrativa: `oauth:revoke-user`, `oauth:client:revoke`, `user:status`.
- Access token 1 h, refresh 30 d, código 10 min y de un solo uso (configurable).

### Flujo y vinculación de usuarios externos
1. El cliente llega a `/oauth/authorize`; se valida y se redirige al IdP (con PKCE propio hacia el IdP).
2. En `/oauth/callback`, `IdentityLinkService`: identidad ya vinculada (`oauth_users`, clave `proveedor+sub`) → entra; nueva → **solo si el IdP verificó el correo y ese correo está en `allowed_users` activo**, entonces se crea el vínculo; en otro caso, acceso denegado (mensaje genérico, evento `oauth.login_denied`).
3. Pantalla de consentimiento → código → `/oauth/token`.
Desvincular: `oauth:revoke-user --email=…` (revoca identidades y tokens; el usuario no puede volver a entrar hasta que un administrador lo restablezca).

### Configurar un IdP
Google: consola de Google Cloud → credenciales OAuth «aplicación web», redirect `https://<host>/oauth/callback`; `OAUTH_IDP=google`, `OAUTH_IDP_CLIENT_ID/SECRET`. Keycloak/Auth0/Okta: `OAUTH_IDP=generic` + URLs. **GitHub** no entrega `email_verified` en `/user`: use un IdP OIDC o implemente `IdentityProviderInterface`.

### Servidor de autorización externo
Puede delegar todo en Keycloak/Auth0/Entra: use `JwtTokenValidator` del SDK, publique el emisor externo en `authorization_servers` y desactive `/oauth/*` (`OAUTH_DCR_ENABLED=false`). El mapeo `sub` → `allowed_users.id` deberá adaptarse en `HttpPrincipalResolver`.

## Seguridad de la base de datos y de la aplicación

- Solo PDO con **consultas preparadas nativas** (`EMULATE_PREPARES=false`); identificadores (columnas/orden) solo desde lista blanca; `LIKE` con comodines escapados; `LIMIT/OFFSET` enteros acotados.
- Validación por tipo, rango, longitud, formato y campos desconocidos/solo lectura rechazados.
- Errores: el cliente solo recibe mensajes seguros; la excepción original (con SQL/host/usuario) se escribe en el log. No se imprime nada en stdout (STDIO).
- Auditoría en `security_events` + `storage/logs/security.log`: login, vínculos, denegaciones, tokens emitidos/rechazados/revocados, escrituras y borrados. Los valores con aspecto de secreto se redactan.
- HTTP: anti DNS-rebinding (`Host`/`Origin`), CORS restringido, cabeceras `nosniff`/HSTS, límite de tamaño de cuerpo, `Cache-Control: no-store` en tokens.
- Recomendado: usuario de BD con privilegios mínimos, `.env` y claves con permisos `600`, HTTPS con proxy confiable, `display_errors=Off`.
