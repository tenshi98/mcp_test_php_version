# Uso

Las llamadas siguientes son las que el agente envía como `tools/call`; usted normalmente solo escribe lenguaje natural.

## Consultas
| Petición al agente | Herramienta |
|---|---|
| "Muéstrame los primeros registros" | `list_records {"per_page":10}` |
| "Página 2, ordenados por precio descendente, solo id, título y precio" | `list_records {"page":2,"sort_by":"price","sort_dir":"desc","fields":["id","title","price"]}` |
| "El registro 5" | `get_record {"id":5}` |
| "Busca 'flota'" | `search_records {"text":"flota"}` |
| "Personas llamadas Camila Rojas" | `search_records {"name":"camila rojas"}` |
| "Activos de tecnología entre 1 y 20 millones" | `search_records {"category":"tecnologia","status":"active","min_price":1000000,"max_price":20000000}` |
| "Eventos del 1 de marzo al 30 de abril de 2025" | `search_records {"date_from":"2025-03-01","date_to":"2025-04-30"}` |
| "Registros sin descripción" | `search_records {"null_fields":["description"]}` |
| "Inactivos" | `search_records {"is_active":false}` |

## Inserciones
"Crea un registro para Ana Soto (ana@example.test), título 'Auditoría', categoría calidad, precio 1500.50":
`create_record {"first_name":"Ana","last_name":"Soto","email":"ana@example.test","title":"Auditoría","category":"calidad","price":1500.5}` → devuelve el registro con `id` y fechas. Correo duplicado → `conflict`; datos inválidos → `validation_error` con el detalle por campo.

## Actualizaciones
"Pon la cantidad del 31 en 7 y borra sus notas": `update_record {"id":31,"quantity":7,"notes":null}`. Solo cambian los campos enviados; `updated_at` se renueva.

## Eliminaciones
1. "Elimina el registro 31" → `delete_record {"id":31}` → vista previa, **sin borrar**.
2. Tras su aprobación → `delete_record {"id":31,"confirm":true}`.
Requiere rol `admin`; con `editor`/`viewer` responde `forbidden`.

## Consejos
- Pida campos concretos (`fields`) para ahorrar contexto; el máximo es 100 por página.
- Si el agente dice que no tiene permiso, revise rol y scopes (`user:list`, `token:list`).
- Pruebe interactivamente con el MCP Inspector (`examples/inspector.sh`).
