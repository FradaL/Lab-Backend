# BE-S2-08 — Finalización clínica de exámenes y órdenes

**Estado:** implementado y validado con PostgreSQL aislado (2026-10-11). Se conserva el trabajo BE-S2-07 existente. No se ejecutan migraciones/seeders en desarrollo o producción, commit, push, despliegue, frontend ni cierre de Trello.

## 1. Auditoría y decisión aprobada

Se revisaron el documento rector, BE-S2-01/03/04/05/06/07, modelos y acciones de órdenes/líneas, muestras/inicio individual, servicios de resultados y validación, auditoría, restricciones PostgreSQL, RBAC Teams, rutas, OpenAPI, consultas y dataset. `completed` ya significa finalización clínica, independiente del pago. No existe cancelación individual ni un sistema de pagos/saldos. Se reutilizan la cabecera bloqueada, la versión histórica de cada línea, la validación exacta e inmutable y la revisión de captura.

El usuario aprobó **`pending → completed` exclusivamente mediante el cierre clínico explícito cuando todas las líneas tengan excepciones aprobadas**. Permite resolver órdenes íntegramente históricas o imposibles de procesar, sin inventar inicio, muestras ni resultados. El documento rector incorpora esta ampliación. El PATCH ordinario no permite completar una orden; conserva sus no-op y transiciones restantes. El cierre desde `in_process` también requiere el endpoint clínico.

El rector exige un procedimiento excepcional para cancelar resultados validados. Se bloquea la cancelación ordinaria si existe cualquier validación histórica, incluso después de rectificarla. No se implementa el procedimiento excepcional. Las cancelaciones anteriores conservan su condición; ninguna cancelación equivale a una excepción aprobada.

## 2. Persistencia y PostgreSQL

Migración aditiva: `2026_10_11_000003_add_clinical_order_finalization.php`.

| Estructura | Contrato |
| --- | --- |
| `laboratory_orders.clinical_revision` | Revisión independiente, inicial cero; cambia por composición, solicitudes/decisiones, resoluciones y cierre. |
| `clinical_exception_requests` | Solicitud con pertenencia completa, motivo, actor/fecha, revisión propia, revisión de captura e inicio observados, origen histórico. Una pendiente por línea. |
| `clinical_exception_decisions` | Una decisión inmutable por solicitud: aprobada o rechazada, actor/fecha, motivo y evidencia. |
| `clinical_line_resolutions` | Una resolución inmutable por línea: `validated_result` con ID de validación, o `authorized_exception` con ID de decisión. |
| `clinical_order_finalizations` | Un cierre inmutable por orden: actor/fecha, revisión clínica previa y snapshot ordenado de todas las líneas/resoluciones. |

FK compuestas aseguran laboratorio/orden/línea, decisión/solicitud y validación/línea. Los actores pertenecen al laboratorio mediante FK a su membresía; el servicio vuelve a exigir usuario y membresía activos y permisos efectivos. No se modifican versiones, snapshots clínicos, excepciones legacy ni atributos comerciales históricos al migrar. La migración no crea evidencia clínica. Las órdenes `completed` antiguas sin evidencia se identifican como `historical`, nunca como un cierre nuevo autorizado.

PostgreSQL impide modificar o borrar solicitudes fuera de su decisión, decisiones, resoluciones, cierres y eventos `order_clinical.*`. La revisión de solicitud avanza de 1/pending a 2/approved o rejected mediante su decisión. Rechaza autoaprobación, resolución con validación distinta de la vigente, excepciones que escondan validaciones y cierres con composición/snapshot/revisión inconsistentes. Un constraint trigger diferido exige que evidencia de cierre y transición completed confirmen juntas.

Las líneas resueltas y la composición finalizada quedan protegidas contra UPDATE/DELETE y nuevas líneas. Los resultados/capturas resueltos también quedan protegidos; una excepción pendiente impide la transición de captura a validated. La cabecera con cierre nuevo queda inmutable. No se rebajan restricciones anteriores. Estas garantías se verifican en PostgreSQL; no se promete equivalencia en SQLite ni que SQL administrativo sustituya autorización/auditoría de dominio.

El down rechaza historial existente de solicitudes/resoluciones/cierres; exige una estrategia explícita de respaldo/restauración. Antes de utilizar el módulo puede retirar tablas, columna y triggers dependientes. No revierte publicaciones, estados históricos ni reconstruye evidencia. Las funciones usan CREATE OR REPLACE para pruebas repetidas.

## 3. Finalización individual y excepción

