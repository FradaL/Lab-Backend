# BE-EXC-02.4 — Administración de motivos clínicos locales

Fecha: 2026-10-25. Solicitud: adjunto `027740c3-9a25-49ca-80ac-a6a740ba57d1`. Fuente funcional: [DF-019 APROBADO 1.4](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md) y [DF-016 APROBADO 1.0](../../../donqer-lab-product/03-decisiones/DF-016-finalizacion-clinica.md). Auditoría previa conservada; resultados finales en la sección de verificación.

## Resultado y clasificación

**BE-EXC-02.4 implementado e integrado con BE08**, con pruebas reales en PostgreSQL temporal. El consumo clínico local usa el mismo servicio y política que el global, con referencia original persistida, aprobación independiente y revisión obligatoria según el contrato ratificado. No se declara completo el circuito clínico integral de DF-019.

| Componente | Clasificación | Evidencia / límite |
| --- | --- | --- |
| Administración owner, publicación, activación, versiones y eventos | IMPLEMENTADO Y PROBADO | HTTP/servicios, permisos específicos, identidad estable e historia inmutable. |
| Referencias locales, obligaciones y evaluaciones | IMPLEMENTADO Y PROBADO | Migración PostgreSQL, FK compuestas, causas explícitas, sucesión y protección SQL. |
| Solicitud/aprobación local BE08 | IMPLEMENTADO Y PROBADO | Política única dentro de transacción; original intacto, no autoaprobación ni adopción global. |
| Seguridad, rollback y concurrencia | IMPLEMENTADO Y PROBADO | Aislamiento, owner sin autoridad clínica implícita, diez carreras y auditoría atómica. |
| Historia global/previa, snapshots e informes existentes | PRESERVADOS; REGRESIÓN DIRIGIDA | Pruebas de compatibilidad en el alcance del informe, sin asociación inferida a motivos ni cambio de filas clínicas. |
| Decisión local de revisión | RATIFICADA; SIN PENDIENTE DE DECISIÓN | Respuesta explícita de Daniel Lara registrada debajo. No queda integración local autorizada aplazada. |
| Evidencia/viabilidad y representación clínica integral | PENDIENTE FUERA DE 02.4 | BE-EXC-03/04/05; no se deduce suficiencia clínica por JSON o terminal_candidate. |
| Lecturas compartidas 02.5 y validación integral 02.6 | PENDIENTE FUERA DE 02.4 | No implementadas anticipadamente. |
| Frontend y despliegue | FUERA DE ALCANCE | Sin migraciones/grants de entornos reales, commits, push o Trello. |

## Diagnóstico funcional previo

EXC-04/05/10 autorizan al owner a crear, editar, clasificar y desactivar motivos exclusivos de su laboratorio, sin delegación administrativa V1 ni aprobación clínica implícita. EXC-07/13 exigen historia original y reevaluación de solicitudes pendientes dependientes de reclasificación. I.A exige revisión explícita por desactivación, favorable para continuar una aprobación independiente y sin permitir solicitudes nuevas con motivo inactivo. No se aplica automáticamente impacto SaaS a locales.

Ya existen laboratory_clinical_reasons, laboratory_clinical_reason_versions, FK compuestas, versiones consecutivas/inmutables y ClinicalReasonVersionStore::appendLocal (02.1). Falta administración tenant auditada y referencia/revisiones clínicas locales. BE08 usa ClinicalResolutionService y ClinicalExceptionReasonGuard; extender ClinicalReasonEligibilityPolicy, sin política paralela. 02.3-I guarda referencias fuera de filas clínicas por report_line_snapshot/to_jsonb: conservar esta separación.

