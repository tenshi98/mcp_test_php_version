# Mantenimiento

## Actualizaciones
```bash
git pull && composer install --no-dev --optimize-autoloader
php bin/console db:install --no-seed   # NO usar --fresh en producción; los scripts usan CREATE TABLE IF NOT EXISTS
vendor/bin/phpunit --testsuite unit
```
Los cambios de esquema futuros deben entregarse como scripts `ALTER` numerados en `database/` (`06_…`). `mcp/sdk` es 0.x: lea su CHANGELOG antes de subir de versión y ejecute las suites completas.

## Respaldos
```bash
mysqldump --single-transaction --routines mcp_server | gzip > backup_$(date +%F).sql.gz   # restaurar: gunzip -c … | mysql mcp_server
```
Respalde además `.env` y `storage/keys/` (en un lugar cifrado): **perder las claves RSA invalida todos los access tokens; perder `APP_KEY` invalida los tokens personales; perder `OAUTH_ENCRYPTION_KEY` invalida refresh tokens y códigos**.

## Logs
- `storage/logs/app.log` (errores internos con traza), `storage/logs/security.log` y tabla `security_events` (auditoría). Rotación con logrotate:
  ```
  /ruta/storage/logs/*.log { weekly rotate 8 compress missingok notifempty create 0640 www-data www-data }
  ```
- Consultas útiles:
  ```sql
  SELECT created_at,event_type,user_id,details FROM security_events WHERE severity<>'info' ORDER BY id DESC LIMIT 50;
  SELECT event_type,COUNT(*) FROM security_events WHERE created_at>NOW()-INTERVAL 1 DAY GROUP BY 1;
  ```
- Alertas recomendadas: ráfagas de `token.rejected`, `oauth.login_denied`, `authorization.denied`, `record.deleted`.
- Claude Desktop guarda el stderr del servidor STDIO en su carpeta de logs (ver docs/AGENTS.md).

## Limpieza periódica (cron)
`0 3 * * * cd /ruta && php bin/console maintenance:purge --days=30 --events-days=365`

## Operaciones frecuentes
| Tarea | Comando |
|---|---|
| Rotar un token personal | `token:create …` → actualizar el cliente → `token:revoke <id>` |
| Bloquear a alguien ya | `user:status suspended --email=…` (invalida todo al instante) |
| Quitar un cliente OAuth | `oauth:client:revoke <client_id>` |
| Rotar claves RSA | `oauth:keys --force` (los usuarios deberán reconectar) |
| Cambiar rol | `UPDATE allowed_users SET role='editor' WHERE email='…'` (los scopes se recortan al refrescar) |

## Diagnóstico y solución de problemas
| Síntoma | Causa / solución |
|---|---|
| Cliente: "server disconnected" (STDIO) | Ejecute `php bin/mcp-server` a mano: falta `MCP_ACCESS_TOKEN`, token vencido/revocado, `APP_KEY` distinta a la que emitió el token, `php` fuera del PATH del cliente |
| `database_error` en herramientas | Revise `app.log` (el cliente no ve el detalle): credenciales, BD caída, `pdo_mysql` no instalado |
| HTTP 401 persistente | `WWW-Authenticate`/`security.log` (`oauth.access_token_rejected`, motivo `invalid_signature_audience_or_expired` o `revoked`); compruebe que `APP_URL` coincide exactamente con la URL usada por el cliente y que el reloj del servidor es correcto |
| HTTP 403 en `/mcp` | Anti DNS-rebinding: añada el host a `MCP_ALLOWED_HOSTS` (y el origen a `MCP_ALLOWED_ORIGINS`) |
| Bearer no llega tras Apache/Nginx | Reenvíe `Authorization` (ver INSTALLATION.md) |
| "Acceso denegado" al iniciar sesión | El correo del IdP no está en `allowed_users` (activo) o no está verificado: `oauth.login_denied` |
| `forbidden` en una herramienta | Rol/scopes insuficientes (`delete_record` = admin) |
| `invalid_grant` al refrescar | Refresh ya usado (rotación), revocado o expirado: reconectar |
| `Falta la clave publica OAuth` | `php bin/console oauth:keys` |
| Puerto 8787 ocupado | `HTTP_PORT=9000 docker compose --profile http up -d http` y actualice `APP_URL` |
