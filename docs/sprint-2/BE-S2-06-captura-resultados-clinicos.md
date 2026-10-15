# BE-S2-06 — Captura de resultados clínicos

**Estado:** implementado y validado con PostgreSQL aislado (2026-10-11). No se ejecutan migraciones ni seeders en desarrollo/producción, ni commits o push. No se modifica frontend ni se cierra Trello.

## 1. Auditoría y alcance

Se revisaron el documento rector `DONQER_LAB_CICLO_DE_VIDA_ORDENES.md`, BE-S2-01, BE-S2-03/04/05, sus modelos/migraciones, acciones, servicios, rutas, autorización, auditoría, OpenAPI, seeders y pruebas. No existían tablas, endpoints ni un evaluador clínico de resultados. Se reutilizan la publicación inmutable, la versión histórica de línea, el inicio individual de BE-S2-05, SaaS/Teams y `AuditWriter`/`AuditLog`.

`LaboratoryOrderExam` fija `clinical_version_id`; la identidad de cada parámetro es versión + código. Las cuatro categorías publicadas son numeric, text, qualitative y predefined_options. Los esquemas clínicos 1/2 no declaran parámetros opcionales: todos los parámetros publicados son obligatorios para la completitud técnica. No se amplía ni se reinterpreta la configuración publicada.

`processing_started_at` y `processing_started_by` identifican el inicio individual. La máquina actual tiene cancelación de cabecera; no existe cancelación/validación individual implementada. La captura exige orden `in_process`, inicio explícito de esa línea y su versión `published`. Pending, ready sin inicio y legacy/historical no habilitan captura. Una orden iniciada por otra línea tampoco la habilita. Cancelar impide nuevas escrituras, sin borrar resultados previos. No se marca la orden o línea como validada/finalizada.

La satisfacción física se decide y archiva al iniciar mediante BE-S2-05. La captura conserva ese inicio: no inventa una caducidad clínica nueva para el procesamiento ya iniciado. BE-S2-05 impide rechazar o liberar material con dependencias de procesamiento. No se implementan anulación de actividad, resultados validados ni correcciones posteriores a validación.

## 2. Almacenamiento e integridad

Migración aditiva: `laravel/database/migrations/2026_10_11_000001_create_clinical_order_results.php`.

| Estructura | Finalidad |
| --- | --- |
| `laboratory_order_result_captures` | Una fila por línea con cinco identidades de pertenencia, revisión, estado preliminary y último actor/fecha de captura. |
| `laboratory_order_results` | Un resultado vigente por línea/código, snapshot completo del parámetro publicado, columna numeric PostgreSQL de precisión variable, texto u opción tipada, unidad/etiqueta históricas y actor/fecha/revisión. |
| `audit_logs` existente | Historial de cambios `order_results.saved` con revisión, valores anteriores/nuevos, actor, fecha y contexto explícito. |

Ambas tablas conservan laboratorio, orden, línea, examen y versión. FK compuestas enlazan el resultado con su captura y esta con la identidad histórica de la línea. Unicidad de captura por línea y resultado por línea/código impide duplicados; repetir examen mantiene otras identidades, valores y revisiones.

PostgreSQL comprueba publicación/inicio/estado de orden, identidad histórica inmutable, revisión positiva, avance unitario del agregado, un único valor tipado, número finito, snapshot exacto del parámetro, unidad numérica, longitud de texto y código/etiqueta exactos publicados. Editar o limpiar valores requiere avanzar la revisión. La captura no puede borrarse. Un trigger impide UPDATE/DELETE de los eventos clínicos de resultados, además de la protección existente del modelo de auditoría. Los permisos y la auditoría transaccional completa siguen siendo responsabilidad del servicio; no se promete que SQL administrativo arbitrario sustituya esa autorización.

No se añaden columnas ni resultados retrospectivos a líneas anteriores. No se modifica ninguna publicación, snapshot, requisito o excepción legacy. La migración no crea resultados. Su down elimina las dos tablas y conserva la auditoría genérica; es apropiado antes de usar el módulo o con respaldo/restauración explícitos, porque elimina datos de captura. Las funciones usan CREATE OR REPLACE para testing repetido. Las garantías SQL se verifican en PostgreSQL; no se afirma equivalencia SQLite.

## 3. Valores y completitud

