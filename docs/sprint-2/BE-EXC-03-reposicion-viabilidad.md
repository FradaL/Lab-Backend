# BE-EXC-03 — Reposición pendiente y evaluación de viabilidad

Fecha: **2026-10-29**. Fuente: solicitud «Donqer Lab — Implementación BE-EXC-03», adjunto `554f3047-a1d8-4b86-955d-fde0ffcfbb95`. Base inicial backend `6f7de3a`, producto `bca0358`; sin cambios posteriores a la auditoría clínica en esos árboles. Cambio local preexistente preservado: `laravel/donqer-lab.code-workspace` sin seguimiento. Fuente funcional: [DF-019 APROBADO 1.4](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), I.B/I.C, EXC-02/09 y M01–M07; [plan PROPUESTO 1.4](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md). No se cambia una decisión aprobada.

**Estado técnico final: IMPLEMENTADO Y PROBADO en PostgreSQL aislado.** BE03 aporta los datos y operaciones requeridos para que BE04 integre los controles terminales. No equivale a cierre administrativo ni a circuito integral de excepciones terminado.

## Diagnóstico inicial y alcance

BE-EXC-03 no tenía tablas, servicios ni endpoints propios. BE05 ya aportaba identidad del recipiente, recepción, aceptación/rechazo, eventos, asociaciones a requisitos, compatibilidad y dependencias de procesamiento. BE08 comprobaba autoridad, revisiones, obsolescencia de captura/inicio, validaciones históricas y catálogo, pero no reposición/viabilidad. El catálogo de motivos y la revisión de impacto no constituyen evaluación de reposición. No se dedujo implementación desde Trello: el enlace `https://trello.com/c/TbeJZCmx` no fue accesible en la consulta; no se modificó el tablero.

Esta entrega añade datos y operaciones de BE-EXC-03. **No integra aún elegibilidad de solicitud/aprobación BE-EXC-04**, no genera decisiones/resoluciones ni cambia el cierre. La brecha clínica de aprobación identificada en la auditoría permanece pendiente de BE-EXC-04; disponer de estos datos no la corrige automáticamente. BE-EXC-05, frontend y despliegue quedan fuera de alcance.

## Persistencia e integridad

Migración aditiva: `laravel/database/migrations/2026_10_29_000001_add_clinical_replacement_evidence.php`. Sin backfill ni modificaciones a filas/tablas de BE05/BE08 o a snapshots históricos.

| Tabla nueva | Función |
| --- | --- |
| `clinical_replacement_contexts` | Una revisión monotónica por línea; proyección protegida, avanzada por triggers de inserción. No sustituye `clinical_revision` de BE08. |
| `clinical_replacement_intents` | Intención inmutable, requisito obligatorio, muestra original opcional, motivo/justificación, responsable y fechas. No crea recipiente. |
| `clinical_replacement_links` | Relación con muestra real y asociación BE05. Original y requisito se recuperan de la intención. Cadenas se reconstruyen uniendo original→muestra vinculada; se impiden identidad repetida y ciclos dentro de la orden. |
| `clinical_replacement_events` | Apertura, vínculo de material y satisfacción material explícita; historial inmutable por intención. |
| `clinical_line_evidence` | Hechos clínicos tipados, fecha de ocurrencia distinta del registro, responsable autenticado y vínculos opcionales a muestra/requisito. |
| `clinical_viability_assessments` | Evaluación formal por sujeto, resultado, justificación, evidencias referenciadas, snapshot de dependencias y predecesora. Cadena sucesora sin bifurcaciones. |

FK compuestas conservan laboratorio/orden/línea, requisito, muestra y actor miembro. Triggers verifican pertenencia del requisito a la línea, coincidencia con la intención, compatibilidad del material, evidencia del mismo contexto, sucesión y snapshot vigente. UPDATE/DELETE de los cinco historiales y modificación directa de la proyección se rechazan. Índices impiden duplicar una revisión o sucesora y repetir un vínculo por intención/muestra.