| Pregunta | Alcance autorizado |
| --- | --- |
| Crear, modificar, publicar y desactivar | Owner con permiso específico. Publicar anexa versión completa vigente; no se inventan borradores o un aprobador clínico de publicación. |
| Permisos | RBAC tenant existente; ningún permiso clínico o SaaS implícito por administrar. |
| Historia | Identidades/versiones 02.1; vigencia por sucesora, sin UPDATE/DELETE ni reescritura de solicitudes. |
| Evidencia | schema_version=1/requirements existentes como metadata estructural; JSON válido no demuestra suficiencia clínica. Mínimos/viabilidad BE-EXC-03/04 conservados. |
| Pendientes | Desactivación o cambio explícito de clasificación exige revisión; reactivar o revertir clasificación no borra obligaciones. |
| BE08 | Referencia source local, laboratorio/identidad/versión originales, justificación independiente; sin adopción global. |

## Ratificación local recibida

Daniel Lara, Product Owner, respondió el 2026-10-25: **«Ratifico ese contrato local; integrar administración y uso clínico.»** Alcance de la consulta ratificada: reutilizar clinical_reasons.review_impact, evaluación obligatoria favorable/no favorable y obligaciones inmutables por desactivación o cambio explícito de clasificación; bloquear aprobación pendiente hasta favorable, sin declaraciones SaaS ni clasificación global de impacto. Fuente: respuesta explícita a la consulta de esta solicitud, sin URL exportable. El agente registra la ratificación, no aprueba por inferencia.

Nuevas solicitudes usan versión local vigente, activa y terminal_candidate. Las pendientes mantienen versión original y exigen revisar los cambios explícitos posteriores aplicables, incluso intermedios luego revertidos. Favorable sólo levanta su obligación y no aprueba una excepción. No inferir impacto por texto o requisitos de evidencia.

## Diseño y límites iniciales

Reutilizar ClinicalReasonTenantAuthority, Teams, AuditWriter y lock transaccional existente. Extender política por origen. Referencias/revisiones/evaluaciones locales separadas con FK tenant, causas verificables y sucesión inmutable; no otra identidad global ni adopción ficticia. Conservar contratos globales y conjunto histórico sellado; no modificar filas clínicas, snapshots/PDF, frontend o anticipar 02.5/02.6.

Pruebas previstas: PostgreSQL temporal, HTTP y servicios, autorización, historial/versiones, publicación/vigencia, aislamiento, revisión favorable/no favorable, concurrencia, contexto original y regresión global/BE08/informes. Ninguna prueba nueva se declara ejecutada en este corte previo.

Sin migraciones de desarrollo/producción, Trello, commits ni push.

## Arquitectura implementada

Se reutilizan las identidades y versiones locales de 02.1, sin clonar motivos globales. LocalClinicalReasonManager orquesta autorización, identidad, versión, obligaciones y auditoría en una transacción. Publicar es anexar una definición completa vigente; activar/desactivar es una operación explícita que anexa otra versión. No existe edición de versiones publicadas, borrador, eliminación o aprobador adicional de publicación.

ClinicalReasonTenantAuthority comprueba Teams, laboratorio/usuario/membresía activos, suscripción vigente y permiso efectivo nuevamente en servicio. Las escrituras administrativas requieren además rol owner. Cinco permisos nuevos: clinical_reasons.local.view/create/publish/manage/history. El catálogo RBAC pasa de 90 a 95; la plantilla owner de 70 a 75 permisos administrativos y administrator conserva 65. Owner no recibe review_impact ni request/approve por esta ampliación. Definir plantillas no concede permisos en instalaciones existentes: no se ejecutaron seeders reales.

Se extienden ClinicalExceptionReasonGuard y la misma ClinicalReasonEligibilityPolicy; no se añade una política paralela. ClinicalResolutionService mantiene sus rutas y controles BE08: solicitud pendiente, revisiones exactas, captura/inicio vigentes, no validación histórica ni resolución previa, aprobador distinto, auditoría atómica y cierre explícito. El servicio elige el origen y guarda contexto inmutable separado; el motivo textual de la solicitud se deriva del nombre de la versión. La justificación clínica se conserva aparte de la justificación administrativa de cambio.

