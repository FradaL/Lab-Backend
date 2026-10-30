# BE-EXC-04 — Incidencias pendientes y elegibilidad terminal

Fecha: 2026-10-29. Bases observadas: backend `6f7de3a`, producto `bca0358`, con cambios locales previos de BE-EXC-03 preservados. Fuente: solicitud del usuario, adjunto `30d324ff-738e-4c3e-80f5-7357ccd857df`. Fuente funcional: [DF-019 APROBADO 1.4](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), EXC-M01–M07, EXC-09/12 e I.B–I.D; [plan BE-EXC-04](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md#be-exc-04--ampliar-solicitud-decisión-y-resolución-segura). Conserva DF-016 y sus reglas de cierre. Amplía BE08 y consume [BE-EXC-03](BE-EXC-03-reposicion-viabilidad.md); no incorpora otro circuito de muestras, resultados o excepciones.

## Diagnóstico y conciliación

`ClinicalResolutionService::decideException()` comprobaba motivo, permisos, separación de actores, estado/revisiones y validaciones históricas, pero creaba la decisión aprobada y `authorized_exception` sin consultar la evidencia/viabilidad BE03. Registrar intenciones/evaluaciones por sí solo no cerraba esa brecha. El plan vigente y DF-019 son compatibles: solicitar, revisar, aprobar, resolver y cerrar son operaciones diferentes. M03 dispensa la evaluación adicional al solicitar; no dispensa el bloqueo terminal por reposición pendiente viable.

El catálogo admite códigos personalizados y listas estructuralmente válidas pero vacías o sin consumidor clínico. Eso no certifica suficiencia. Este consumidor define códigos técnicos `m01`–`m07` para los motivos base y aplica sus mínimos fijos, independientemente de una lista administrativa vacía o alterada. No identifica motivos por nombre, descripción o texto histórico; no carga/adopta catálogo ni cambia versiones existentes. Para motivos personalizados, los identificadores de requisitos soportados se documentan abajo; una política vacía/desconocida bloquea el uso con `clinical_evidence_policy_unconfigured`. Su provisión debe realizarse explícitamente mediante el catálogo existente. No se convierte ningún motivo actual por inferencia.

No se localizó un documento independiente atribuido a Macario en los repositorios consultados. La reproducción prueba la brecha descrita en la solicitud: una intención pendiente formalmente viable, incluida muestra recibida sin aceptación, no puede terminar por excepción; M03 permite conservar la solicitud sin convertir esa dispensa en aprobación. No se atribuye acceso a una auditoría externa no disponible.

## Reglas implementadas

| Motivo base | Mínimo estructurado al solicitar |
| --- | --- |
| `m01` | Problema de muestra y evaluación vigente no viable de reposición; ausencia de evaluación no demuestra inviabilidad. |
| `m02` | Lo anterior y muestra/requisito afectados conservados en evidencia referenciada por su evaluación. |
| `m03` | Rechazo expreso tipado, fecha del hecho/registro, responsable y observaciones; no exige evaluación adicional para solicitar. |
| `m04` | Seguimiento documentado y evaluación clínica vigente de continuación no viable. Sin plazos/cantidad de intentos universales. |
| `m05` | Indicación médica documentada y actor/fechas del registro. Sin efectos comerciales automáticos. |
| `m06` / `m07` | Problema técnico, alternativas documentadas y evaluación vigente de alternativas no viable. |

Los hechos y sus actores/fechas se validan/reutilizan desde BE03. Una justificación del solicitante no sustituye esos registros. Los códigos son identificadores técnicos, no nuevas reglas clínicas ni una carga automática del catálogo.

Para personalizados, `evidence_requirements.requirements` admite estos identificadores consumibles: `sample_problem`, `replacement_assessment`, `affected_sample`, `patient_refusal`, `follow_up`, `continuation_assessment`, `medical_instruction`, `technical_problem`, `alternatives`, `technical_alternatives_assessment`. Problema de muestra implica evaluación de reposición; muestra afectada implica problema/evaluación vinculados; seguimiento implica evaluación de continuación; problema/alternativas técnicas exigen ambas evidencias y evaluación. Seleccionar una clasificación terminal o eliminar requisitos no permite omitir esos controles. Una lista vacía no habilita excepción.

Toda aprobación revalida evidencia, evaluaciones vigentes, todas las intenciones pendientes y dependencias originales/revisadas dentro de la transacción BE08, antes de insertar la decisión. Cada intención pendiente exige evaluación actual no viable: pendiente viable, evaluación ausente u obsoleta bloquean; también en M03/M05 y personalizados. Recibir/vincular material no lo acepta ni satisface artificialmente una intención. Se reutiliza la suficiencia/aceptación BE05 y la satisfacción material explícita BE03.

Permanecen los controles de referencia/versiones y revisiones de catálogo, usuario independiente, permiso efectivo, pertenencia tenant, revisiones de captura/procesamiento, validaciones históricas, línea sin resolución y orden abierta. Fallar no inserta decisión aprobada ni resolución, no cambia el pendiente a aprobado y no finaliza línea/orden. Rechazar continúa disponible aunque falle elegibilidad de aprobación.

## Incidencia y revisión inmutable

La incidencia es una proyección de evidencia/intenciones BE03 en una línea sin resolución: `none`, `pending` o `resolved`, separada de `processing_started`. No introduce estados técnicos de procesamiento ni resultados. `pending` puede presentarse como Incompleto, aclarando si el procesamiento comenzó. No realizado exige la resolución excepcional autorizada y no constituye resultado validado. Registrar evidencia/intención no inicia procesamiento.

La migración aditiva `2026_10_29_150442_add_clinical_exception_evidence_reviews.php` conserva en `clinical_exception_evidence_reviews` el snapshot inicial de dependencias de cada solicitud y las revisiones clínicas posteriores, con actor, fecha, justificación y tipo. Las referencias originales de catálogo siguen en las tablas BE02 existentes. No cambia solicitudes históricas, snapshots de resoluciones, funciones de informe ni PDFs.

`clinical_exception_dependencies(line_id)` combina el snapshot BE03 de hechos/material/requisitos con las últimas evaluaciones de cada sujeto. Incluye la identidad de cada evaluación: incluso una sucesora con igual resultado exige revisión de la solicitud. Comparar con la revisión original/última permite identificar las pendientes afectadas sin mutarlas ni borrar historia. Una revisión favorable del catálogo no sustituye esta revisión de evidencia; una revisión de evidencia no levanta restricciones del catálogo.

Cada revisión explícita avanza `clinical_revision` de la orden mediante el trigger existente de BE08; una pantalla de aprobación anterior no puede decidir sin recargar esa revisión. La solicitud original y su revisión propia se conservan. La revisión explícita requiere token observado y mínimos actuales suficientes, incluidas evaluaciones no obsoletas y ausencia de reposición pendiente viable. No fuerza aprobación ni repara revisiones obsoletas de captura/validaciones protegidas por BE08/D10. Una pendiente estructurada sin snapshot inicial exige revisión explícita actual: se registra como `review`, sin reconstruir ni atribuirle dependencias originales no observadas. No se añade rechazo/nueva solicitud automático ni migración comercial. Pendientes genéricas sin política/evidencia comprobable no conservan un acceso alternativo a aprobación; las resoluciones ya registradas se conservan.

FK compuestas protegen solicitud/laboratorio/orden/línea y membresía del actor. Triggers rechazan UPDATE/DELETE de las revisiones, contextos/snapshots ajenos u obsoletos y decisiones aprobadas sin revisión vigente; comprueban además las reposiciones pendientes ante inserción directa de decisión. El rollback de la migración exige ausencia de historia nueva. Los fixtures que recrean esquemas antiguos retiran antes esta migración vacía y la restauran después de sus dependencias.

Concurrencia: BE08 conserva bloqueo de catálogo → orden → línea → autoridad; BE03/BE05 serializan sus cambios por la misma orden/línea. Decisión y resolución permanecen en una transacción junto con auditoría. Los lectores de elegibilidad adquieren bloqueo compartido de orden. No hay ventanas entre comprobar viabilidad y registrar resolución.

## Contratos para FE-S2-11

Se amplían lecturas existentes y se mantienen los payloads de solicitar/aprobar/rechazar:

- `GET /api/v1/laboratory-orders/{order}/clinical-finalization`: cada línea añade `incidence` con `status`, `processing_started`, `evidence_ids` e `intention_ids`.
- `GET /api/v1/laboratory-orders/{order}/exams/{line}/exceptions`: cada solicitud pendiente añade `clinical_eligibility`. Incluye elegibilidad del usuario consultante, bloqueos clínicos/catálogo/permisos/separación, faltantes, obsolescencia, revisión requerida, evaluaciones/evidencias, token y última revisión. `reason_eligibility` conserva su alcance de catálogo. Las decididas devuelven `clinical_eligibility: null`, sin reinterpretación histórica.
- Las lecturas BE03 mantienen sus historiales, evaluaciones últimas, estado material y obsolescencia, con su permiso independiente. Su token de evaluación es diferente del token de revisión de solicitud.
- Única operación añadida: `POST .../exceptions/{exception}/review`. Ninguna operación existente registra revisión clínica independiente sin solicitar/decidir. Recibe `clinical_revision`, `request_revision`, `dependency_token` y `justification`. Devuelve revisión inmutable y elegibilidad actual; no aprueba ni resuelve.

La revisión utiliza `orders.clinical_replacements.evaluate`, facultad clínica explícita ya existente. Los permisos BE08 de solicitar, consultar, aprobar/rechazar/finalizar conservan su significado. No añade permisos, concesiones de rol, autoridad por owner ni seeders ejecutados. HTTP conserva Sanctum, `X-Laboratory-ID`, membresía/suscripción activas y middleware existentes; los servicios repiten sus controles de autoridad.

Errores de negocio: 409 con `code` y `errors.clinical_eligibility` cuando corresponde: `clinical_evidence_policy_unconfigured`, `clinical_evidence_missing`, `clinical_viability_obsolete`, `clinical_replacement_pending_viable`, `clinical_continuation_viable`, `clinical_technical_alternatives_viable`, `clinical_exception_review_required`. Token de revisión cambiado: `clinical_exception_dependencies_conflict`. Conserva errores BE08 y de catálogo. Contrato inválido: 422; falta de sesión: 401; falta de autoridad/autoaprobación: 403; referencias ajenas: 404.

FE-S2-11 debe mostrar estado pendiente y procesamiento por separado; usar `clinical_eligibility.eligible` de la solicitud pendiente para orientar la aprobación y mostrar todos los bloqueos/faltantes; nunca inferir autorización desde `terminal_candidate`, texto, ausencia de intenciones viables o un resultado favorable aislado. Ante revisión requerida, cargar evidencia/evaluaciones actuales, permitir revisión explícita sólo con el permiso correspondiente y recargar tras ella. No reintentar a ciegas una aprobación tras 409. El POST es siempre la autoridad final. Para inspeccionar los hechos necesita además `orders.clinical_replacements.view`; solicitar y aprobar no conceden esa lectura ni facultad de registrar/evaluar. Las concesiones efectivas deben provisionarse explícitamente. El frontend no fue modificado ni se declara completo.

OpenAPI fuente: `ClinicalFinalizationDocumentation.php` y `ClinicalExceptionEvidenceDocumentation.php`; nuevo contrato de incidencia/elegibilidad/revisión y errores en las operaciones existentes.

## Validación

**IMPLEMENTADO Y PROBADO en alcance backend.** La brecha de aprobación terminal de BE-S2-08 queda corregida en este alcance: reposición pendiente viable, evidencia insuficiente, evaluación obsoleta o dependencia sin revisión impiden insertar decisión aprobada y resolución terminal. No acredita despliegue, provisión real de permisos/catálogo, integración frontend ni circuito de informes completo.

La selección final se ejecutó con fuentes fijas en PostgreSQL 17 efímero, red interna sin puertos publicados y código montado sólo lectura. Duración: **17:32.074**. Ambos proyectos temporales fueron retirados al terminar; los artefactos quedan en `/tmp/be-exc-04-artifacts/`.

| Corrida / selección | Resultado |
| --- | --- |
| Regresión final ampliada | **476/476 pruebas, 7.038 aserciones, cero fallos/errores/omisiones.** JUnit: `final.xml`; salida: `final.log`. |
| BE04 dentro de la final | **38 pruebas/217 aserciones**, incluidas tres carreras reales PostgreSQL. No sumar de nuevo a los 476. |
| Regresión BE08 dentro de la final, excluyendo BE04 | **96 pruebas/506 aserciones**, incluidas HTTP, servicios, integridad/historia, catálogo y concurrencia. |
| Regresión BE03 dentro de la final | **42 pruebas/3.315 aserciones**; incluye sus cuatro carreras y contrato OpenAPI con referencias locales resueltas. |
| Selección dirigida intermedia | 122 pruebas/613 aserciones satisfactorias, antes de los últimos ajustes de revisión y seeders; `targeted.xml`. No se presenta como la selección final ni se suma a ella. |
| Dos casos finales de revisión aislados | 2 pruebas/13 aserciones satisfactorias; `review-cases.xml`. También están incluidos en la selección final. |
| OpenAPI | Regeneración satisfactoria: **183 operaciones**, incluida revisión clínica. Exportación habitual y copia en `api-docs.json` del directorio de artefactos. SHA-256: `2570f5d71b55e6d393f3e1a6e58456daffffda7c36a89ed49b6a86ff3e6228ae`. |
| Formato / integridad documental | Pint satisfactorio en archivos propios de la entrega; sintaxis correcta en los 26 PHP de su inventario; enlaces locales del documento comprobados; `git diff --check` en ambos repositorios. DF-019 y DF-016 idénticos a HEAD. |

La final incluye ClinicalFinalization, ClinicalReplacements, catálogo local/lecturas/auditoría integral, PhysicalSamples, ClinicalExamTest, ClinicalResultTest, OrderComposition, LaboratoryOrderSeederTest, DemoDevelopmentSeederTest y ClinicalReportTest. No es la suite global de todo el repositorio.

La cobertura específica demuestra mínimos ausentes y válidos M01–M07; inviabilidad formal; reposición pendiente viable; recepción sin aceptación; dispensa M03 al solicitar; continuación/alternativas viables; sucesoras aun con igual resultado; evaluación obsoleta; revisión requerida con token y autoridad actuales; pendiente estructurada sin snapshot inicial; obsolescencia de una pantalla de aprobación anterior a revisión; separación de actores; aislamiento tenant; historia inmutable; decisión directa SQL bloqueada por reposición viable; rollback de auditoría; ausencia de resolución/decisión aprobada/cierre al fallar; y conservación de snapshots/PDF anteriores.

Las carreras BE04 usan `pcntl_fork`, conexiones independientes y comprobación de espera efectiva con `pg_stat_activity`: evaluación viable antes de aprobar, sucesora no viable antes de aprobar y aprobación antes de intentar una nueva evaluación. La primera bloquea terminal; la segunda exige revisión explícita; la tercera conserva la resolución y rechaza la modificación clínica posterior por línea resuelta, sin eludir D10.

Se conservan también las corridas intermedias no satisfactorias: 220 casos/4.315 aserciones con dos errores y tres fallos de fixtures/expectativas anteriores (`initial-regression.log`); 68 casos/1.500 aserciones con una comparación de representación antes de recargar el modelo y un error de retirada de trigger por cambios de la migración durante la ejecución (`supplement-initial.xml/log`). Se corrigió la normalización del fixture y se descartaron las corridas que mezclaban esquemas; no se presentan como evidencia final. La corrida final anterior completa vuelve a verificar todos esos casos con las fuentes definitivas.

Comando reproducible desde backend-lab:

```sh
docker compose -p donqer-be-exc-04 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never \
  tests/Feature/ClinicalFinalization tests/Feature/ClinicalReplacements \
  tests/Feature/ClinicalReasons/LocalClinicalReasonTest.php \
  tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php \
  tests/Feature/ClinicalReasons/ClinicalReasonReadTest.php \
  tests/Feature/ClinicalReasons/ClinicalReasonIntegralAuditTest.php \
  tests/Feature/PhysicalSamples tests/Feature/ClinicalExams/ClinicalExamTest.php \
  tests/Feature/ClinicalResults/ClinicalResultTest.php tests/Feature/OrderComposition \
  tests/Feature/LaboratoryOrderSeederTest.php tests/Feature/DemoDevelopmentSeederTest.php \
  tests/Feature/ClinicalReports/ClinicalReportTest.php

docker compose -p donqer-be-exc-04 -f deploy/testing/compose.yaml down --volumes --remove-orphans
```

## Archivos y límites

Implementación: guard de evidencia nuevo; servicio/controlador BE08 y rutas; migración aditiva; dos fuentes OpenAPI. Pruebas nuevas: `ClinicalExceptionEligibilityTest`, `ClinicalExceptionEligibilityConcurrencyTest` y `ClinicalExceptionScenario`. Se adapta el fixture compartido de motivos, pruebas de catálogo local/lecturas, regresión de aprobación histórica/auditoría y el orden de retirada/restauración de migraciones en fixtures históricos. Los seeders de demostración BE08/BE09 preparan evidencia clínica tipada sólo para las líneas de sus órdenes sintéticas registradas, mediante BE03 y sin cambiar generación/formato de informes; las instantáneas de idempotencia incluyen esa evidencia y sus revisiones. No se ejecutan sobre bases compartidas ni reparan/adaptan silenciosamente datos ya registrados. Los cambios locales anteriores de BE03 se conservan.

Archivos propios de esta entrega (rutas relativas a backend-lab; los demás cambios del árbol ya estaban presentes):

```text
docs/sprint-2/BE-EXC-04-incidencias-elegibilidad.md
laravel/app/ClinicalFinalization/ClinicalExceptionEvidenceGuard.php
laravel/app/ClinicalFinalization/ClinicalResolutionService.php
laravel/app/Http/Controllers/Api/V1/ClinicalFinalizationController.php
laravel/app/OpenApi/ClinicalExceptionEvidenceDocumentation.php
laravel/app/OpenApi/ClinicalFinalizationDocumentation.php
laravel/database/migrations/2026_10_29_150442_add_clinical_exception_evidence_reviews.php
laravel/routes/api.php
laravel/database/seeders/DemoClinicalReason.php
laravel/database/seeders/LaboratoryFinalizationSeeder.php
laravel/database/seeders/LaboratoryReportEvidenceSeeder.php
laravel/tests/Support/StructuredClinicalReasonFixture.php
laravel/tests/Feature/ClinicalFinalization/ClinicalExceptionEligibilityTest.php
laravel/tests/Feature/ClinicalFinalization/ClinicalExceptionEligibilityConcurrencyTest.php
laravel/tests/Feature/ClinicalFinalization/ClinicalExceptionScenario.php
laravel/tests/Feature/ClinicalFinalization/ClinicalFinalizationIntegrityTest.php
laravel/tests/Feature/ClinicalFinalization/ClinicalHistoricalResolutionTest.php
laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionTest.php
laravel/tests/Feature/ClinicalReasons/ClinicalReasonReadTest.php
laravel/tests/Feature/ClinicalReasons/ClinicalReasonIntegralAuditTest.php
laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonTest.php
laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php
laravel/tests/Feature/ClinicalExams/ClinicalExamTest.php
laravel/tests/Feature/ClinicalResults/ClinicalResultTest.php
laravel/tests/Feature/PhysicalSamples/PhysicalSampleTest.php
laravel/tests/Feature/OrderComposition/AtomicOrderCompositionTest.php
laravel/tests/Feature/LaboratoryOrderSeederTest.php
```

En producto se añaden seguimientos a `README.md`, `01-contexto/inventario-funcional.md`, `06-especificaciones/README.md`, `06-especificaciones/plan-excepciones-clinicas-v1.md` y `07-auditorias/AUD-002-excepciones-clinicas-v1.md`, conservando íntegros los cambios/historiales previos. No cambia una decisión aprobada. OpenAPI generado habitual también se regenera; continúa ignorado por Git según la configuración existente.

BE-EXC-05 debe consumir resolución, motivo/referencia originales y evidencia histórica para la representación prospectiva de No realizado/Incompleto, conservando snapshots y PDFs existentes, publicación validada e independencia comercial. Esta entrega no modifica renderer, emisor, entrega ni frontend, y no declara completo el circuito de informes. Despliegue, provisión efectiva de catálogo/permisos e integración FE-S2-11 conservan sus pasos propios. Sin migraciones/seeders en bases compartidas, commits o push.