Rollback de esta migración sólo se admite sin historial BE-EXC-03; con historia exige restauración explícita. No se ejecutó rollback sobre bases compartidas. La retirada de una línea con contexto de reposición devuelve `409 order_exam_has_clinical_activity`; conserva evidencia sin marcar inicio de procesamiento o de actividad artificial. La FK también impide eliminar el contexto referenciado. Los fixtures históricos que retiran BE05/BE08 retiran primero esta migración vacía y la restauran después de sus dependencias, sin CASCADE ni debilitamiento de las aserciones.

## Reutilización de muestras y estado de intención

`ClinicalReplacementService::linkSample` llama a `LaboratorySampleService::associate`; no duplica registro, recepción ni evaluación física. Usa `SampleCompatibility::candidates` para comprobar el material actual, también cuando ya existía una asociación. Crear/vincular una muestra no la acepta. La original conserva identidad, estado, eventos y rechazo. Una nueva intención puede referirse a una reposición anterior para conservar la cadena.

La intención tiene estado operativo calculado:

- `pending`: sin satisfacción material explícita actualmente válida; incluye intención sin recipiente y material recibido pendiente de aceptación.
- `fulfilled`: evento explícito `fulfilled` cuyo vínculo sigue siendo una asociación activa, aceptada, compatible y suficiente conforme al evaluador BE05.

El endpoint `fulfill` registra disponibilidad material y su justificación; no resuelve el examen, no inicia procesamiento ni cancela solicitudes. No deduce cumplimiento desde una declaración de inviabilidad. Si se rechaza/libera el material o deja de ser adecuado, la consulta vuelve a mostrar pendiente sin modificar el evento histórico. No se añade cancelación automática ni política de abandono de intenciones.

## Evidencia y evaluaciones

| Motivos aprobados | Tipo y campos de hechos | Evaluación disponible |
| --- | --- | --- |
| M01/M02 | `sample_problem`: `problem`; muestra/requisito afectados cuando corresponda | Sujeto `replacement`, asociado a intención |
| M03 | `patient_refusal`: `express_refusal` exactamente JSON true y `observations`; responsable/fecha conservados | Registrar rechazo no requiere evaluación adicional; no aprueba ni resuelve |
| M04 | `follow_up`: `action`, `observations`; cada seguimiento preserva actor/fecha | Sujeto `continuation` |
| M05 | `medical_instruction`: `instruction`; responsable del registro y fecha | No implica cancelar orden/cobros ni aprobación |
| M06/M07 | `technical_problem`: `problem`; `alternatives`: `alternatives_considered` | Sujeto `technical_alternatives` |

No se carga automáticamente el catálogo base ni se asocian códigos de motivos desde texto. Los nombres anteriores son contratos técnicos de evidencia, no nuevas clasificaciones clínicas. Campos textuales no vacíos hasta 4.000 caracteres, sin adjuntos/fotos/firmas obligatorios, número universal de intentos ni plazos automáticos. Actor/fecha de registro provienen del backend; `occurred_at` permite registrar el hecho real previo, sin fechas futuras ni actor fabricado por el cliente.

La evaluación es una operación distinta del registro de evidencia. Requiere permiso clínico específico, `viable`/`non_viable`, justificación, IDs de evidencia aplicable y predecesora explícita. Reposición referencia problema; continuación referencia seguimiento; alternativas técnicas referencia problema y alternativas consideradas. Los IDs deben pertenecer a la línea y respetar muestra/requisito de la intención. Estas verificaciones estructurales no acreditan suficiencia de una futura solicitud por motivo: BE-EXC-04 debe aplicar las condiciones M01–M07 y la referencia clínica correspondiente.

Cada sujeto (`replacement`+intención; `continuation` por línea; `technical_alternatives` por línea) tiene cadena independiente. La primera usa `previous_id: null`; las siguientes señalan la última evaluación de ese sujeto. No se sobrescriben originales. Ningún resultado aprueba una excepción automáticamente.

