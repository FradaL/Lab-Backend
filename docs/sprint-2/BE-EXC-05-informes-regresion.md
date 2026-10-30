# BE-EXC-05 — Informes y regresión de excepciones clínicas

Fecha: 2026-10-29. Solicitud: adjunto `1c2ba89a-49d0-42e9-8daa-d1c6f3f41fb3`. Tarjeta indicada por el usuario: [BE-EXC-05](https://trello.com/c/aWGBiMnm), prioridad P0; su contenido/estado no pudieron consultarse mediante la herramienta disponible y no se modificó el tablero. Bases: backend `56f56c2`, producto `0f289d9`; único archivo local previo sin seguimiento: `laravel/donqer-lab.code-workspace`, conservado.

Fuente principal: [DF-019 APROBADO 1.4](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), que conserva las condiciones EXC-M01–M07, EXC-12 e I.B–I.F de sus antecedentes. También [DF-016 APROBADO 1.0](../../../donqer-lab-product/03-decisiones/DF-016-finalizacion-clinica.md), [DF-003 R1–R10](../../../donqer-lab-product/03-decisiones/DF-003-informes-parciales.md), [DF-011 R4](../../../donqer-lab-product/03-decisiones/DF-011-formato-informes.md) y [plan vigente](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md#be-exc-05--no-realizado-en-informes-y-evidencia-de-emisión). DF-019 1.3 no se usa como fuente principal; se leen sus reglas conservadas dentro del expediente aprobado 1.4. No se modifica ninguna decisión aprobada.

## Diagnóstico contractual

BE-S2-09.1 ya emite explícitamente parciales/completos desde resoluciones exactas, con validación vigente para los valores, cierre explícito para completos, autorización y revalidación antes/después del render, idempotencia, archivos privados y auditoría. BE08/BE04 conservan resolución, actores independientes y evidencia clínica; registrar evidencia o una solicitud no crea resolución terminal.

Brechas observadas:

- `report_line_snapshot()` guardaba solicitud/decisión y resolución, pero no la referencia global/local ni la versión original del motivo. Su unión con BE02/BE04 no estaba archivada en la emisión.
- El renderer presentaba la excepción genérica y publicaba `decision.reason`, una justificación interna de aprobación sin autorización específica de visibilidad al paciente.
- El parcial sólo describía su alcance de líneas seleccionadas; no archivaba ni identificaba todos los exámenes clínicamente pendientes, pese a DF-003 R6/DF-011 R4.

Conciliación: DF-003 R2 exige resolución definitiva para incluir una **línea clínica**; R6 y DF-011 R4 autorizan identificar pendientes **sin valores no validados**. Se conserva el bloqueo de una incidencia como línea de resultados y se permite su identificación informativa separada dentro de un parcial que sí tenga líneas resueltas seleccionadas. No hay autorización para un informe de sólo incidencias ni para publicar valores parciales no autorizados. No se incorpora una habilitación adicional por laboratorio: DF-003 R10 la descarta en V1. No se detectó una decisión funcional pendiente que impidiera este alcance.

## Representación clínica

| Situación | Línea clínica emitible | Representación prospectiva |
| --- | --- | --- |
| Resolución `validated_result` con validación vigente exacta | Sí, sujeta a emisión/selección/cierre y permisos existentes | Mismos valores, precisión, referencias, contexto, profesional y observaciones autorizadas de BE091. |
| Incidencia BE03 sin resolución | No | Identidad del examen y **Incompleto — situación clínica pendiente** en sección informativa del parcial; indica si el procesamiento comenzó. Sin valores, motivo interno, evidencia ni justificaciones. |
| Examen sin resolución ni incidencia registrada | No | **Pendiente de resolución clínica**, separado del estado de procesamiento. Captura o validación por sí solas no se interpretan como incidencia ni finalización. |
| Resolución `authorized_exception` con referencia estructurada original | Sí, sujeta a las reglas existentes | **No realizado. No constituye un resultado clínico.** Nombre clínico de la versión original del motivo. Sin valores ni validación inventados. |
| Excepción genérica histórica sin referencia estructurada | Conserva el tratamiento histórico autorizado | **Excepción clínica autorizada** y motivo textual histórico; no se infiere una asociación ni se reclasifica como No realizado desde nombres o catálogo vigente. |

La presencia de evidencia/intenciones BE03 identifica la incidencia igual que BE04. `processing_started` es un hecho independiente; no se inicia procesamiento por informar una incidencia. Una captura o validación sin resolución no habilita la selección, aunque tenga valores completos. El parcial no cierra línea ni orden; el completo sigue requiriendo todas las resoluciones y el cierre exacto de DF-016.

Para nuevos PDFs se limita la exposición de la excepción al estado y al nombre histórico del motivo. `decision.reason`, justificación de solicitud, descripción administrativa del motivo, datos de contacto/seguimiento y evidencia de viabilidad permanecen internos. No se añaden firmas, responsables profesionales ficticios, resultados ni permisos de publicación. La rama de resultados validados conserva su comportamiento.

## Snapshots y migración

Migración aditiva: `2026_10_29_164459_version_clinical_report_exception_snapshots.php`. No hace UPDATE/backfill de emisiones ni resoluciones y no modifica la migración BE091 original.

- `report_line_snapshot_v1()` conserva la definición original de snapshot. Las emisiones y líneas ya almacenadas no se recalculan.
- `report_line_snapshot()` produce nuevas líneas con `snapshot_version: 2` y `clinical_display` explícito. Conserva las claves/evidencias originales de resolución, validación, solicitud y decisión.
- Para excepciones estructuradas añade `exception.reason_reference`: origen, ID de referencia/identidad, código, ID/número de versión, definición original completa y selección/adopción global cuando corresponde. Consulta exclusivamente el `version_id` persistido en la solicitud, nunca la versión vigente ni una coincidencia textual.
- `exception.evidence_review_id` referencia la revisión inmutable BE04 utilizada por la solicitud ya aprobada, cuando existe. La evidencia clínica completa permanece en sus tablas inmutables; no se duplica ni se publica indiscriminadamente en el PDF.
- `report_pending_snapshot(order_id)` construye sólo identidades/estados informativos de líneas sin resolución: ID, código, nombre, estado y hecho de procesamiento. No obtiene ni publica resultados de esas líneas.
- Nueva columna privada `laboratory_report_issuances.pending_snapshot`: null en emisiones anteriores, array exacto en formato 2; `[]` en completos. Null significa que no se archivó ese dato, no que no existieran pendientes.
- Nuevo formato de emisión: `donqer-clinical-2`. Los registros/PDF `donqer-clinical-1` conservan sus bytes, snapshots, hashes y reintentos idempotentes. El servicio deriva el formato del contrato SQL, sin selector de formato controlado por cliente.

El guard SQL original se conserva mediante una copia versionada y se extiende con comprobaciones de inserción: sólo nuevas emisiones de formato 2, pendientes exactos del contexto actual y líneas exactas del nuevo snapshot. Se mantienen comprobaciones de tenant, resolución, validación, cierre, secuencia, identidad, líneas únicas y constraint diferido de completitud. Tampoco se pueden extender líneas de una emisión histórica de formato 1. UPDATE/DELETE de emisiones/líneas siguen prohibidos.

La migración comprueba que las cláusulas del guard/constraint a extender sean las esperadas; si no coinciden exige revisión técnica en lugar de omitir comprobaciones. El down restaura funciones/constraint originales únicamente si no hay emisiones de formato 2; con historia nueva exige respaldo/restauración explícitos. Los fixtures que reproducen esquemas anteriores retiran primero esta migración y la restauran después de BE04. La prueba histórica compara todas las columnas originales y bytes, reconociendo separadamente la adición nullable de metadatos.

No se crea un circuito de conversión comercial ni se reasocian solicitudes antiguas. Una emisión nueva puede usar una resolución histórica preservada, sin reaprobarla ni atribuirle evidencias que no tenía. Desactivar/publicar un motivo no modifica la referencia original ni PDFs emitidos. No se reconstruye el motivo desde el catálogo actual.

## Emisión, concurrencia y privacidad

Se reutiliza `ClinicalReportService::issue()` y su mutex de idempotencia. La preparación toma snapshots de líneas resueltas y pendientes bajo bloqueo de orden; renderiza fuera de la transacción; vuelve a comprobar ambos snapshots antes de registrar emisión, líneas y auditoría. Una incidencia registrada durante render puede cambiar el estado pendiente sin avanzar la revisión clínica de cabecera: también se detecta ese cambio y se devuelve `409 report_evidence_conflict`, compensando el archivo nuevo. El límite técnico existente de 8 MiB incluye ahora ambos conjuntos de evidencia del documento.

Un reintento idempotente devuelve la emisión original y sus bytes; no actualiza su formato ni pendientes. Una clave nueva crea otra emisión con evidencia prospectiva. No se regeneran PDFs por consultar, cambiar catálogo, cerrar una orden o descargar.

`PrivateReportStorage`, permisos, membresías/suscripciones activas, autenticidad pública mínima y protección de credenciales QR permanecen. La metadata del informe no expone `clinical_snapshot`, `pending_snapshot`, identidad privada, ruta o credencial. Descarga interna y preparación/confirmación de entrega siguen siendo operaciones diferentes. ReportDelivery consume la emisión y bytes/hash archivados; No realizado no concede una excepción comercial, no demuestra pago/crédito y no omite D02/D12. Ningún servicio financiero/política de entrega fue modificado.

## Contratos e impacto frontend

No se crean endpoints ni permisos. Entradas de emisión, idempotencia y entrega permanecen.

- `GET /api/v1/laboratory-orders/{order}/clinical-finalization`: cada línea añade `reporting` con estado, etiqueta, hecho de procesamiento, `eligible_line`, bloqueos, nombre del motivo y referencia/version original reducida. No expone la definición administrativa completa ni justificaciones internas. Es información de elegibilidad de línea; no concede permiso de emisión ni garantiza elegibilidad del documento completo/entrega.
- POST/GET de informes: metadata mantiene `line_count` como número de líneas clínicas resueltas; añade `pending_line_count` como conteo informativo separado, null para formato 1. `format_version` distingue los dos contratos archivados.
- PDF parcial: sección **EXÁMENES PENDIENTES — sin resultados clínicos publicados**, con examen/estado/procesamiento. No cambia `line_ids` ni convierte pendientes en líneas seleccionadas emitibles.
- Se conservan errores de selección, revisión, cierre, identidad, autorización, generación y almacenamiento BE091. `report_evidence_conflict` también cubre cambios del snapshot informativo durante render. No se inventa un error o permiso financiero nuevo.

FE-S2-11 puede consumir `reporting` junto con la incidencia BE04 sin deducir No realizado desde una solicitud pendiente o captura. FE-S2-12 debe mantener selección de líneas resueltas, reconocer formato/conteo de pendientes, conservar el PDF archivado y tratar 409 recargando contexto; no reconstruir un informe histórico desde catálogo actual. Requiere los permisos efectivos existentes de consulta clínica/emisión/descarga/entrega según la acción. El plan también referencia FE-S2-13 como consumidor de informes; no se cambian sus IDs, asignaciones ni tarjetas por inferencia. No se modifica frontend ni se declara integrada ninguna de esas interfaces.

OpenAPI fuente: `ClinicalReportDocumentation.php`, `ClinicalFinalizationDocumentation.php` y `ClinicalExceptionReportDocumentation.php`; incluye estados visibles, nuevos metadatos, esquema interno de snapshot y separación de pendientes. Las operaciones existentes permanecen.

## Pruebas y resultados

**Resultado final: IMPLEMENTADO Y PROBADO en el alcance backend BE-EXC-05.** Se ejecutaron tres selecciones disjuntas con fuentes fijas en PostgreSQL 17 efímero, red interna sin puertos publicados y código montado sólo lectura. **536 pruebas, 7.393 aserciones, cero fallos, errores u omisiones.** Los tres proyectos temporales se retiraron al terminar. Artefactos: `/tmp/be-exc-05-artifacts/`.

| Selección | Pruebas | Aserciones | Resultado / evidencia |
| --- | ---: | ---: | --- |
| Informes y entrega | 99 | 551 | Satisfactorio; `final-reports.xml/log`, duración 06:05.604. |
| BE08/BE03/BE04 y catálogo | 244 | 4.490 | Satisfactorio; `final-clinical.xml/log`, duración 09:20.950. |
| Muestras, configuración, resultados, composición y seeders | 193 | 2.352 | Satisfactorio; `final-consumers.xml/log`, duración 07:01.571. |
| Total de selecciones disjuntas | **536** | **7.393** | Se verificó que no hubiera casos duplicados entre selecciones. |
| BE05 específico, ya incluido en informes | 15 | 128 | No sumar al total; incluye carrera real durante render. |
| BE091, ya incluido en informes | 51 | 290 | Regresión íntegra del directorio previo, incluidas ocho carreras reales (8/78). |
| Entrega, ya incluida en informes y entrega | 33 | 133 | Políticas, permisos, preparación/descarga/confirmación, historia y concurrencia. |
| BE08/BE04, ya incluidos en clínica | 134 | 723 | Historia, HTTP, servicios, independencia, viabilidad y concurrencia. |
| BE03, ya incluido en clínica | 42 | 3.318 | Reposición, hechos tipados, evaluación formal, obsolescencia e integridad. |
| PDFs finales, comprobación adicional con Poppler | 11 | 53 comprobaciones de texto | `final-pdfs/*.pdf` y `pdf-content-final.json`; no se suman a las aserciones PHPUnit. |

La inspección ejecutó `pdftotext -layout` sobre PDFs reales y comprobó estado/motivo originales, No realizado, valores validados precisos, Incompleto con/sin inicio, pendiente de finalización y conservación genérica histórica. Verificó ausencia de justificaciones/evidencia internas, valores no validados y textos de versiones posteriores. El PDF archivado de formato 1 conserva deliberadamente su texto anterior y hash; no se redacciona ni regenera retroactivamente.

Selección dirigida intermedia: 14 pruebas/122 aserciones satisfactorias (`targeted-final.xml/log`), anterior a añadir el caso de validación sin finalización. Inspección intermedia: diez PDFs/48 comprobaciones. Las corridas iniciales de desarrollo detectaron un error de sintaxis SQL corregido y errores de preparación de fixtures (composición antes de iniciar, recarga canónica del modelo, transacción de DDL y selección por ID sin asumir orden de filas). Se conservan `targeted-initial.xml/log` y `targeted.xml/log`; no se presentan como corridas finales satisfactorias. La selección final vuelve a cubrir esos casos sin relajar las reglas clínicas.

OpenAPI habitual regenerado correctamente: **183 operaciones**, sin endpoints añadidos; referencias locales comprobadas por la regresión BE03 y contratos BE05. Copia en `api-docs.json` del directorio de artefactos; SHA-256 `1bd2274f4410f5124f072007f8d587285ff652a159dd3c13fe7d6d75c63141d4`. Formato Pint aplicado; sintaxis PHP y `git diff --check` comprobados. DF-019 y DF-016 conservan exactamente sus archivos aprobados.

Las pruebas específicas cubren global/local, No realizado sin resultados, mezcla con validación, referencia/version histórica tras desactivación/nueva publicación, archivo de formato 1 y reintento inalterados, emisión prospectiva de formato 2, excepción genérica sin reclasificación textual, Incompleto con/sin procesamiento, captura no validada excluida, validación sin resolución, bloqueo de selección/completo, privacidad, integridad SQL, rollback protegido, tenant, permisos y no omitir entrega comercial. Una carrera real con `pcntl_fork` y conexiones independientes comprueba un cambio de incidencia durante render sin revisión de cabecera, rechazo de snapshot obsoleto y eliminación del PDF no emitido.

Se conserva la regresión BE091 (PDF/identidad/almacenamiento/auditoría y ocho carreras), BE08, BE-EXC-03/04, catálogo, entrega, muestras, composición y seeders/fixtures históricos. No se afirma suite global del repositorio.

Comandos reproducibles desde backend-lab (una base distinta por selección; no ejecutar grupos simultáneos sobre la misma base):

```sh
docker compose -p donqer-be-exc-05 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalReports tests/Feature/ReportDelivery

docker compose -p donqer-be-exc-05-clinical -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalFinalization tests/Feature/ClinicalReplacements tests/Feature/ClinicalReasons/LocalClinicalReasonTest.php tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php tests/Feature/ClinicalReasons/ClinicalReasonReadTest.php tests/Feature/ClinicalReasons/ClinicalReasonIntegralAuditTest.php

docker compose -p donqer-be-exc-05-consumers -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/PhysicalSamples tests/Feature/ClinicalExams/ClinicalExamTest.php tests/Feature/ClinicalResults/ClinicalResultTest.php tests/Feature/OrderComposition tests/Feature/LaboratoryOrderSeederTest.php tests/Feature/DemoDevelopmentSeederTest.php
```

Para exportar PDFs sintéticos del test BE05: crear un directorio de artefactos, montarlo con `-v /tmp/be-exc-05-artifacts:/artifacts` y pasar `-e BE_EXC05_ARTIFACT_DIR=/artifacts/final-pdfs` al contenedor de informes, creando previamente ese subdirectorio. Cada PDF lleva un manifiesto `.expected.json` para la comprobación independiente con Poppler. Retirar cada proyecto después con `docker compose -p <proyecto> -f deploy/testing/compose.yaml down --volumes --remove-orphans`.

## Archivos y límites

Implementación: migración nueva; `ClinicalReportService`, `ClinicalReportRenderer`, `ClinicalResolutionService`, modelo `LaboratoryReportIssuance`; fuentes OpenAPI de informes/finalización y esquema nuevo `ClinicalExceptionReportDocumentation`; prueba nueva `ClinicalExceptionReportTest`. Adaptación del orden de dependencias en fixtures de ClinicalExamTest, ClinicalResultTest, PhysicalSampleTest, AtomicOrderCompositionTest, LaboratoryOrderSeederTest, ClinicalHistoricalResolutionTest y StructuredClinicalExceptionTest, sin debilitar sus garantías históricas. Este documento y seguimientos de producto conservan las fuentes/historia previas.

Inventario propio, relativo a backend-lab:

```text
docs/sprint-2/BE-EXC-05-informes-regresion.md
laravel/app/ClinicalReports/ClinicalReportService.php
laravel/app/ClinicalReports/ClinicalReportRenderer.php
laravel/app/ClinicalFinalization/ClinicalResolutionService.php
laravel/app/Models/LaboratoryReportIssuance.php
laravel/app/OpenApi/ClinicalReportDocumentation.php
laravel/app/OpenApi/ClinicalFinalizationDocumentation.php
laravel/app/OpenApi/ClinicalExceptionReportDocumentation.php
laravel/database/migrations/2026_10_29_164459_version_clinical_report_exception_snapshots.php
laravel/tests/Feature/ClinicalReports/ClinicalExceptionReportTest.php
laravel/tests/Feature/ClinicalExams/ClinicalExamTest.php
laravel/tests/Feature/ClinicalResults/ClinicalResultTest.php
laravel/tests/Feature/PhysicalSamples/PhysicalSampleTest.php
laravel/tests/Feature/OrderComposition/AtomicOrderCompositionTest.php
laravel/tests/Feature/LaboratoryOrderSeederTest.php
laravel/tests/Feature/ClinicalFinalization/ClinicalHistoricalResolutionTest.php
laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionTest.php
```

El JSON OpenAPI generado continúa ignorado por Git conforme a la configuración existente. En producto se añaden seguimientos a README, inventario, reglas de entrega/resultados, índices/registro de decisiones, índice/plan de especificaciones y AUD-002; sin modificar fichas aprobadas ni sus estados/versiones.

BE-EXC-05 cubre la representación prospectiva y regresión de informes aquí descrita. **BE-EXC-06 conserva un alcance independiente de verificación transversal** de EXC-01–EXC-13, siete motivos, gobierno SaaS/local, cambios de dependencias/catálogo/permisos concurrentes y consumidores. Estas pruebas aportan evidencia, sin cerrar administrativamente esa tarea ni acreditar integración frontend/operativa o despliegue.

Siguen fuera del alcance correcciones posteriores al cierre/informes corregidos D10, otras brechas generales de D11, integración/provisión/despliegue y concesiones reales. No se ejecutan migraciones/seeders en bases compartidas, no se modifica frontend, no se cambian D02/DF-016/DF-019 ni se realizan commits/push.
