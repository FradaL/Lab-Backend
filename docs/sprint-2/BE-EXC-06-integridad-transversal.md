# BE-EXC-06 — Integridad transversal de excepciones clínicas

Fecha: 2026-10-29. Estado técnico: **IMPLEMENTADO Y PROBADO en el alcance backend BE-EXC-06**. No supone cierre administrativo o despliegue.

## Fuente, alcance y versiones

Solicitud «Donqer Lab — BE-EXC-06: Implementación de garantías transversales», adjunto `a18e8834-f194-412d-b778-0817c61be535`. Árbol inicial backend `e510a0b`; producto `dfcf5a2`, comprobados antes de editar. Se conserva el archivo personal `laravel/donqer-lab.code-workspace`, que ya estaba sin seguimiento.

Fuentes: [DF-019 APROBADO 1.4](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), [DF-016 APROBADO 1.0](../../../donqer-lab-product/03-decisiones/DF-016-finalizacion-clinica.md) y [plan PROPUESTO 1.4, BE-EXC-06](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md#be-exc-06--pruebas-de-integridad-aislamiento-y-concurrencia). No se modifican las fichas aprobadas ni sus decisiones. La evidencia de implementación no supone aprobación funcional, cierre administrativo de una tarjeta o despliegue.

Se reutilizan BE-EXC-02/03/04/05, [BE-S2-08](BE-S2-08-finalizacion-clinica-ordenes.md), [BE-S2-09.1](BE-S2-09.1-generacion-emision-informes.md) y la separación de entrega BE-S2-09.2. No se agregan flujos, estados clínicos, permisos, rutas, condiciones financieras, reaperturas ni correcciones posteriores al cierre.

## Diagnóstico y reproducción antes de corregir

Se agregaron primero `ClinicalTransversalIntegrityTest` y `ClinicalEvidenceReviewConcurrencyTest`, y se ejecutaron contra el código inicial, sin la nueva migración. Artefactos: `/tmp/be-exc-06-artifacts/reproduction.xml` y `reproduction.log`.

Resultado inicial: **54 pruebas, 138 aserciones, 24 fallos esperados, cero errores y omisiones**:

- Nueve fallos: UPDATE, DELETE y renombrado SQL de eventos `clinical_replacement.recorded`, `clinical_reason.adopted` y `clinical_reason.local_review_evaluated` no eran rechazados. Las protecciones anteriores de resultados, validación, finalización e informes sí respondían.
- Doce fallos: rechazo, revisión de evidencia, finalización de línea y cierre de orden no protegían de forma autónoma suscripciones ausentes, futuras o vencidas. Solicitud y aprobación ya comprobaban acceso mediante la política de motivos; se conserva esa protección y se homogeneiza la entrada del servicio.
- Un fallo: `ClinicalExceptionEvidenceGuard::remember()` podía persistir una revisión como escritor público autónomo.
- Dos fallos: una revisión SQL que esperaba mientras otra transacción aprobaba o rechazaba utilizaba el estado `pending` leído antes del bloqueo y era aceptada después de la decisión.

La reproducción de concurrencia usa `pcntl_fork`, reconexión PostgreSQL independiente, PID del backend y comprobación efectiva de `pg_stat_activity.wait_event_type='Lock'` antes de liberar la transacción escritora. No es una simulación secuencial.

## Correcciones

### Auditoría PostgreSQL

Migración aditiva `2026_10_29_180000_harden_clinical_transversal_guarantees.php`. Reemplaza la función existente `protect_clinical_resolution_audit()` y reutiliza su trigger: no añade otro trigger sobre `audit_logs`.

Conserva la protección anterior y agrega los prefijos literales `clinical_replacement.` y `clinical_reason.` mediante `starts_with`. Se impiden UPDATE, DELETE y renombrado desde/hacia esos espacios. No se reescriben filas ni eventos históricos; los eventos ajenos mantienen sus mecanismos anteriores. Las pruebas comprueban también nombres parecidos que no pertenecen a los prefijos.

### Revisiones concurrentes

La misma migración reemplaza `guard_clinical_exception_evidence_review()`. Obtiene primero únicamente el contexto inmutable de la solicitud y adquiere bloqueos en orden **orden → línea → solicitud**; luego lee y bloquea la solicitud vigente. Los bloqueos se derivan del contexto original, no de los vínculos proporcionados por el insert. Comprueba estado pendiente, contexto, actor, justificación, snapshot actual y reglas de sucesión existentes después de la espera.

No cambia la semántica de evaluaciones de viabilidad ni duplica M01–M07 en SQL. La aprobación/rechazo y la revisión canónica conservan el bloqueo de catálogo previo y sus controles de revisiones y autorización.

### Autorización interna

`ValidationAuthority::access()` centraliza el control ya utilizado por informes: laboratorio activo, usuario y membresía activos del contexto seleccionado, permisos efectivos y una suscripción activa que cumpla `Subscription::allowsAccess()`. Mantiene los bloqueos compartidos correspondientes dentro de la transacción.

`ClinicalResolutionService` lo usa en solicitud, aprobación/rechazo, revisión, finalización de línea, cierre y lecturas. `ReplacementAuthority` y `ReportAuthority` delegan en esa misma comprobación. No se crean permisos ni se cambia el middleware o las rutas. El control de suscripción no incorpora pago o crédito del paciente a la finalización clínica.

### Escritor de revisiones

Se retira `remember()` de `ClinicalExceptionEvidenceGuard`; el guard conserva únicamente lectura y comprobación de elegibilidad. La persistencia queda en `ClinicalResolutionService::rememberEvidence()`, privado, invocado exclusivamente desde solicitud y revisión clínicas.

Sus precondiciones están documentadas: transacción, bloqueos, acceso, estado/revisiones vigentes, política de evidencia y, para revisión explícita, token observado. La selección estructurada original se guarda antes de la revisión inicial. Los comandos conservan auditoría atómica. No se expone un escritor público alternativo ni se duplica la política clínica.

## Archivos de implementación

```text
laravel/database/migrations/2026_10_29_180000_harden_clinical_transversal_guarantees.php
laravel/app/ClinicalFinalization/ClinicalResolutionService.php
laravel/app/ClinicalFinalization/ClinicalExceptionEvidenceGuard.php
laravel/app/ClinicalValidation/ValidationAuthority.php
laravel/app/ClinicalReplacements/ReplacementAuthority.php
laravel/app/ClinicalReports/ReportAuthority.php
laravel/tests/Feature/ClinicalFinalization/ClinicalTransversalIntegrityTest.php
laravel/tests/Feature/ClinicalFinalization/ClinicalEvidenceReviewConcurrencyTest.php
```

`source-manifest.json` registra las sumas SHA-256 de las ocho fuentes PHP sobre el commit inicial, sin atribuir los cambios locales a ese commit. Durante las selecciones finales no se modificaron las fuentes PHP. También se actualizan este informe, el README backend y los seguimientos de producto; las fichas DF-019/DF-016 permanecen idénticas.

## Verificación específica

La selección final específica amplía la reproducción con lecturas internas, preservación de filas al aplicar la migración y ausencia de triggers duplicados: **67 pruebas/248 aserciones satisfactorias, cero fallos, errores u omisiones**, en `targeted-final.xml/log`. La selección intermedia de 65 pruebas/230 aserciones no se suma al total final. Durante la revisión de la nueva autorización compartida se detectó una inversión de bloqueos en las lecturas: autorización antes de orden, frente a orden antes de autorización en escrituras. Dos pruebas adicionales reprodujeron el interbloqueo (`40P01`, dos fallos/8 aserciones en `read-lock-reproduction.xml`). Se corrigió antes de la ejecución final: las lecturas también bloquean primero la orden. Las selecciones amplias en curso se interrumpieron y reiniciaron; sus logs `interrupted-*` no constituyen resultados de regresión.

Las nueve carreras nuevas comprueban:

1. Aprobación antes de revisión SQL: rechazo `23514`.
2. Rechazo antes de revisión SQL: rechazo `23514`.
3. Aprobación antes de revisión por servicio: conflicto de revisión clínica.
4. Rechazo antes de revisión por servicio: conflicto de revisión clínica.
5. Evidencia nueva antes de revisión SQL: snapshot rechazado.
6. Evidencia nueva antes de revisión por servicio: token obsoleto rechazado.
7. Revocación de permiso mientras el servicio espera: denegación `403`.
8. Lectura de finalización esperando una orden cuyo escritor después revoca permisos: `403`, sin interbloqueo.
9. Lectura de excepciones en el mismo intercalado: `403`, sin interbloqueo.

Todos verifican bloqueo real, conservación de la revisión original y ausencia de decisiones/resoluciones adicionales. Las evaluaciones, autoaprobación, vínculos tenant, rollback de auditoría y obsolescencia de captura siguen cubiertos por las suites originales.

## Resultados PostgreSQL y contratos

Se completaron tres selecciones disjuntas con fuentes PHP fijas. La unión por clase/nombre de caso comprobó **820 pruebas únicas, 16.224 aserciones, cero fallos, errores u omisiones**. Artefactos `/tmp/be-exc-06-artifacts/{clinical,reports,consumers}.xml/log` y `results.json`.

| Selección final | Pruebas | Aserciones | Carreras reales | Duración de la selección |
| --- | ---: | ---: | ---: | --- |
| Clínica, reposición, catálogo completo y SaaS | 463 | 13.169 | 81 | 17:42.612 |
| Informes y entrega | 99 | 551 | 15 | 09:48.629 |
| Consumidores relacionados | 258 | 2.504 | 16 | 11:31.682 |
| Unión sin duplicados | **820** | **16.224** | **112** | Selecciones paralelas; no sumar duraciones |

La selección clínica comprende 201 casos de finalización (incluidos los 67 específicos BE06), 42 de reposición, 191 de motivos y 29 de autoridad SaaS. Informes comprende 66 casos y entrega 33. Consumidores comprende muestras, configuración/captura, composición, seeders, auditoría, middleware, pipeline SaaS y suscripciones. Los XML identifican cada caso y sus aserciones; no se atribuye cobertura a una garantía únicamente por el total satisfactorio.

Las **112 carreras** proceden de las clases de concurrencia y del caso de cambio de incidencia durante render de BE05; se verificó su ejecución con procesos/conexiones independientes. Incluyen las nueve BE06, tres BE04, dieciséis BE08, nueve de integración de motivos, cuatro BE03, cuarenta de catálogo/SaaS, nueve de informes, seis de entrega, nueve de muestras y siete de composición. `results.json` enumera las clases y cantidades.

Los once PDF sintéticos finales pasan **53 comprobaciones adicionales con Poppler**, registradas junto a sus hashes en `pdf-content.json`; no se suman a las aserciones PHPUnit. La preservación de bytes de formato 1 e idempotencia está probada por `ClinicalExceptionReportTest`, además de snapshots e integridad SQL de informes.

Las reproducciones iniciales (54 casos con 24 fallos esperados y dos casos de interbloqueo), selecciones específicas y ejecuciones interrumpidas son evidencia separada: no se suman a la regresión final. El análisis anterior de BE05 tampoco se suma. Los cuatro proyectos temporales se retiraron al finalizar; no quedaron bases de esta entrega en ejecución.

Entorno: PHP **8.4.25**, PostgreSQL **17.11** (`postgresql-version.txt`), Compose `deploy/testing/compose.yaml`, PostgreSQL efímero en tmpfs, red interna sin puertos publicados, código montado de sólo lectura y `.env.testing.example` sobre el `.env` del contenedor. Cada selección usa un proyecto y una base independientes. No se usa el `.env` local ni se migran bases compartidas. El guard de pruebas verifica ambiente, conexión y versión del servidor antes de permitir migraciones.

Selecciones: `clinical` (ClinicalFinalization, ClinicalReplacements, ClinicalReasons completo y SaasAccess); `reports` (ClinicalReports y ReportDelivery); `consumers` (muestras, composición, resultados/configuración, seeders, AuditWriter/AuditLog, middleware, pipeline SaaS y suscripciones). Los comandos siguientes reproducen las selecciones finales.

Comandos reproducibles desde la raíz del backend, con imágenes locales ya disponibles. No ejecutar selecciones simultáneas sobre la misma base:

```sh
mkdir -p /tmp/be-exc-06-artifacts/final-pdfs

docker compose -p donqer-be-exc-06-clinical -f deploy/testing/compose.yaml run --rm -v /tmp/be-exc-06-artifacts:/artifacts tests php vendor/bin/phpunit --do-not-cache-result --colors=never --log-junit /artifacts/clinical.xml tests/Feature/ClinicalFinalization tests/Feature/ClinicalReplacements tests/Feature/ClinicalReasons tests/Feature/SaasAccess

docker compose -p donqer-be-exc-06-reports -f deploy/testing/compose.yaml run --rm -v /tmp/be-exc-06-artifacts:/artifacts -e BE_EXC05_ARTIFACT_DIR=/artifacts/final-pdfs tests php vendor/bin/phpunit --do-not-cache-result --colors=never --log-junit /artifacts/reports.xml tests/Feature/ClinicalReports tests/Feature/ReportDelivery

docker compose -p donqer-be-exc-06-consumers -f deploy/testing/compose.yaml run --rm -v /tmp/be-exc-06-artifacts:/artifacts tests php vendor/bin/phpunit --do-not-cache-result --colors=never --log-junit /artifacts/consumers.xml tests/Feature/PhysicalSamples tests/Feature/ClinicalExams/ClinicalExamTest.php tests/Feature/ClinicalResults/ClinicalResultTest.php tests/Feature/OrderComposition tests/Feature/LaboratoryOrderSeederTest.php tests/Feature/DemoDevelopmentSeederTest.php tests/Feature/Audit/AuditWriterTest.php tests/Feature/Models/AuditLogPersistenceTest.php tests/Feature/Http/Middleware/EnsureActiveSubscriptionTest.php tests/Feature/Saas/SaasRequestPipelineTest.php tests/Feature/Subscriptions
```

Retirar los tres proyectos con `docker compose -p <proyecto> -f deploy/testing/compose.yaml down --volumes --remove-orphans`; el proyecto `donqer-be-exc-06` se usa únicamente para reproducción, pruebas específicas y generación de OpenAPI. Los proyectos fueron creados expresamente para esta entrega y sus bases son desechables.

OpenAPI regenerado: **183 operaciones**, sin operaciones, permisos o contratos nuevos. El JSON es idéntico al artefacto BE05: SHA-256 `1bd2274f4410f5124f072007f8d587285ff652a159dd3c13fe7d6d75c63141d4`. Las respuestas existentes de autorización/conflicto se conservan; no se modificaron anotaciones innecesariamente.

## Riesgos residuales y límites

- La protección de permisos y de mínimos M01–M07 corresponde al comando clínico canónico; una cuenta SQL administrativa con capacidad de desactivar triggers no pertenece a esa frontera. No se declara autorización completa de SQL arbitrario por los triggers.
- Las pruebas con bloqueos demuestran los intercalados ejecutados, no ausencia matemática de cualquier deadlock posible. Se conserva el orden de bloqueo existente, y sólo se adelantan al estado los bloqueos de la revisión SQL.
- PostgreSQL y almacenamiento privado de PDF no forman una transacción distribuida. Se conserva la compensación ante fallos controlados y cambios de snapshots durante render; una interrupción abrupta puede requerir conciliación de archivos privados huérfanos.
- No se acredita frontend, despliegue, concesiones reales, correcciones D10 ni implementación completa de otras brechas D11.
- No se han ejecutado migraciones sobre bases compartidas, commits ni push.

## Recomendaciones de despliegue

Desplegar código y migración conjuntamente mediante el procedimiento habitual, después de revisar la evidencia. Confirmar PostgreSQL compatible, respaldo y estado de migraciones; aplicar la migración aditiva en una ventana controlada. Verificar los dos cuerpos de funciones y que el número de triggers no aumentó. No requiere backfill, regeneración de PDF, reclasificación o modificación de solicitudes anteriores.

El `down()` restaura los cuerpos anteriores sin tocar datos, pero elimina estas garantías nuevas: no debe utilizarse como reparación rutinaria de historia. Una reversión debe coordinar esquema y código y preservar los archivos e historia clínica. Las tareas operativas de despliegue no fueron ejecutadas en esta entrega.

## Matriz final de cumplimiento

| Requisito | Implementación actual | Evidencia | Estado | Acción pendiente |
| --- | --- | --- | --- | --- |
| UPDATE/DELETE de auditoría clínica rechazados | Función existente ampliada a los prefijos omitidos; historia intacta | `ClinicalTransversalIntegrityTest`: SQL directo, renombrado, eventos ajenos y preservación al migrar | Implementado y probado | Aplicación operativa de la migración, fuera de esta entrega |
| Revisiones concurrentes con estado vigente | Orden → línea → solicitud; lectura de estado y snapshot después de esperar | Nueve carreras de `ClinicalEvidenceReviewConcurrencyTest`; evaluación/token obsoleto BE04 | Implementado y probado | Ninguna en el alcance backend verificado |
| Suscripción en llamadas internas | `ValidationAuthority::access()` compartida, sin nuevos permisos | 32 combinaciones de cuatro estados de suscripción y ocho operaciones; middleware/SaaS | Implementado y probado | Ninguna |
| Escritor de revisiones protegido | Persistencia privada al servicio, con precondiciones documentadas y comandos autorizados | Invocación autónoma rechazada; pruebas existentes de solicitud/revisión sin permiso | Implementado y probado | Ninguna |
| Concurrencia y rollback | Bloqueos canónicos y auditoría en transacción | 112 carreras; fallos inducidos en solicitud, revisión de catálogo, decisión, resolución, vínculos y emisión según sus suites | Implementado y probado | Ninguna |
| Aislamiento y separación de funciones | Contexto, membresías, permisos efectivos, aprobación independiente y FK compuestas | `ClinicalReasonIntegralAuditTest` con actor en dos laboratorios; integridad BE03/BE08 y SaaS | Implementado y probado | Ninguna |
| Historia, snapshots y PDF | Versiones originales, revisiones/evaluaciones inmutables, cierre explícito, idempotencia y compensación | BE03/04/08; 99 casos de informes/entrega y 11 PDF/53 comprobaciones; pago/crédito separados | Implementado y probado | Comprobaciones operativas del entorno al desplegar |
| Regresión transversal y contratos | Selecciones disjuntas y OpenAPI idéntico | 820 casos/16.224 aserciones; 183 operaciones; manifiesto de fuentes sin cambios | Implementado y probado | Ninguna |

**BE-EXC-06 queda completada técnicamente en este alcance backend.** No requiere nuevas decisiones de producto ni quedan preguntas funcionales bloqueantes. DF-019/DF-016 no cambian. Las recomendaciones operativas y límites anteriores se conservan; no se declara producción, cierre administrativo, frontend o correcciones posteriores al cierre.