## Contratos y autorización

Prefijo: `/api/v1/laboratory-orders/{order}/exams/{line}/clinical-replacements`.

| Método y sufijo | Permiso explícito | Contrato |
| --- | --- | --- |
| GET `/` | `orders.clinical_replacements.view` | Historial paginado de intenciones/evidencia/vínculos/eventos/evaluaciones; vigencia y resumen actuales |
| POST `/intentions` | `orders.clinical_replacements.record` | requisito, muestra original opcional, motivo y justificación |
| POST `/evidence` | `orders.clinical_replacements.record` | tipo, facts tipados y vínculos opcionales |
| POST `/intentions/{intention}/samples` | `orders.clinical_replacements.record` **y** `samples.associate` | muestra real y justificación; asociación BE05 en la misma transacción |
| POST `/intentions/{intention}/fulfill` | `orders.clinical_replacements.record` | link_id y justificación; exige material aceptado/adecuado |
| POST `/assessments` | `orders.clinical_replacements.evaluate` | sujeto, intención cuando corresponda, predecesora, resultado, evidencia y justificación |

Se revisó RBAC antes de extenderlo: `samples.evaluate` gobierna aceptación/rechazo físico; aprobar excepciones y revisar impacto de catálogo también son facultades diferentes. Se añaden tres permisos al catálogo (95→98), **sin concesión automática a ninguno de los seis roles**. Los dos perfiles sintéticos del seeder de desarrollo que ya declaran expresamente todos los permisos incorporan los tres códigos al ejecutarse su provisión existente: su conteo directo pasa de 180 a 186; se actualiza esa aserción, sin cambiar el seeder ni ejecutar provisión compartida. RolePermissionSeeder registra los códigos por su mecanismo existente cuando se ejecute explícitamente. Las concesiones efectivas del entorno deben provisionarse por el mecanismo autorizado existente; no se ejecutaron seeders en desarrollo/producción.

HTTP exige sesión Sanctum, contexto `X-Laboratory-ID`, usuario/membresía/laboratorio/suscripción activos y permisos. Los servicios internos repiten autorización, sin confiar sólo en middleware. Escrituras requieren orden abierta y línea no resuelta; no se relajan protecciones de validación/corrección existentes.

Toda escritura exige `expected_revision` entero JSON y `occurred_at` ISO8601 con zona. Se rechazan campos desconocidos. Éxito de escritura: 201 con ID y revisión actual; lectura: 200. 400 sin contexto donde corresponda; 401 sin sesión; 403 sin autoridad; 404 para referencias fuera del ámbito; 409 por conflicto; 422 por contrato inválido.

Códigos: `replacement_revision_conflict`, `viability_predecessor_conflict`, `viability_dependencies_conflict`, `replacement_order_not_open`, `replacement_sample_identity_conflict`, `replacement_sample_already_linked`, `replacement_material_not_adequate`, `replacement_integrity_conflict`, además de los códigos BE05/guard de línea reutilizados.

### Evaluación — ejemplo sintético

Consultar primero GET y usar revisión/token devueltos, sin reintentos ciegos:

```json
{
  "expected_revision": 3,
  "expected_dependency_token": "<dependency_token del GET>",
  "occurred_at": "2026-10-29T08:00:00-06:00",
  "subject": "replacement",
  "intention_id": 1,
  "previous_id": null,
  "outcome": "viable",
  "justification": "FICTICIO: evaluación clínica registrada por el responsable",
  "evidence_ids": [1]
}
```

El token real es un SHA-256 hexadecimal de 64 caracteres; el marcador del ejemplo no es un valor válido. Los IDs son ilustrativos y deben pertenecer al contexto consultado.

## Concurrencia y consumidor BE-EXC-04