Validar mediante BE-S2-07 no finaliza automáticamente. El POST individual exige orden in_process, inicio de esa línea, captura validated, evidencia de la revisión vigente exacta, revisión de resultados recibida, ausencia de excepción pendiente y permiso propio. Fija ID de validación, revisión aprobada/política y origen histórico. No altera valores ni revisión de captura. Publicaciones posteriores y cambios del paciente no sustituyen la evidencia de BE-S2-07.

Solicitar una excepción exige motivo no vacío, revisión clínica vigente y revisión actual de captura (cero si no existe). Archiva origen clínico e inicio observado. No admite una línea resuelta, una pendiente duplicada ni **ninguna validación histórica**, aun rectificada. La solicitud no invalida ni limpia resultados preliminares; una captura o inicio posterior vuelve obsoleta su aprobación. La validación permanece bloqueada mientras la solicitud esté pendiente.

Aprobar exige otro usuario con permiso explícito, solicitud pendiente/revisión 1 y línea sin cambios desde su solicitud. Inserta decisión y resolución en la misma transacción. Nunca crea una validación, versión, muestra o resultado. Rechazar requiere motivo y conserva la solicitud y decisión; permite luego una nueva solicitud o continuar el flujo clínico. Un solicitante con permiso de aprobación puede rechazar su propia solicitud; no puede aprobarla.

Las líneas legacy/historical pueden resolverse excepcionalmente con autorización explícita, conservando versión null y origen. La prueba de actualización crea la historia antes de BE-S2-04, aplica las migraciones y comprueba su preservación antes de resolverla. El corte legacy permanece idéntico. No se incorporan líneas históricas automáticamente.

Una resolución definitiva bloquea captura, validación, rectificación ordinaria, eliminación e incorporación/liberación de material de esa línea. La protección existe antes de cerrar toda la orden. Reapertura, anulación de resolución y corrección posterior requieren un procedimiento futuro que preserve historia; no se habilitan aquí.

## 4. Cierre y economía

El cierre exige al menos una línea y resolución definitiva de **todas**, sin solicitudes pendientes. Desde pending todas deben ser authorized_exception; desde in_process se permiten validadas finalizadas, excepciones o mezcla. Guarda snapshot por ID ascendente: identidad de línea/examen, código/nombre históricos, versión/origen, ID/tipo de resolución y evidencia de validación/decisión. No deduplica repeticiones.

El servicio inserta evidencia, cambia completed y avanza una vez la revisión clínica, junto con `order_clinical.finalized` y `order.status_changed`. Un fallo de auditoría revierte todo. No requiere ni escribe paid/balance/payment_status, no recalcula ni altera precio, descuento, impuestos, moneda o total. Finalizar no emite ni entrega informes.

El esquema no posee pagos o saldos, por lo que no sería correcto afirmar una prueba de pago completo o saldo pendiente real. Se verifican cierres con total positivo y preservación exacta de atributos económicos; la futura integración de pagos debe conservar esta independencia.

## 5. API y permisos

Siete rutas nuevas, SaaS/Sanctum, usuario activo, `X-Laboratory-ID`, membresía activa, Teams y suscripción. Recursos ajenos/inexistentes responden 404 sin revelar pertenencia. Las escrituras vuelven a comprobar actor/membresía/permisos después de esperar bloqueos.

| Método y ruta relativa a `/api/v1` | Permiso |
| --- | --- |
| POST `/laboratory-orders/{order}/exams/{line}/exceptions` | `orders.clinical_exceptions.request` |
| GET misma ruta | `orders.clinical_exceptions.view` |
| POST misma ruta `/{exception}/approve` | `orders.clinical_exceptions.approve` |
| POST misma ruta `/{exception}/reject` | `orders.clinical_exceptions.approve` |
| POST `/laboratory-orders/{order}/exams/{line}/finalize` | `orders.exams.clinical_finalize` |
| POST `/laboratory-orders/{order}/finalize` | `orders.clinical_finalize` |
| GET `/laboratory-orders/{order}/clinical-finalization` | `orders.clinical_finalization.view` |

Catálogo: **66 permisos y seis roles**. Owner/administrator reciben únicamente los dos permisos nuevos de lectura, con 60 asignaciones cada uno; ninguno de los cuatro permisos nuevos de escritura se concede automáticamente. Se mantienen las exclusiones validate/rectify de BE-S2-07. Otros roles conservan sus asignaciones. RolePermissionSeeder sigue siendo independiente. Aprobar una excepción o fijar una validación anterior no requiere automáticamente registro profesional: ese registro/política gobierna la validación de BE-S2-07.

Ejemplos de cuerpos (las revisiones se consultan antes de escribir):