LocalClinicalReasonReviewStore crea una obligación por solicitud pendiente y versión que desactiva o cambia explícitamente clasificación. Conserva motivo, versión original, versión causante, actor, fecha y causa exactos. Cada evaluación es una sucesora inmutable, con revisión esperada, evaluación obligatoria, justificación y resultado favorable/non_favorable. No se puede modificar una favorable ni borrar obligaciones. La aprobación clínica comprueba todas las causas posteriores pertinentes, incluso intermedias revertidas, y todas sus favorables. Cambios de nombre/descripcion/evidencia no reciben automáticamente una clasificación global de impacto. No hay dependencia funcional de adopciones, declaraciones o grants SaaS para usar locales.

## Migración, modelos e integridad

[2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php](../../laravel/database/migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php) crea tres tablas y sus modelos:

| Tabla/modelo | Propósito |
| --- | --- |
| clinical_exception_local_reason_references / ClinicalExceptionLocalReasonReference | Referencia local única por solicitud, laboratorio/orden/línea, motivo/versión originales, justificación y actor/fecha. |
| local_clinical_reason_reviews / LocalClinicalReasonReview | Obligación inmutable causada por cambio explícito, ligada a la referencia original y versión causante. |
| local_clinical_reason_resolutions / LocalClinicalReasonResolution | Sucesión de evaluaciones con predecesora/revisión exactas; favorable sólo levanta su obligación. |

FK compuestas impiden relaciones entre tenants, motivos o solicitudes diferentes. Triggers impiden UPDATE/DELETE, causas inventadas, revisiones omitidas al confirmar una publicación con pendientes y evaluaciones sin predecesora exacta. Para cada solicitud nueva, una comprobación diferida exige exactamente un origen global o local; no se puede agregar un segundo origen después. Las referencias locales requieren versión vigente, nombre derivado y actor original, sin añadir vínculos al conjunto histórico sellado.

Los registros heredados de texto, referencias globales, filas de decisiones/resoluciones, snapshots y funciones report_line_snapshot permanecen sin cambios de estructura ni migración por nombre. La migración es aditiva sobre ese circuito; no altera PDFs almacenados ni introduce columnas a filas serializadas con to_jsonb. El down se niega a borrar historia local clínica existente y requiere restauración explícita; sin ella puede revertirse antes de 02.3-I. Los tests que simulan upgrades ahora respetan ese orden.

Las operaciones adquieren el advisory lock compartido existente (182045,1) antes de filas tenant/motivo/orden/línea. appendLocal y triggers de inserción toman ese mismo lock antes de la identidad/versiones, evitando inversión entre el store y los comandos clínicos. Publicación, solicitudes, evaluaciones y aprobación se serializan hasta commit. No se agregan decisiones clínicas a una cola ni autorización basada en una lectura previa. El lock también serializa temporalmente otros laboratorios, una limitación técnica ya existente.

## Contratos API y permisos

Prefijo `/api/v1/laboratory/clinical-reasons/local`; todas las rutas requieren auth/Sanctum, `X-Laboratory-ID`, contexto tenant y suscripción/membresía activos. Lecturas 200, escrituras 201. Paginación de 50 y parámetro page>=1, sin filtros adicionales. Los IDs de rutas son numéricos; las referencias y revisiones de escritura son enteros JSON estrictos.

| Método / sufijo | Permiso | Cuerpo o respuesta |
| --- | --- | --- |
| GET / | clinical_reasons.local.view | Identidades con latest_version, paginadas. |
| POST / | clinical_reasons.local.create + owner | code y definition completa; devuelve reason/version/audit_id. |
| GET /{reason} | clinical_reasons.local.view | Identidad y versión vigente. |
| GET /{reason}/versions | clinical_reasons.local.history | Versiones completas, más reciente primero. |
| GET /{reason}/events | clinical_reasons.local.history | Eventos administrativos tenant. |
| POST /{reason}/versions | clinical_reasons.local.publish + owner | expected_number y definition completa; no cambiar is_active por esta vía. |
| POST /{reason}/activation | clinical_reasons.local.manage + owner | expected_number, is_active boolean estricto y change_reason. |
| GET /{reason}/reviews | clinical_reasons.local.view | Obligaciones y última evaluación/estado derivado. |
| GET /{reason}/reviews/{review}/resolutions | clinical_reasons.local.history | Evaluaciones sucesoras, más reciente primero. |
| POST /{reason}/reviews/{review}/resolutions | clinical_reasons.review_impact | expected_revision, assessment, justification y outcome favorable/non_favorable. No exige owner ni concede approve. |