Orden de bloqueo: orden → línea → autoridad efectiva/contexto. BE05 y BE08 ya serializan operaciones por orden. Cada inserción incrementa la revisión; las evaluaciones no incrementan revisiones de otras cadenas ni se incluyen en el snapshot de dependencias, evitando invalidación propia. Las revisiones son opacas: una operación puede insertar más de un registro y avanzar varias posiciones.

El snapshot `clinical_replacement_dependencies(line_id)` preserva intenciones, evidencia, vínculos, eventos, requisitos y muestras/asociaciones relacionadas. `expected_dependency_token` es obligatorio al evaluar y se revalida bajo bloqueo: detecta cambios físicos aunque no hayan avanzado la revisión propia. Historial y snapshot original permanecen inmutables.

GET expone:

- `revision`, `dependency_token` y cinco historiales con `page`, 50 filas por colección y metadatos independientes.
- `latest_assessments`: última evaluación por sujeto, independiente de la página histórica; `requires_review` compara dependencias actuales con las observadas. Una evaluación sucesora reemplaza vigencia, no historia.
- `intention_states`: pendiente/satisfecha materialmente, última evaluación, `current_outcome` o null si falta evaluación actual o cambiaron dependencias.
- `pending_viable_intention_ids`: sólo intenciones pendientes con evaluación viable vigente.
- `review_required`: falta de evaluación de intención o cambios en dependencias observadas. Es información clínica para revisión, no una autorización ni una decisión terminal.

El conjunto de dependencias se conserva a nivel de línea: evidencia nueva de esa línea también señala revisión. BE-EXC-04 deberá integrar su revisión clínica y la suficiencia por motivo antes de cualquier aprobación. Una lista vacía de pendientes viables **no demuestra inviabilidad ni habilita resolución**. El consumidor debe revalidar revisión/token dentro de su propia transacción clínica y conservar referencias originales. El método `read` es exclusivamente lectura; no sustituye la futura validación transaccional BE04.

## OpenAPI y pruebas

Contrato fuente: `laravel/app/OpenApi/ClinicalReplacementDocumentation.php`; seis operaciones, esquemas de escritura y lectura tipados. Exportación habitual `laravel/storage/api-docs/api-docs.json` regenerada: 182 operaciones totales; ese archivo es ignorado por Git según la configuración existente. El contrato fuente y su prueba permanecen versionables, sin cambiar reglas de ignore. La prueba de contrato regenera OpenAPI, resuelve todas sus referencias locales y coteja rutas/permisos y la separación de roles.

Resultados ejecutados el 2026-10-29:

| Corrida | Resultado y alcance |
| --- | --- |
| Regresión inicial dirigida | 326 casos, 6.144 aserciones: 325 satisfactorios y un fallo de conteo fijo en fixture demo (180 concesiones directas esperadas, 186 reales tras registrar tres permisos para dos perfiles sintéticos que ya usan todo el catálogo). No se presenta como corrida completa sin fallos. |
| BE-S2-05 en esa corrida | 47/47 pruebas, 416 aserciones, sin fallos/errores; incluye concurrencia de muestras. |
| BE-S2-08 en esa corrida | 96/96 pruebas, 503 aserciones, sin fallos/errores; incluye historia, servicios, HTTP y concurrencia. |
| Repetición final tras corregir el conteo y añadir protección de retirada | **113/113 pruebas, 4.087 aserciones, cero fallos/errores/omisiones**: BE03, composición, toda la clase DemoDevelopmentSeederTest y RBAC. No se repitió toda la corrida de 326. |
| BE03 dentro de la repetición final | **42/42 pruebas, 3.300 aserciones**; incluidas cuatro carreras PostgreSQL reales. No sumar esta cifra otra vez a los 113 casos. |
| Composición dentro de la repetición final | 50/50 pruebas, 334 aserciones; incluye protección de retirada y concurrencia. |
| Demos y RBAC dentro de la repetición final | 18/18 casos demo, 398 aserciones; 3/3 casos RBAC, 55 aserciones. El fallo anterior queda cubierto por la repetición de su clase completa. |
| OpenAPI | Regeneración satisfactoria, 182 operaciones, 1.014 referencias locales resueltas. Fuente, exportación habitual y artefacto aislado coherentes. SHA-256 del JSON: `f2662b4c6675c6ae3c853acbd58a27c9a9fd229d581d6bd04b423078409757fd`. |
| Formato/documentación | Pint satisfactorio en los 13 archivos de implementación/pruebas de BE03 y la protección de retirada; sintaxis PHP satisfactoria; git diff --check en ambos repositorios; 575 enlaces locales comprobados antes del cierre documental. DF-019 y DF-016 idénticos a HEAD. |

