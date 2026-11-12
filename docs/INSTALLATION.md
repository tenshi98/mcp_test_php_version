# Instalación

## Con Docker (recomendado, reproducible)

```bash
docker compose build app
docker compose up -d db
docker compose run --rm app composer install
cp .env.example .env
docker compose run --rm app php bin/console app:generate-key      # copie APP_KEY y OAUTH_ENCRYPTION_KEY a .env
# en .env con Docker: DB_HOST=db  DB_USER=mcp_user  DB_PASSWORD=mcp_dev_password  DB_NAME=mcp_server
docker compose run --rm app php bin/console db:install
docker compose run --rm app php bin/console oauth:keys            # solo si usará HTTP/OAuth
docker compose --profile http up -d http                          # HTTP en http://localhost:8787
```

Si su usuario no es UID 1000: `export DOCKER_UID=$(id -u) DOCKER_GID=$(id -g)`. Puerto HTTP: `HTTP_PORT=...`.

## Instalación nativa

1. **PHP 8.3+** con extensiones `pdo_mysql openssl mbstring curl`. Ubuntu/Debian: `sudo apt install php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl php8.3-xml unzip`. macOS: `brew install php`. Windows: php.net + activar extensiones en `php.ini`.
2. **Composer**: https://getcomposer.org/download/ (`composer --version`).
3. **Dependencias**: `composer install --no-dev` (producción) o `composer install` (con PHPUnit).
4. **MariaDB/MySQL**: instale el servidor y cree base y usuario:
   ```sql
   CREATE DATABASE mcp_server CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'mcp_user'@'localhost' IDENTIFIED BY 'una-clave-fuerte';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON mcp_server.* TO 'mcp_user'@'localhost';
   ```
   (Tras instalar, en producción puede reducir a `SELECT, INSERT, UPDATE, DELETE`.)
5. **Configuración**: `cp .env.example .env`; complete `DB_*`, `APP_KEY` y `OAUTH_ENCRYPTION_KEY` (`php bin/console app:generate-key`). `chmod 600 .env`.
6. **Base de datos**: `php bin/console db:install` (ejecuta `database/01..05_*.sql`) — o manualmente `mysql mcp_server < database/01_create_tables.sql` y así sucesivamente. Opciones: `--no-seed` (sin datos), `--fresh` (borra todo antes).
7. **Usuarios y token**: edite `database/04_seed_users.sql` o use `php bin/console user:create persona@empresa.cl --role=admin --name="Persona"`; luego `php bin/console token:create --email=persona@empresa.cl --days=30`.
8. **Ejecutar**:
   - STDIO: `MCP_ACCESS_TOKEN=mcp_... php bin/mcp-server` (lo lanza normalmente el cliente MCP).
   - HTTP (desarrollo): `php bin/console oauth:keys` y `php -S 127.0.0.1:8787 -t public public/index.php`.
   - HTTP (producción): Apache/Nginx + PHP-FPM con DocumentRoot en `public/`, **HTTPS**, y reescritura de todo a `index.php` (Nginx: `try_files $uri /index.php$is_args$args;`). Conserve la cabecera `Authorization` (Apache: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
9. **Verificación**: `php bin/console user:list`; para ver las herramientas: `npx @modelcontextprotocol/inspector php bin/mcp-server`.

## Verificar la instalación

```bash
curl -s localhost:8787/health                                  # {"status":"ok"}
curl -s localhost:8787/.well-known/oauth-protected-resource/mcp
vendor/bin/phpunit --testsuite unit
```