Definition: name<=200, description, classification incidence_only/terminal_candidate, evidence_requirements con schema_version=1 y requirements de identificadores canónicos, is_active estricto y change_reason no vacío; se reutiliza la validación existente del version store. Código estable canónico <=50 y único por laboratorio. Campos desconocidos y revisiones no enteras se rechazan. La evaluación admite assessment<=10000 y justification<=2000. Los máximos exactos adicionales de definición se documentan en OpenAPI; JSON válido no acredita suficiencia clínica.

Errores: 401/403 autenticación/autoridad; 404 recurso ausente o ajeno sin exposición; 422 validación; 409 local_clinical_reason_code_conflict, clinical_reason_version_conflict, local_clinical_reason_activation_unchanged o clinical_reason_review_revision_conflict. BE08 mantiene conflictos de revisiones, clinical_reason_selection_conflict (versión local no vigente), clinical_reason_use_blocked (inactivo al solicitar o revisión no favorable al aprobar), clinical_reason_not_terminal (incidence_only). No hay incorporación local por un código global ni adopción ficticia.

Solicitud clínica existente, ejemplo FICTICIO con IDs ilustrativos:

```json
{
  "clinical_revision": 1,
  "result_revision": 0,
  "reason_reference": {"source": "local", "reason_id": 10, "version_id": 20},
  "justification": "FICTICIO: justificación clínica independiente"
}
```

Evolución del contrato: el origen local antes devolvía 409 clinical_reason_source_not_available; ahora admite la referencia local documentada. Enviar adopción global con source local devuelve 422. Las solicitudes globales válidas conservan su estructura y reglas.

El laboratorio, orden, línea y actor se derivan del contexto autenticado. Adopción y adoption_version_id están prohibidos para local; el contrato global mantiene sus campos obligatorios. Aprobación/rechazo siguen utilizando exclusivamente clinical_revision/request_revision y reason donde corresponde, sin permitir reemplazar reason_reference. Historia/respuestas exponen source y definición originales; sólo global incluye adopción. OpenAPI usa oneOf de dos esquemas, sin aumentar autoridad por conocer IDs.

Diez operaciones nuevas: 175 operaciones OpenAPI, 183 rutas del bootstrap de tests y 150 rutas tenant con can:. Los inventarios contractuales se actualizan a esas cifras. Los consumidores globales y demos conservan sus contratos.

## Riesgos, integración y decisiones restantes

No quedan decisiones funcionales pendientes dentro del contrato local ratificado. La administración, persistencia y consumo BE08 están implementados; la verificación final se registra abajo. No se presenta este cambio como implementación clínica integral de DF-019: evidencia mínima, viabilidad y reposición BE-EXC-03/04, representación No realizado/BE-EXC-05, frontend, lecturas definitivas 02.5 y verificación transversal 02.6 conservan su alcance pendiente.

No se migró desarrollo/producción ni se ejecutaron seeders/grants reales. Cualquier despliegue futuro debe coordinar código y migración, revisar plantillas RBAC y evitar escritores antiguos que intenten crear solicitudes sin referencia. No se implementó frontend, 02.5/02.6, Trello, commits o push.

## Verificación ejecutada

Entornos temporales exclusivos Compose donqer-be-exc-024 (regresión) y donqer-be-exc-024-contracts (contratos, base independiente), PostgreSQL 17 en tmpfs y red interna sin puertos, PHP 8.4.25, Laravel 13.32.0, PHPUnit 12.5.35. Montajes de aplicación/configuración testing en lectura; TestDatabaseGuard verifica configuración e identidad del servidor. Resultados finales ejecutados:

