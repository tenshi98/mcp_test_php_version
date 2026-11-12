# Pruebas

PHPUnit 11. Dos suites separadas (`phpunit.xml.dist`):

| Suite | Contenido | Requisitos |
|---|---|---|
| `unit` (99 pruebas, ~1 s) | Validadores, criterios de búsqueda, roles/scopes, tokens (emisión, expiración, revocación, no-almacenamiento en claro) con repositorios en memoria y reloj congelado, `ToolInvoker` (mapeo y no filtración de errores), registro y esquemas de herramientas, validador JWT (firma, `iss`, `aud`, expirado, `alg:none`, manipulado, revocado, usuario inactivo), configuración | Solo PHP |
| `integration` (131 pruebas, ~1.5 min) | Esquema y datos (30 registros, FKs, cascadas), CRUD y búsquedas reales, inyección SQL, tokens contra BD, vinculación de identidades, permisos de herramientas, caída de BD sin fugas, **proceso STDIO real** (initialize, tools/list, ciclo CRUD, revocación y expiración en caliente), **servidor HTTP real** con flujo OAuth completo (metadatos, DCR, PKCE, replay de código, refresh con rotación, revocación, DNS rebinding, consentimiento/CSRF, usuarios no autorizados) | MariaDB/MySQL; `pdo_mysql`, `curl` |

## Ejecutar

Con Docker (todo incluido):
```bash
docker compose up -d db
docker compose run --rm app vendor/bin/phpunit --testsuite unit
docker compose run --rm app vendor/bin/phpunit --testsuite integration
docker compose run --rm app vendor/bin/phpunit            # todo
docker compose run --rm app vendor/bin/phpunit --filter HttpOAuthTest
```
Sin Docker: `composer install`, y para integración exporte un usuario con permiso de crear bases:
```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 TEST_DB_NAME=mcp_server_test TEST_DB_ADMIN_USER=root TEST_DB_ADMIN_PASSWORD=...
vendor/bin/phpunit
```
`composer test` (unit), `composer test:integration`, `composer test:all`.

Las pruebas de integración **crean `TEST_DB_NAME` y reinstalan el esquema antes de cada test**: nunca apunte `TEST_DB_NAME` a una base con datos reales. Si no hay BD disponible se omiten (SKIPPED), no fallan. No usan `.env`; generan sus propias claves RSA y levantan `php -S` en un puerto libre.

## Validación de resultados
Resultado esperado: `OK (N tests, … assertions)` sin *warnings* ni *risky* (`failOnWarning/failOnRisky` activos). Se puede añadir cobertura con Xdebug/PCOV: `--coverage-text`.

## Añadir pruebas
Unitarias en `tests/Unit` (use `tests/Support/InMemoryRepositories` y `FrozenClock`); de integración extendiendo `Tests\Support\DatabaseTestCase`.