```json
{"clinical_revision":1,"result_revision":0,"reason":"Imposibilidad documentada"}
```

Aprobar: `{"clinical_revision":2,"request_revision":1}`. Rechazar agrega reason obligatorio. Finalizar línea: `{"clinical_revision":1,"result_revision":2}`. Cerrar orden: `{"clinical_revision":2}`. Son enteros JSON; cadenas, campos desconocidos, revisiones negativas y motivos vacíos producen 422. Solicitud devuelve 201; decisiones/finalizaciones 200.

GET de excepciones pagina 50 por ID descendente y expone solicitudes/decisiones con su permiso propio. GET de finalización expone progreso, bloqueantes, resolución/actores/fechas, revisiones y capacidades orientativas, sin valores clínicos ni motivos reservados del historial. El GET operativo conserva muestras, progreso de captura, inicio y dependencias, añadiendo resolución mínima/progreso/capacidad de cierre. Carga relaciones por lotes, sin aumentar consultas por repetición ni cargar valores clínicos para estos indicadores. Las cabeceras incluyen clinical_revision y metadatos de cierre cuando se carga esa relación; el endpoint detallado conserva su permiso específico.

| Código / respuesta | Significado |
| --- | --- |
| `clinical_revision_conflict` / 409 | Cambió composición, solicitud, decisión o resolución. |
| `result_revision_conflict` / 409 | Cambió revisión de captura/validación. |
| `clinical_line_not_validated` / 409 | Falta inicio o validación vigente exacta. |
| `clinical_line_resolved` / 409 | Ya existe resolución definitiva; no cabe edición ordinaria. |
| `clinical_exception_pending` / 409 | Solicitud activa que debe decidirse. |
| `clinical_exception_not_available` / 409 | Existe una validación histórica que no puede ocultarse. |
| `clinical_exception_obsolete` / 409 | Cambió captura o inicio después de solicitar. |
| `clinical_exception_revision_conflict` / 409 | Solicitud ya decidida o revisión propia obsoleta. |
| `clinical_order_unresolved` / 409 | Alguna línea sigue pendiente. |
| `clinical_order_not_open` / 409 | Estado incompatible. |
| `clinical_order_finalized` / 409 | Ya existe evidencia de cierre. |
| `clinical_finalization_required` / 409 | PATCH completed exige endpoint clínico explícito. |
| `validated_order_cancellation_requires_procedure` / 409 | Cancelación de validaciones requiere procedimiento futuro. |
| 403 | Falta autorización, actor/membresía activos o se intentó autoaprobar. |
| 404 | Identidad ajena/inexistente o línea fuera de su orden. |
| 422 | Formato inválido, orden vacía o transición ordinaria prohibida. |

OpenAPI contiene operaciones, entradas/revisiones, progreso, evidencia y ampliaciones de cabecera/consulta operativa/resultados. Inventario comprobado: **119 rutas Laravel, 111 operaciones OpenAPI y 104 rutas protegidas por permiso**. Las comprobaciones automáticas contrastan inventarios con rutas reales.

## 6. Transacciones y concurrencia

Orden de bloqueo: cabecera exclusiva, línea exclusiva, solicitud/dependientes y autorización efectiva. Finalización de orden bloquea cabecera y todas sus líneas por ID. Captura, validación, rectificación, inicio, composición y cancelación comparten la cabecera. Lecturas de progreso usan bloqueo compartido y conjuntos coherentes.

clinical_revision es independiente de resultados: cambios de composición/solicitud/decisión/resolución la avanzan mediante triggers; finalizar una captura no usa su revisión para representar cambios de otra línea. Los clientes deben recargar y decidir cómo trasladar cambios, sin reintentos ciegos. Las validaciones siguen gobernadas por su revisión exacta; una rectificación invalida la elegibilidad de finalización. La evidencia de cierre fija el conjunto completo, aunque otra publicación sea vigente.

Dieciséis carreras usan procesos y conexiones PostgreSQL independientes y verifican espera real mediante pg_stat_activity: validación/finalización, rectificación/finalización y el orden inverso, dos finalizaciones individuales, dos cierres, solicitud/validación en ambos órdenes, aprobación/cierre, dos aprobaciones, rechazo/aprobación, cancelación/cierre, adición/cierre, cierre/adición y eliminación, revocación de permiso/aprobación y membresía/cierre. Las carreras anteriores BE-S2-03/04/05/06/07 se conservan. La competencia ordinaria completed/cancelled ahora comprueba que completed exige cierre explícito; la carrera real entre cierres está en la nueva suite.

## 7. Dataset demostrativo