Las carreras usan pcntl_fork, conexiones independientes y `pg_stat_activity` para demostrar espera efectiva: dos evaluaciones de la misma revisión; evidencia/evaluación; evaluación/evidencia; aceptación física/evaluación basada en token anterior. Los lectores rechazan la revisión o token obsoletos; no aparecen bifurcaciones ni resoluciones.

Otros casos verifican intención sin recipiente, rechazo original y muestra posterior con identidad propia, aceptación independiente, satisfacción material explícita y pérdida posterior de adecuación, evidencia de los siete motivos, M03 sin evaluación adicional para registrar rechazo, sujetos sin muestra, predecesora exacta, referencias/SQL inválidos, historia inmutable, permisos independientes, aislamiento, paginación sin perder la evaluación última, obsolescencia física, ciclos, rollback de asociación/eventos/auditoría y preservación de procesamiento/orden/resoluciones.

Comando durable para repetir la selección final desde backend-lab (el proyecto crea únicamente infraestructura temporal):

```sh
docker compose -p donqer-be-exc-03 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalReplacements tests/Feature/OrderComposition tests/Feature/DemoDevelopmentSeederTest.php tests/Feature/Authorization/RolePermissionSeederTest.php
```

La corrida inicial también incluyó `tests/Feature/PhysicalSamples`, `tests/Feature/ClinicalFinalization`, `ClinicalExamTest`, `ClinicalResultTest`, `AtomicOrderCompositionTest` y `LaboratoryOrderSeederTest`. Los JUnit exactos están en `/tmp/be-exc-03-artifacts/regression.xml` y `/tmp/be-exc-03-artifacts/final.xml`; OpenAPI en `api-docs.json` del mismo directorio. Los contenedores y la red efímeros se retiraron al terminar. No se afirma suite global de todo el repositorio.

Evidencia temporal: `/tmp/be-exc-03-artifacts/`. PostgreSQL 17 efímero exclusivo `donqer-be-exc-03`, tmpfs, red interna sin puertos publicados; código montado sólo lectura para ejecutar pruebas. Formateador aplicado únicamente a archivos de la entrega. Sin migraciones ni seeders sobre bases compartidas, commits, push, frontend o modificación de Trello.

## Dependencias y límite de finalización

BE-EXC-03 proporciona evidencia estructurada y revisiones para BE-EXC-04. BE04 debe verificar mínimos al solicitar, conservar la dispensa M03, registrar/revisar dependencias clínicas de solicitudes, bloquear aprobación terminal ante reposición pendiente viable y revalidar atomicidad con cambios de muestras/catálogo/permisos. También mantiene el circuito de incidencia sin inicio artificial y las reglas de corrección D10. BE05 ya se reutiliza, sin segundo circuito de muestras/excepciones.

BE-EXC-05 conserva No realizado/Incompleto e informes prospectivos. Las tablas, servicios y renderer clínicos de BE08/09 no se alteran en esta entrega. No se declara terminado el circuito integral ni corregida la brecha terminal mientras BE04 no integre y pruebe sus controles. La finalización técnica de BE03 no equivale a cierre administrativo de la tarjeta ni a despliegue.