| Tipo publicado | Entrada y persistencia |
| --- | --- |
| numeric | Cadena decimal exacta o entero JSON. Fracciones JSON se rechazan para evitar pérdida de precisión al decodificarlas como float. Se admiten signo y notación exponencial; PostgreSQL normaliza sin redondear. La respuesta siempre usa cadena decimal canónica. |
| text | Cadena no vacía ni de espacios, preservada literalmente; máximo publicado, o límite técnico de 100.000 caracteres si no fue configurado. |
| qualitative | Un código literal de las opciones publicadas, con etiqueta histórica derivada por el servidor. |
| predefined_options | Un código literal de las opciones publicadas; sin equivalencias, valores libres ni selección múltiple implícita. |

El literal numérico tiene límite técnico de 4.096 caracteres y exponente de hasta cuatro dígitos. No son límites clínicos, ni rangos diagnósticos. Se rechazan NaN, Infinity, booleanos, arreglos y separadores decimales locales. Para decimales se envía, por ejemplo, `"12345678901234567890.1234567890123456789"`. La unidad siempre proviene del parámetro publicado; no hay conversiones. Las etiquetas se seleccionan mediante comparación estricta: los códigos `"01"` y `"1"` conservan significados distintos.

`value: null` retira un resultado preliminar y lo deja pendiente; el historial conserva ambos valores. Omitir un parámetro conserva su valor anterior. Texto vacío no equivale a null: se exceptúa únicamente este PUT de la normalización global TrimStrings/ConvertEmptyStringsToNull, para validar el contenido real. Un lote con cualquier valor inválido devuelve 422 y no cambia resultados, revisión ni auditoría.

La respuesta `progress` expone revisión, status empty/partial/complete, clinical_status preliminary, required_count, captured_count, pending_count, complete, pending_parameters y validation_errors. El último arreglo está vacío en lecturas válidas: entradas inválidas no se persisten; sus errores se entregan en el 422. Completitud exige todos los parámetros publicados de esa versión, nunca solo uno. Un conjunto sin versión no es completo.

El GET conserva el documento íntegro de cada parámetro, incluidas sus referencias por edad, sexo, condiciones y prioridad. No existe selección de referencia ni evaluación de normalidad/criticidad; no se capturan contexto del paciente ni referencias aplicadas inventadas. Esas decisiones, snapshots y resolución autorizada pertenecen a BE-S2-07 y su integración aprobada.

## 4. API y permisos

Todas las rutas mantienen `/api/v1`, Sanctum SPA, usuario activo, `X-Laboratory-ID`, membresía activa, Teams y suscripción. Recursos ajenos/inexistentes responden 404 sin revelar pertenencia.

| Método y ruta relativa | Permiso |
| --- | --- |
| GET `/laboratory-orders/{order}/exams/{line}/results` | `results.view` |
| PUT `/laboratory-orders/{order}/exams/{line}/results` | `results.capture`; además `results.edit` al enviar un parámetro con valor existente, incluso para un reenvío. |
| GET `/laboratory-orders/{order}/exams/{line}/results/history` | `results.view_history` |

Son cuatro permisos independientes nuevos. Total: **55 permisos**, seis roles. Owner/administrator reciben el catálogo mediante `RolePermissionSeeder`; los demás conservan sus asignaciones anteriores. El seeder sigue siendo independiente. No se usan permisos de administración de exámenes para capturar resultados. La revisión de permisos también se ejecuta en el servicio para escrituras directas del dominio.

Quien tiene capture puede continuar parámetros ausentes; enviar un parámetro con valor existente exige edit adicional, incluso si el valor coincide. Con lectura autorizada, una escritura idéntica y revisión vigente no crea evento ni revisión. Sin results.view, un reenvío autorizado de un valor existente siempre se guarda/audita y avanza la revisión: la respuesta no permite deducir por comparación si coincidía con un valor oculto. El permiso de lectura no está implícito en capture: un PUT sin results.view omite `parameters` en su respuesta, evitando divulgar valores previamente registrados. History autoriza específicamente su historial, incluso sin view.

El GET identifica orden/línea/examen, snapshots de código/nombre, versión/número/hash, inicio, disponibilidad y bloqueantes, parámetros ordenados con restricciones/opciones/unidades/valores y metadatos, progreso y capacidades can_view/can_capture/can_edit/can_view_history. Consultar no crea una captura: su revisión inicial es cero.

```json
{
  "revision": 0,
  "results": [
    {"parameter_code": "NUM", "value": "1.2500"},
    {"parameter_code": "TEXT", "value": "Contenido ficticio de demostración"}
  ],
  "comment": "Captura preliminar demostrativa"
}
```

El PUT acepta entre 1 y 100 objetos, códigos sin repetición y campos explícitos; revision debe ser entero JSON. No se aceptan tipo, unidad, versión, actor o estado enviados por el cliente. Su semántica es guardar un avance por fusión, no reemplazar todo el examen. Una escritura efectiva devuelve 200 con revisión incrementada una vez por lote. El historial pagina 50 eventos por página en ID descendente, con before/after/context y metadatos de paginación.