| Batería ejecutada | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| Regresión dirigida inicial | 491 | 3.065 | 490 casos pasan; 1 fallo de orden en prueba demo, corregido y reejecutado abajo |
| Contratos, rutas y OpenAPI | 193 | 1.897 | Sin fallos, errores u omisiones |
| Muestras y persistencia de validación | 56 | 435 | Sin fallos, errores u omisiones |
| Dataset tras corregir la aserción de orden | 1 | 821 | Sin fallos, errores u omisiones |

Las baterías pueden solaparse; no se suman como casos únicos. **No se ejecutó la suite completa del repositorio.** Logs locales: /tmp/be-exc-024-regression.log, /tmp/be-exc-024-contracts.log, /tmp/be-exc-024-supplement.log y /tmp/be-exc-024-seeder-final.log.

La ejecución principal terminó con 490 casos satisfactorios y un fallo de LaboratoryOrderSeederTest: comparaba el orden de estados de una consulta sin ORDER BY. Se corrigió exclusivamente a assertEqualsCanonicalizing, conservando el conjunto exacto de tres estados y todas las comprobaciones de dominio posteriores. La reejecución completa del caso pasó con 821 aserciones. No se repitió la batería principal de 32:24.562 después de ese cambio exclusivo de prueba; no se presenta como una corrida única de 491 casos sin fallos. No cambió lógica productiva por ese hallazgo.

La regresión dirigida incluye los **35 casos nuevos locales**, diez de ellos carreras con procesos/conexiones independientes y espera real comprobada mediante pg_stat_activity. Cubre solicitudes/aprobaciones locales, referencias manipuladas o ajenas, versión vigente/inactiva, incidencia no terminal, revisión favorable/no favorable, reclasificación revertida, desactivación con aprobación original tras revisión, aislamiento, no autoridad clínica implícita, revocación de owner/membresía con caché previa, integridad SQL e historial inmutable. Los fallos inyectados de auditoría revierten publicación/obligaciones, solicitud/referencia o evaluación completos.

Las carreras cubren código duplicado, versión duplicada, publicación frente a activación, ambos órdenes de solicitud/desactivación y aprobación/desactivación, reclasificación antes de aprobación, evaluación favorable antes de aprobación y dos evaluaciones simultáneas. El contexto original se conserva y no se crean resoluciones tras un bloqueo. La regresión existente verifica motivos globales, SaaS, BE08, historia anterior, cierre, informes, entregas y dataset; la adicional verifica muestras/validaciones y upgrades de esquema.

Pint aplicado a los archivos PHP de implementación de esta entrega y pruebas locales; comprobación final satisfactoria. Sintaxis PHP: 39 archivos modificados/nuevos, cero errores. git diff --check sin incidencias en ambos repositorios. Documentación: 13 archivos y 639 enlaces locales/anclas comprobados, sin destinos rotos. Se comprobó conservación literal del expediente DF-019 1.3, las dieciocho decisiones 1.4, todas las demás fichas DF, el archivo previo AUD-002 y la planificación histórica 1.3. Modelos de solicitud/decisión/resolución y migración de snapshots permanecen idénticos al inicio de 02.4.


### Contratos verificados

Batería `tests/Feature --filter 'contract|openapi|route'`: **193 pruebas, 1.897 aserciones**, sin fallos, errores u omisiones, 01:37.882. Log local `/tmp/be-exc-024-contracts.log`. Confirma OpenAPI, permisos exactos e inventarios de 175 operaciones/183 rutas de bootstrap/150 rutas tenant protegidas. Esta batería se solapa con la regresión dirigida; no se suman como casos únicos.

### Muestras y validaciones verificadas

Batería adicional `tests/Feature/PhysicalSamples tests/Feature/ClinicalValidation/ValidationPersistenceTest.php`: **56 pruebas, 435 aserciones**, sin fallos, errores u omisiones, 04:16.354. Log `/tmp/be-exc-024-supplement.log`. Comprueba también el orden de rollback/reaplicación en los escenarios históricos de esquema, sin ejecutar migraciones en entornos reales.

### Comandos reproducibles

