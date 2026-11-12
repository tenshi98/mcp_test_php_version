# Herramientas MCP

Todas operan sobre `sample_records`. Los argumentos se validan primero contra el JSON Schema (`additionalProperties: false`; un error devuelve JSON-RPC `-32602`) y después con reglas de negocio (error de herramienta: `isError: true`).

Columnas: `id` (int, auto), `first_name`, `last_name` (texto ≤80), `email` (único, ≤190), `birth_date`, `event_date` (fecha `YYYY-MM-DD`, opcionales), `title` (≤150), `short_text` (≤255, opc.), `description`, `notes` (texto largo, opc.), `quantity` (int, def. 0), `price` (decimal 2, ≥0, opc.), `rating` (decimal 0–99.99, opc.), `is_active` (bool, def. true), `category` (≤50), `status` (`draft|active|archived`, def. `draft`), `created_at`, `updated_at` (automáticas, solo lectura).

Respuesta correcta: `content[0].text` (JSON) **y** `structuredContent` con el mismo objeto.

| Herramienta | Permiso | Rol mínimo |
|---|---|---|
| `list_records`, `get_record`, `search_records` | `records:read` | viewer |
| `create_record`, `update_record` | `records:write` | editor |
| `delete_record` | `records:delete` | admin |

## list_records
`page` (≥1), `per_page` (1–100, def. 20), `sort_by` (cualquier columna), `sort_dir` (`asc|desc`), `fields` (lista de columnas).
```json
{"name":"list_records","arguments":{"per_page":2,"sort_by":"price","sort_dir":"desc","fields":["id","title","price"]}}
```
```json
{"records":[{"id":14,"title":"Ampliacion de patio","price":78000000.0},{"id":6,"title":"Migracion a nuevo TMS","price":45000000.0}],
 "pagination":{"page":1,"per_page":2,"total":30,"total_pages":15,"has_next":true}}
```

## get_record
`id` (obligatorio), `fields`. → `{"record":{...}}`.

## search_records
Los filtros se combinan con **AND**; admite también `page/per_page/sort_by/sort_dir/fields`.

| Parámetro | Efecto |
|---|---|
| `text` | LIKE sin distinguir mayúsculas en nombre, apellido, correo, título, texto corto, descripción y observaciones (los comodines `%`/`_` se buscan literalmente) |
| `name` | nombre, apellido o "nombre apellido" |
| `category`, `status`, `is_active` | igualdad |
| `min_quantity/max_quantity`, `min_price/max_price`, `min_rating/max_rating` | rangos inclusivos |
| `date_field` (`birth_date`, `event_date`, `created_at`, `updated_at`; def. `event_date`) + `date_from`/`date_to` | rango de fechas inclusivo (día completo para timestamps) |
| `null_fields` / `not_null_fields` | columnas opcionales que deben ser / no ser NULL |

```json
{"name":"search_records","arguments":{"category":"flota","status":"active","min_price":100000,"date_from":"2025-01-01","date_to":"2025-12-31"}}
```

## create_record
Obligatorios: `first_name`, `last_name`, `email`, `title`, `category`. Devuelve el registro creado (con `id`, `created_at`, `updated_at`).
```json
{"name":"create_record","arguments":{"first_name":"Ana","last_name":"Soto","email":"ana@example.test","title":"Nuevo","category":"otros","price":10.5}}
```

## update_record
`id` + uno o más campos (actualización parcial; los no enviados no cambian; un campo opcional puede ponerse en `null`). `updated_at` se actualiza solo. Devuelve el registro completo.
```json
{"name":"update_record","arguments":{"id":31,"quantity":7,"notes":null}}
```

## delete_record
`id`, `confirm` (def. `false`). **Sin `confirm: true` no se borra nada**; devuelve una vista previa:
```json
{"deleted":false,"confirmation_required":true,"message":"No se elimino nada. Para borrar el registro 31 vuelva a llamar con confirm=true.","record":{...}}
```
Con `confirm: true`: `{"deleted":true,"id":31,"record":{...}}`. El borrado queda auditado (`record.deleted`).

## Errores

Error de herramienta (`result.isError = true`), texto JSON `{"error":{"code","message","details?"}}`:

| code | Significado |
|---|---|
| `validation_error` | Valores inválidos; `details.errors` = `{campo: mensaje}` |
| `not_found` | El `id` no existe |
| `conflict` | Valor único duplicado (correo) |
| `forbidden` | Falta el permiso/scope |
| `authentication_failed` | Token inválido, expirado, revocado o usuario no activo |
| `database_error` | Fallo de BD (mensaje genérico; el detalle solo está en el log) |
| `internal_error` | Error inesperado (genérico) |

Error de protocolo (`error.code = -32602`): argumentos que incumplen el esquema (tipo, formato, desconocidos, faltantes).