| Respuesta | Motivo |
| --- | --- |
| 401 / 403 | Sesión, contexto autorizado, membresía, suscripción o permisos según middleware existente. |
| 404 | Orden/línea inexistente, ajena o línea fuera de esa orden. |
| 409 `result_capture_unavailable` | Falta inicio/publicación o la orden no está in_process. |
| 409 `result_revision_conflict` | Revisión obsoleta; recargar antes de guardar. |
| 409 `result_not_editable` | Estado de captura distinto de preliminary; reservado como guarda para integración futura. |
| 422 | Formato/campo desconocido, parámetro ajeno a la versión fijada o valor incompatible. |

Los conflictos reutilizan `ClinicalConflict`: message/code/errors. OpenAPI documenta tres operaciones, entrada tipada, progreso, respuesta e historial; total posterior **103 rutas Laravel, 95 operaciones OpenAPI y 88 rutas con permiso**. Los endpoints anteriores permanecen.

## 5. Transacciones, concurrencia y auditoría

`LaboratoryResultService` bloquea primero la cabecera y luego la línea. Recomprueba usuario/membresía/permisos después de esperar el bloqueo, lee resultados y revisión, valida el lote completo, incrementa la captura, escribe valores y audita en una transacción. Cancelación y otras escrituras de órdenes usan el mismo bloqueo de cabecera. Un fallo de validación, autorización o auditoría revierte todo. El GET usa bloqueo compartido de cabecera para devolver un conjunto coherente con la revisión.

La revisión protege toda la línea: dos operadores que parten de la misma revisión no pueden sobrescribirse, incluso si escriben parámetros diferentes. La primera captura simultánea crea un único agregado. La revisión obsoleta produce 409 antes de considerar un no-op autorizado. El frontend debe volver a leer y decidir explícitamente cómo trasladar cambios.

Publicar otra versión no requiere bloquear esta captura: siempre se lee el ID histórico de la línea, sin resolver el puntero vigente. Los códigos añadidos después no pertenecen a esa línea y se rechazan. No se modifica disponibilidad comercial como consecuencia de capturar.

`order_results.saved` reutiliza la auditoría existente, con sujeto laboratory_order_exam. before/after contienen revisión y lista de cambios por código/tipo/valor/unidad/etiqueta; contexto incluye orden, versión y comentario opcional. El actor y la fecha pertenecen al evento. Se seleccionan explícitamente los campos; no se copia el request, el modelo completo ni condiciones arbitrarias del documento clínico. Una ausencia o limpieza se registra como null. No se crea una tabla paralela de historial ni una interpretación clínica simulada.

## 6. Consulta operativa BE-S2-05

El GET operativo existente añade `result_capture` con el mismo progreso por línea. Conserva operational_status, preparación, muestras, requisitos, dependencias e inicio. Tener resultados no convierte started en validated/completed ni cambia la cabecera.

La consulta de muestras solo expone conteos, códigos pendientes y revisión, sin valores clínicos ni comentarios del historial. Carga únicamente identidad/código de los resultados para ese cálculo; no carga sus valores. La carga de relaciones se agrupa y conserva número de consultas independiente de las repeticiones. Lectura detallada e historial requieren sus permisos propios.

## 7. Dataset demostrativo

`DatabaseSeeder` incorpora `LaboratoryResultSeeder` después de catálogos/publicación/precios, órdenes y muestras. Se mantiene el registro de procedencia BE-S2-04, bloqueo de producción y transacción exterior. `RolePermissionSeeder` no se incorpora al dataset ni cambia su contrato.

Se crea exclusivamente un examen nuevo propio `DEMO-RESULTS`, con descripción **FICTICIO — DEMOSTRACIÓN. NO APTO PARA USO DIAGNÓSTICO REAL.** Sus cuatro parámetros NUM/TEXT/QUAL/OPT son ficticios, sample_mode none, sin rangos médicos inventados. Se publica mediante `ClinicalExamService`, se añade su precio activo propio de 25,00 y se crea una orden mediante `CreateLaboratoryOrder` con tres repeticiones independientes. El inicio utiliza el servicio real BE-S2-05; dos líneas reciben valores mediante el servicio de captura. La primera queda vacía, la segunda parcial y la tercera completa técnicamente, todas sin validación clínica. Total de esa orden: 75,00.