DatabaseSeeder añade `LaboratoryFinalizationSeeder` después de LaboratoryValidationSeeder, conservando procedencia, bloqueo de producción y transacción exterior. Crea exclusivamente laboratorio BE08, dos usuarios propios (solicitante y aprobador), membresías/permisos directos propios, suscripción, sucursal, paciente, área, lista no predeterminada, precio activo y examen publicado por ClinicalExamService. Texto ficticio, sample_mode none y sin rangos diagnósticos. Todas las escrituras clínicas usan servicios/acciones reales.

Escenarios: validado y finalizado individualmente con cabecera abierta; captura pendiente; excepción pendiente, aprobada y rechazada; cierre de dos líneas validadas; cierre mixto; cierre desde pending con dos excepciones. Los cierres conservan total positivo de 50,00 y no fabrican un estado de pago. No se fabrican legacy en una base limpia: su escenario se prueba con actualización real de esquema anterior.

La identidad `finalization_scenario/be08-v1` controla toda la provisión inicial atómica. Reejecutar no sobrescribe órdenes, decisiones, permisos, precios, contraseñas o listas de datos existentes. No adopta identidades sin procedencia ni modifica laboratorios anteriores. Se preservan los escenarios y descuentos previos. El CLI sobre base aislada limpia ejecutó migrate:fresh y db:seed dos veces: confirmó snapshots idénticos de **todas las tablas**, SHA-256 `ca3d5b7fd9def332bb1ac6fdae4582b51db6c7e9bff1fee8f7f423c2584e230c`. Dataset total: **17 publicaciones, 30 órdenes, 67 líneas, 47 requisitos, 4 muestras, 11 capturas, 30 resultados, 9 validaciones, 2 rectificaciones, 3 profesionales, 6 solicitudes, 5 decisiones, 8 resoluciones, 3 cierres y 0 excepciones legacy**.

## 8. Verificación aislada

Se utiliza exclusivamente Compose `donqer-be-s2-08` con `deploy/testing/compose.yaml`: PostgreSQL 17 en tmpfs, red interna sin puertos publicados y PHP 8.4 con código/configuración de testing en lectura y cachés temporales. Se mantienen las guardas BE-S2-02 y no se ejecutan suites simultáneas sobre la base.

```bash
docker compose -p donqer-be-s2-08 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-08 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalFinalization
docker compose -p donqer-be-s2-08 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never
docker compose -p donqer-be-s2-08 -f deploy/testing/compose.yaml down --remove-orphans
```

La batería dirigida del código corregido pasó con **392 casos y 2.972 aserciones**, incluyendo 56 casos de finalización con 313 aserciones y las 16 carreras reales. La comprobación adicional de professional_required pasó con **1 caso y 5 aserciones**. Pint verificó **78 archivos PHP** creados/modificados, incluidos los cambios BE07 previos; git diff --check pasó. La batería adicional de contratos de filtros/búsqueda y persistencia demo pasó con **27 casos y 303 aserciones**, conservando el conteo propio de las cinco validaciones BE07 y el total ampliado de nueve. **Resultado completo final:** PHPUnit 12.5.35, PHP 8.4.25 y PostgreSQL 17.11. **3.217 casos, 20.385 aserciones, cero errores, cero fallos y tres omisiones SQLite existentes.** Duración: **10 min 51,647 s**.

| Suite / subconjunto | Casos | Aserciones | Errores / fallos | Omitidos |
| --- | ---: | ---: | ---: | ---: |
| Unit | 46 | 70 | 0 / 0 | 0 |
| Feature | 3.171 | 20.315 | 0 / 0 | 3 |
| Total | 3.217 | 20.385 | 0 / 0 | 3 |
| ClinicalFinalization, incluido en Feature | 57 | 318 | 0 / 0 | 0 |
| Concurrencia BE08, incluida en ClinicalFinalization | 16 | 128 | 0 / 0 | 0 |
| ClinicalValidation BE07, incluido en Feature | 55 | 277 | 0 / 0 | 0 |
| Dataset, incluido en Feature | 11 | 822 | 0 / 0 | 0 |

Las omisiones corresponden a intención numérica en SQLite y dos comprobaciones de sus triggers de descuentos; no se omitió clínica ni concurrencia PostgreSQL. La suite completa incluye los ajustes de contratos y el conteo demo ampliado. Las cifras BE07 anteriores permanecen como antecedentes históricos; esta tabla corresponde al código final conjunto. El proyecto temporal se retiró mediante down --remove-orphans después de preservar los reportes. JUnit final: `/tmp/be08-results/full.xml`; log: `/tmp/be08-full.log`; batería dirigida: `/tmp/be08-results/directed.xml` y `/tmp/be08-directed-final.log`; contratos: `/tmp/be08-results/contracts.xml` y `/tmp/be08-contracts-final.log`; integración profesional: `/tmp/be08-professional-final.log`; CLI: `/tmp/be08-demo-check.php` y `/tmp/be08-demo-check.log`; Pint: `/tmp/be08-pint-final.log`; retirada: `/tmp/be08-cleanup.log`.