Desde la raíz backend, usando sólo la configuración testing aislada de deploy/testing/compose.yaml:

```bash
docker compose -p donqer-be-exc-024 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalReasons tests/Feature/ClinicalFinalization tests/Feature/SaasAccess tests/Feature/Api/V1/Auth tests/Feature/Authorization tests/Feature/Saas tests/Feature/ClinicalReports tests/Feature/ReportDelivery tests/Feature/LaboratoryOrderSeederTest.php
docker compose -p donqer-be-exc-024-contracts -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature --filter 'contract|openapi|route'
docker compose -p donqer-be-exc-024-contracts -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/PhysicalSamples tests/Feature/ClinicalValidation/ValidationPersistenceTest.php
docker compose -p donqer-be-exc-024 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/LaboratoryOrderSeederTest.php --filter test_complete_dataset_uses_published_configurations_independent_requirements_and_domain_economics
```

No ejecutar baterías simultáneas sobre la misma base; los proyectos separados tienen bases/redes propias. Los contenedores de pruebas y su PostgreSQL temporal se retiran al terminar. Ninguno publica puertos ni comparte datos de desarrollo/producción.

## Archivos de esta entrega

Comparación SHA-256 contra el árbol al inicio de 02.4 (`/tmp/be-exc-024-baseline.json`), incluyendo archivos entonces no versionados. No se atribuyen a esta entrega todos los cambios visibles en git. Los archivos de pruebas existentes reciben ajustes de inventario o del orden de rollback/reaplicación; sus comprobaciones históricas se conservan.