El escenario `result_order_scenario/results-v1` es una identidad persistida explícita. Su segunda ejecución no modifica ni adopta capturas/órdenes preexistentes y preserva correcciones manuales. No se restauran contraseñas, precios, listas predeterminadas ni roles; no se modifica la lista legacy ni otros laboratorios. Conflictos con identidades ajenas al registro abortan la transacción. Los roles demo registrados antiguos no se resincronizan automáticamente: para incorporar este escenario deben contar con los permisos explícitamente provisionados, o se aborta sin cambios parciales.

La comprobación CLI sobre base aislada limpia y una segunda ejecución verificó snapshots idénticos de **todas las tablas**. Dataset ampliado: **14 publicaciones, 20 órdenes, 51 líneas, 47 requisitos, 4 muestras, 3 asociaciones, 1 dependencia de procesamiento, 2 capturas y 6 resultados; 0 excepciones legacy**. Conserva las órdenes y descuentos demostrativos anteriores.

## 8. Verificación PostgreSQL aislada

Solo se usa el proyecto Compose `donqer-be-s2-06`, `deploy/testing/compose.yaml`: PostgreSQL 17 en tmpfs, red interna sin puertos publicados y runner PHP 8.4 con código/configuración de testing en lectura y cachés temporales. Se verifican las guardas BE-S2-02. No se ejecutan suites simultáneas sobre esa base.

```bash
docker compose -p donqer-be-s2-06 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-06 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalResults
docker compose -p donqer-be-s2-06 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never
docker compose -p donqer-be-s2-06 -f deploy/testing/compose.yaml down --remove-orphans
```

Cobertura nueva: cuatro tipos, precisión, texto vacío/largo, opciones exactas, unidades, campos desconocidos, duplicados, revisiones, avances/edición/limpieza, completitud, auditoría/no-op/rollback, líneas repetidas, parámetros de publicación posterior, legacy, inicio físico real, cancelación, tenants, permisos separados, actor/membresía/suscripción activos, SQL y dataset. Se conservan las pruebas anteriores de composición, economía, descuentos, autorización, muestras, migraciones e idempotencia. Las pruebas de rollback de esquemas anteriores ahora retiran y restauran primero la nueva migración dependiente. Las carreras previas de adición/eliminación contra cambio de estado usan cancelación: in_process exige inicio individual desde BE-S2-05, y aquellos escenarios no lo tenían. Se conserva la comprobación de bloqueo de cabecera, conteos y totales sin cambiar la máquina de estados.

Ocho escenarios con procesos/conexiones PostgreSQL independientes comprueban primera captura duplicada, edición del mismo parámetro, cambios en parámetros distintos/pérdida de revisión, rollback de captura, cancelación y su rollback, desactivación del actor durante la espera, y publicación concurrente conservando versión histórica. Los siete que compiten por cabecera verifican espera real en pg_stat_activity; el caso de publicación comprueba captura independiente antes de confirmar la publicación nueva. No se simula concurrencia con la misma conexión.

**Resultado completo final (2026-10-11):** PHPUnit 12.5.35, PHP 8.4.25 y PostgreSQL 17.11. **3.105 casos, 19.622 aserciones, cero errores, cero fallos y tres omisiones SQLite existentes.** Duración del runner: **5 min 49,053 s**.

| Suite / subconjunto | Casos | Aserciones | Errores / fallos | Omitidos |
| --- | ---: | ---: | ---: | ---: |
| Unit | 46 | 70 | 0 / 0 | 0 |
| Feature | 3.059 | 19.552 | 0 / 0 | 3 |
| Total | 3.105 | 19.622 | 0 / 0 | 3 |
| ClinicalResults, incluido en Feature | 36 | 227 | 0 / 0 | 0 |
| Integración clínica, incluida en ClinicalResults | 28 | 156 | 0 / 0 | 0 |
| Concurrencia clínica, incluida en ClinicalResults | 8 | 71 | 0 / 0 | 0 |
| Dataset, incluido en Feature | 10 | 659 | 0 / 0 | 0 |

La batería final dirigida de resultados y seeders pasó con **46 casos y 886 aserciones**. Las ocho carreras nuevas ejecutaron procesos y conexiones reales y no se omitieron. Las tres omisiones corresponden a intención numérica de SQLite y dos comprobaciones específicas de sus triggers de descuentos; no son omisiones de captura o concurrencia PostgreSQL. Se conserva la regresión completa de BE-S2-03/04/05.

La última comprobación CLI ejecutó migrate:fresh únicamente en esta base aislada y db:seed dos veces con el código final, y confirmó snapshots idénticos de todas las tablas y los conteos del apartado 7. También se preservan las correcciones manuales en las pruebas de reseeding. Laravel Pint (`--dirty --test --format agent`) y `git diff --check` pasaron.