Los diagnósticos previos se conservan separados en `/tmp/be08-full-initial.log`, `/tmp/be08-full-before-contracts.log`, `/tmp/be08-results/full-before-contracts.xml` y los logs de baterías iniciales; no se presentan como ejecuciones finales. La última repetición completa responde a los fallos de expectativas ya corregidos. No se ejecutaron migraciones ni seeders en desarrollo/producción, ni commit/push. Cobertura: finalización vigente, preliminar/inicio ausente/rectificación, revisiones, autoaprobación, motivos, duplicados, obsolescencia, rechazo/repetición, validaciones históricas, excepción legacy/historical, estados de cabecera, aislamiento/FK, inmutabilidad SQL, rollback de auditoría, recursos/capacidades, dataset y carreras reales. Las pruebas de esquemas anteriores retiran y restauran primero la migración dependiente; excluyen el contador técnico nuevo al comparar atributos anteriores sin alterar las aserciones de negocio.

## 9. Archivos y contrato BE-S2-09

Creados: migración BE08; modelos ClinicalExceptionRequest, ClinicalExceptionDecision, ClinicalLineResolution, ClinicalOrderFinalization; ClinicalResolutionService y ClinicalFinalizationGuard; ClinicalFinalizationController; ClinicalFinalizationDocumentation; LaboratoryFinalizationSeeder; pruebas ClinicalResolutionTest, ClinicalFinalizationApiTest, ClinicalFinalizationIntegrityTest, ClinicalHistoricalResolutionTest y ClinicalFinalizationConcurrencyTest; este documento.

Modificados por integración: modelos de orden/línea; acciones de eliminación/estado; servicios de muestras/resultados/validación; controladores de órdenes/muestras/resultados; recurso de cabecera; catálogo RBAC y rutas; OpenAPI de órdenes/muestras/resultados; DatabaseSeeder y pruebas de dataset, inventarios, RBAC, contratos y migraciones progresivas. El trabajo BE07 anterior permanece sin atribuir sus archivos nuevos a esta entrega.

Para una emisión completa basada en este cierre, BE-S2-09 debe usar su ID y resoluciones históricas, nunca el puntero clínico vigente. El rector permite una futura emisión parcial autorizada antes de cerrar toda la orden: puede tomar las resoluciones individuales inmutables disponibles, sin presentar pendientes como terminados; sus reglas y permisos de emisión pertenecen a BE-S2-09. Esta entrega no exige un cierre completo para diseñar ese flujo ni implementa emisiones. Para validated_result debe consumir la evidencia BE07 identificada, sus valores, contexto y referencias inmutables bajo permisos clínicos apropiados. authorized_exception debe distinguirse expresamente y conservar motivo/actores mediante lectura autorizada; no autoriza emitir resultados preliminares como validados. completed histórico sin evidencia no puede presentarse como cierre nuevo autorizado.

Quedan separados informes/PDF, entrega, firma certificada, cobros/facturas, cancelación excepcional, reapertura/anulación de resolución y corrección posterior. Un procedimiento futuro debe conservar cierre y validaciones anteriores y volver a exigir validación/finalización cuando corresponda; no existe un endpoint ordinario de reapertura. El despliegue posterior requiere migración y actualización RBAC explícita en entorno autorizado, además de provisionar permisos sensibles deliberadamente. Esta sesión no realiza esos pasos ni commit/push.

## Actualización posterior — BE-S2-09.1

BE-S2-09.1 añade emisión explícita de PDFs parciales desde resoluciones individuales y completos desde el cierre exacto, sin emitir automáticamente al finalizar. Conserva evidencias BE07/08, snapshot de presentación, archivo privado/hash e historial independiente. El usuario aprobó confirmar identidad actual de órdenes antiguas sin presentarla como identidad histórica; las altas nuevas capturan identidad y área prospectivamente. No se agregan entrega ni reglas comerciales. Consultar [BE-S2-09.1 — Generación y emisión de informes](BE-S2-09.1-generacion-emision-informes.md) para su contrato y verificación. Las cifras de esta entrega BE08 permanecen como antecedentes históricos.