- [backend-lab/README.md](../../README.md)
- [backend-lab/laravel/app/Authorization/ClinicalReasonTenantAuthority.php](../../laravel/app/Authorization/ClinicalReasonTenantAuthority.php)
- [backend-lab/laravel/app/Authorization/RbacCatalog.php](../../laravel/app/Authorization/RbacCatalog.php)
- [backend-lab/laravel/app/ClinicalExams/ClinicalReasonEligibilityPolicy.php](../../laravel/app/ClinicalExams/ClinicalReasonEligibilityPolicy.php)
- [backend-lab/laravel/app/ClinicalExams/ClinicalReasonVersionStore.php](../../laravel/app/ClinicalExams/ClinicalReasonVersionStore.php)
- [backend-lab/laravel/app/ClinicalExams/LocalClinicalReasonManager.php](../../laravel/app/ClinicalExams/LocalClinicalReasonManager.php)
- [backend-lab/laravel/app/ClinicalExams/LocalClinicalReasonReviewStore.php](../../laravel/app/ClinicalExams/LocalClinicalReasonReviewStore.php)
- [backend-lab/laravel/app/ClinicalFinalization/ClinicalExceptionReasonGuard.php](../../laravel/app/ClinicalFinalization/ClinicalExceptionReasonGuard.php)
- [backend-lab/laravel/app/ClinicalFinalization/ClinicalResolutionService.php](../../laravel/app/ClinicalFinalization/ClinicalResolutionService.php)
- [backend-lab/laravel/app/Http/Controllers/Api/V1/LocalClinicalReasonController.php](../../laravel/app/Http/Controllers/Api/V1/LocalClinicalReasonController.php)
- [backend-lab/laravel/app/Models/ClinicalExceptionLocalReasonReference.php](../../laravel/app/Models/ClinicalExceptionLocalReasonReference.php)
- [backend-lab/laravel/app/Models/LocalClinicalReasonResolution.php](../../laravel/app/Models/LocalClinicalReasonResolution.php)
- [backend-lab/laravel/app/Models/LocalClinicalReasonReview.php](../../laravel/app/Models/LocalClinicalReasonReview.php)
- [backend-lab/laravel/app/OpenApi/ClinicalFinalizationDocumentation.php](../../laravel/app/OpenApi/ClinicalFinalizationDocumentation.php)
- [backend-lab/laravel/app/OpenApi/LocalClinicalReasonDocumentation.php](../../laravel/app/OpenApi/LocalClinicalReasonDocumentation.php)
- [backend-lab/laravel/database/migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php](../../laravel/database/migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php)
- [backend-lab/laravel/routes/api.php](../../laravel/routes/api.php)
- [backend-lab/laravel/tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php](../../laravel/tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientActiveTest.php](../../laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientActiveTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientPriceListStoreTest.php](../../laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientPriceListStoreTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientStatusTest.php](../../laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientStatusTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientUpdateTest.php](../../laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientUpdateTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderDiscountTest.php](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderDiscountTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamDestroyTest.php](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamDestroyTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamIndexTest.php](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamIndexTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamStoreTest.php](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamStoreTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderShowTest.php](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderShowTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStatusTest.php](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStatusTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStoreTest.php](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStoreTest.php)
- [backend-lab/laravel/tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php](../../laravel/tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php)
- [backend-lab/laravel/tests/Feature/Authorization/ProtectedDomainAuthorizationTest.php](../../laravel/tests/Feature/Authorization/ProtectedDomainAuthorizationTest.php)
- [backend-lab/laravel/tests/Feature/Authorization/RolePermissionSeederTest.php](../../laravel/tests/Feature/Authorization/RolePermissionSeederTest.php)
- [backend-lab/laravel/tests/Feature/ClinicalFinalization/ClinicalHistoricalResolutionTest.php](../../laravel/tests/Feature/ClinicalFinalization/ClinicalHistoricalResolutionTest.php)
- [backend-lab/laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionTest.php](../../laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionTest.php)
- [backend-lab/laravel/tests/Feature/ClinicalReasons/ClinicalReasonPersistenceTest.php](../../laravel/tests/Feature/ClinicalReasons/ClinicalReasonPersistenceTest.php)
- [backend-lab/laravel/tests/Feature/ClinicalReasons/GlobalClinicalReasonAdministrationTest.php](../../laravel/tests/Feature/ClinicalReasons/GlobalClinicalReasonAdministrationTest.php)
- [backend-lab/laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php](../../laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php)
- [backend-lab/laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonTest.php](../../laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonTest.php)
- [backend-lab/laravel/tests/Feature/LaboratoryOrderSeederTest.php](../../laravel/tests/Feature/LaboratoryOrderSeederTest.php)
- [backend-lab/laravel/tests/Feature/PhysicalSamples/PhysicalSampleTest.php](../../laravel/tests/Feature/PhysicalSamples/PhysicalSampleTest.php)
- [donqer-lab-product/01-contexto/inventario-funcional.md](../../../donqer-lab-product/01-contexto/inventario-funcional.md)
- [donqer-lab-product/02-reglas-negocio/resultados.md](../../../donqer-lab-product/02-reglas-negocio/resultados.md)
- [donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md)
- [donqer-lab-product/03-decisiones/README.md](../../../donqer-lab-product/03-decisiones/README.md)
- [donqer-lab-product/03-decisiones/registro-decisiones.md](../../../donqer-lab-product/03-decisiones/registro-decisiones.md)
- [donqer-lab-product/04-flujos/README.md](../../../donqer-lab-product/04-flujos/README.md)
- [donqer-lab-product/06-especificaciones/README.md](../../../donqer-lab-product/06-especificaciones/README.md)
- [donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md)
- [donqer-lab-product/07-auditorias/AUD-002-excepciones-clinicas-v1.md](../../../donqer-lab-product/07-auditorias/AUD-002-excepciones-clinicas-v1.md)
- [donqer-lab-product/07-auditorias/README.md](../../../donqer-lab-product/07-auditorias/README.md)
- [donqer-lab-product/README.md](../../../donqer-lab-product/README.md)

## Trazabilidad de trabajo

Backend HEAD `6613fb9576ec3be9d8075780a1d4eeca90a82c2c`; producto HEAD `714dd05c3a983fde0d375b9bdd3b6b98381734f2`. Ambos árboles tenían cambios previos que se conservaron. La evidencia corresponde al árbol local comparado con el inicio de esta tarea, no a implementación atribuida a esos commits. Sin commits ni push nuevos.