El proyecto Compose temporal se retiró con `down --remove-orphans` tras conservar los reportes. JUnit final: `/tmp/be06-results/full.xml`; log completo: `/tmp/be06-full.log`; batería dirigida: `/tmp/be06-results/results.xml` y `/tmp/be06-results.log`; CLI: `/tmp/be06-demo-check.php` y `/tmp/be06-demo-check.log`; Pint: `/tmp/be06-pint-final.log`. Los diagnósticos previos se conservan separados en `/tmp/be06-results/initial.xml`, `/tmp/be06-results/intermediate.xml` y sus logs; no se presentan como ejecuciones finales. No se ejecutaron migraciones ni seeders en desarrollo/producción.

Los antecedentes BE-S2-05 se mantienen como resultados de su entrega; las cifras de este apartado corresponden a la suite completa del código final BE-S2-06.

## 9. Archivos y trabajos posteriores

Nuevos: migración de resultados; modelos `LaboratoryOrderResultCapture` y `LaboratoryOrderResult`; `ClinicalResults/LaboratoryResultService`; controlador `LaboratoryResultController`; request `SaveLaboratoryResultsRequest`; `OpenApi/ClinicalResultDocumentation`; `LaboratoryResultSeeder`; pruebas `ClinicalResults/ClinicalResultScenario`, `ClinicalResultTest`, `ClinicalResultConcurrencyTest`; este documento.

Modificados: relaciones de `LaboratoryOrderExam`, progreso en `LaboratorySampleService`, catálogo RBAC, rutas, excepción de normalización del PUT en bootstrap, OpenAPI operativo, `DatabaseSeeder`, pruebas de seeders, inventarios y migraciones progresivas. Los cambios anteriores BE-S2-03/04/05 permanecen como trabajo existente.

Quedan separados BE-S2-07/08: selección/aplicación de referencias y snapshot de contexto, interpretación, validación por profesional, bloqueo/corrección de resultados validados, finalización y cierre clínico/financiero. Tampoco hay informes, entrega, conversiones de unidades ni frontend. Añadir estados de captura posteriores exige ampliar deliberadamente CHECK/triggers y reglas de autorización: no se abre edición de resultados validados por anticipado.

Despliegue posterior autorizado: migración habitual y actualización explícita del catálogo mediante `RolePermissionSeeder`, coordinación con consumidores del campo operativo añadido y el contrato decimal/revisión. Esta sesión no ejecuta esos pasos en entornos operativos y deja cambios sin commit/push para revisión.

## Actualización posterior — BE-S2-07 (2026-10-11)

BE-S2-07 implementa validación explícita, política configurable, autorización profesional, resolución de referencias y rectificación auditada. Mantiene precisión decimal y captura preliminar; validated impide el PUT ordinario hasta rectificación. Cada transición clínica avanza la revisión del agregado y distingue la revisión aprobada. Los permisos validate/rectify no se asignan automáticamente por ser owner/administrator.

El informe y las cifras anteriores corresponden a esta entrega histórica. Para el contrato vigente consultar [BE-S2-07 — Validación de resultados clínicos](BE-S2-07-validacion-resultados-clinicos.md). El inventario ampliado es de 112 rutas Laravel, 104 operaciones OpenAPI y 60 permisos (97 rutas protegidas por permiso). RolePermissionSeeder permanece independiente; esta ampliación no ejecuta migraciones/seeders en entornos operativos ni commit/push. La verificación final se documenta en BE-S2-07.


## Actualización posterior — BE-S2-08 (2026-10-11)

BE-S2-08 incorpora resolución individual explícita, excepciones solicitadas y decididas por usuarios independientes y cierre clínico con evidencia inmutable. Una validación vigente no finaliza automáticamente una línea. Las líneas resueltas bloquean la rectificación ordinaria; el PATCH de estado no completa órdenes. Se aprobó el cierre explícito desde pending cuando absolutamente todas las líneas tienen excepciones aprobadas, conservando su origen y sin inventar procesamiento. La composición, publicaciones, resultados y excepciones legacy históricas permanecen intactos.

Se conserva la independencia financiera del cierre y se añade un escenario demo propio e idempotente. El catálogo vigente contiene 66 permisos y seis roles; las escrituras clínicas nuevas requieren concesión explícita. Las cifras anteriores corresponden a esta entrega histórica; el contrato y la verificación ampliados se documentan en [BE-S2-08 — Finalización clínica](BE-S2-08-finalizacion-clinica-ordenes.md). No se ejecutan estos cambios en entornos operativos ni commit/push.
