# Donqer Lab — Verificación final de roles y permisos

Fecha: 2026-10-30. Dictamen: **Listo para frontend con observaciones**.

Se recomienda integrar las tres operaciones del módulo con frontend en un entorno donde su migración y configuración estén aplicadas. No se detectaron regresiones funcionales del módulo en el alcance comprobado. La suite global sigue sin aprobar: se confirmaron incidencias previas de aislamiento de pruebas y queda un fallo intermitente sin causa concluyente. Este dictamen no certifica una liberación general del backend ni un despliegue compartido.

## Alcance e integridad

- Baseline: HEAD `80f9402cf4fd6123a7b6dd91bc520df2268652dd`. Se verificaron los blobs Git de **699 archivos** de la copia temporal de Laravel: ninguna diferencia respecto de HEAD.
- Actual: árbol de trabajo con la implementación, todavía sin commit. Se revisaron sus diferencias con HEAD, incluidos los nuevos archivos no versionados.
- Se reutilizó la imagen `donqer-lab-workspace`, PHP 8.4.25, PHPUnit 12.5.35 y el mismo vendor, con PostgreSQL 17 efímero en redes privadas independientes. `TestDatabaseGuard` validó servidor, conexión, usuario y base antes de las migraciones.
- Montajes del código y de los controles temporales de solo lectura. No se alteraron bases compartidas, código funcional, pruebas existentes, configuración ni migraciones. Una comparación SHA-256 de todos los archivos preexistentes del repositorio confirmó cero cambios durante esta verificación. El único archivo añadido al repositorio es este informe.
- No se realizaron commits ni push. No se volvió a ejecutar toda la suite de 3787 casos: se analizó su log conservado y se ejecutaron nuevamente las selecciones indicadas abajo sobre el código actual y HEAD.
- Evidencias y controles temporales: `/tmp/rbac-verification.2gLCv7`. Log global analizado: `/tmp/backend-rbac-final-suite.log`.

## Matriz de cumplimiento

| Decisión o requisito | Estado | Evidencia actual |
| --- | --- | --- |
| Cualquier miembro activo con `roles.permissions.manage` puede gestionar matrices, sin exigir owner | Cumple | Actores con los cuatro roles configurables; también un actor sin roles con permiso directo. `RolePermissionManager::replace` autoriza mediante Gate y no inspecciona el nombre del rol del actor. |
| Owner y administrator protegidos | Cumple | GET devuelve `protected=true`, `editable=false`; PUT rechazado para ambos desde cada uno de los cuatro perfiles configurables. |
| Cuatro roles configurables | Cumple | `receptionist`, `cashier`, `laboratory_technician`, `viewer`: actualización y redelegación aprobadas. |
| Permisos administrativos, clínicos y financieros delegables | Cumple | Se concedieron y persistieron `users.change_status`, `results.validate`, `orders.manage_discount` y el propio permiso de gestión. El validador utiliza todo `RbacCatalog::PERMISSIONS`, sin lista de exclusiones operativas. Las restricciones estructurales de otros servicios se conservan. |
| SaaS excluido | Cumple | Catálogo sin códigos `saas.*`; rechazo 422 de `saas.access.manage` incluso creando ese registro en la tabla de permisos del entorno efímero. |
| Owner predeterminado y nuevos laboratorios | Cumple mediante aprovisionador explícito | Igualdad de conjuntos catálogo/plantilla/pivote real; seis roles por laboratorio; sin faltantes ni extras. |
| Delegación, revocación y sesiones existentes | Cumple | Una sola llamada a login, reenvío de su cookie, mismo ID de sesión; 403 antes de conceder, 200 tras conceder, 403 tras revocar; `/auth/me` conserva la identidad autenticada. |
| Varios roles y permisos directos | Cumple | Se conserva autorización efectiva mientras exista otra vía de concesión; se pierde al retirar la última. Actor sin roles autorizado por permiso directo. |
| Usuarios en varios laboratorios y aislamiento | Cumple | Cambios A/B/A con la misma sesión, permisos distintos por laboratorio, 403 en B y 200 en A; roles ajenos rechazados; contexto, membresía y suscripción verificados. |
| Caché y múltiples procesos | Cumple en pruebas HTTP/servicio | Proceso con caché caliente observa revocación confirmada en su siguiente solicitud; solicitud que espera un bloqueo vuelve a autorizar antes de escribir. Las relaciones del usuario se recargan. No se realizó una prueba de carga de workers de producción. |
| Revisión optimista, auditoría, rollback y último owner | Cumple | Carreras reales PostgreSQL, conflicto de revisión, rollback por fallo de auditoría y conservación de al menos un owner activo en cambios concurrentes de rol/membresía. |
| Contrato OpenAPI de las tres operaciones | Cumple | Generación y comprobación de rutas, esquemas, catálogo y revisión incluidas en la selección actual. |

## Verificación dinámica del owner

Se calculó `N = count(RbacCatalog::PERMISSIONS)` durante la prueba; **no se utilizó el número observado como constante de validación**. Se compararon los conjuntos completos, no únicamente sus cantidades.

```json
{
  "catalog_count": 99,
  "owner_template_count": 99,
  "provisioned_owner_count": 99,
  "role_count": 6,
  "missing": [],
  "extra": []
}
```

El conteo observado es **99**. El catálogo operativo es la unión autorizada en `RbacCatalog::PERMISSIONS`; la plantilla owner devuelve ese conjunto directamente. `LaboratoryRoleProvisioner::provision` registra el catálogo y crea los seis roles faltantes con sus plantillas iniciales. No hay un endpoint público de alta de laboratorios ni un observador que ejecute automáticamente este servicio al crear cualquier modelo Laboratory: el flujo de alta debe invocarlo explícitamente.

Esta verificación corresponde a la plantilla general y al aprovisionamiento nuevo. Las matrices existentes se preservan; la migración agrega solo `roles.permissions.manage` a owner/administrator existentes. Los datasets DEMO especializados pueden conservar owner con cinco permisos y complementos directos por identidad. No se afirma que todos los owners históricos o DEMO tengan la plantilla completa ni que los datos del entorno compartido estén actualizados.

## Pruebas ejecutadas en esta verificación

| Selección o control | Resultado |
| --- | --- |
| Controles temporales dinámicos y de cookie + autorización + administración de usuarios + autorización efectiva + contratos + adopción clínica + integridad de reportes + seguridad de validación + seeder de órdenes | **168 pruebas, 12977 aserciones, aprobadas** |
| HEAD: reportes global/local sin la prueba histórica anterior | **2 pruebas, 26 aserciones, aprobadas** |
| Actual: mismo control de dos reportes sin la prueba histórica anterior | **2 pruebas, 26 aserciones, aprobadas** |
| HEAD: prueba histórica seguida por esos dos reportes | **3 pruebas, 5 aserciones: 1 aprobada y 2 errores de emisión** |
| HEAD: secuencia histórica + clases completas de reportes y entrega | **100 pruebas, 210 aserciones, 62 errores y 4 fallos** |
| Actual: exactamente la misma selección y orden | **100 pruebas, 210 aserciones, 62 errores y 4 fallos** |
| HEAD: inspección de caché de columnas después de la prueba histórica | **2 pruebas, 9 aserciones, aprobadas como diagnóstico del estado defectuoso** |
| HEAD: control que limpia únicamente esa caché en memoria antes de los dos reportes | **4 pruebas, 34 aserciones, aprobadas** |

Las dos selecciones de 100 casos tienen exactamente los mismos identificadores de errores y fallos: **sin casos exclusivos de HEAD ni del árbol actual**. Los conteos de selecciones que se solapan no se suman como casos distintos. La limpieza del último control es una intervención diagnóstica temporal dentro del proceso de pruebas; no se aplicó como corrección al repositorio.

La selección aprobada de 168 casos incluye `RolePermissionAdministrationTest`, `RolePermissionConcurrencyTest`, `RolePermissionDeploymentTest`, `RolePermissionSeederTest`, el resto de Authorization y UserAdministration, `CurrentAuthorizationTest`, `ClinicalReplacementContractTest`, `ClinicalReasonAdoptionTest`, `ClinicalReportIntegrityTest`, `ValidationSecurityTest`, `LaboratoryOrderSeederTest` y cuatro controles externos al repositorio. Estos últimos verifican conjuntos dinámicos, los cuatro actores configurables, una sesión con cookie real y un actor sin roles con permiso directo.

## Clasificación del registro global anterior

Registro analizado: **3787 pruebas, 34671 aserciones, 62 errores, 10 fallos y 3 omisiones**. Sus fallos no representan automáticamente el estado del código actual: cinco fixtures se habían ajustado después de que el runner cargara sus versiones anteriores.

| Grupo | Cantidad | Clasificación | Evidencia y alcance |
| --- | ---: | --- | --- |
| Errores de emisión en reportes y entrega | 62 | Preexistentes; contaminación de metadatos de esquema entre pruebas | Mismos 62 casos reproducidos en HEAD y actual; causa de caché de columnas confirmada por control. |
| Fallos de aserciones 503/201 de concurrencia e identidad de reportes | 4 | Preexistentes, asociados a la misma emisión fallida | Mismos cuatro casos en las dos selecciones de 100. |
| Expectativas antiguas de owner y rollback del catálogo | 5 | Incompatibilidades de fixtures por cambios aprobados, ya corregidas | Se reejecutaron sobre el árbol actual dentro de los 168 casos aprobados. No se clasifican como fallos preexistentes ni como regresiones funcionales pendientes. |
| Cambio de incidencia durante renderizado | 1 | Intermitente, causa no concluyente | El registro antiguo esperaba `report_evidence_conflict` y obtuvo `report_generation_unavailable`. El caso pasa en las selecciones nuevas de HEAD y actual. El test espera un escritor independiente con un límite de 20 segundos; temporización/entorno es una posibilidad, no una causa demostrada. |

No se detectó una nueva regresión funcional en el módulo de roles y permisos. **No se afirma que el origen del fallo intermitente esté descartado o probado como preexistente.** La existencia de ese caso y de la incidencia histórica impide presentar la suite global como aprobada. Las tres omisiones pertenecen al registro global previo y no se cuentan como verificaciones aprobadas.

Los 62 errores se distribuyen así:

| Clase | Errores |
| --- | ---: |
| `ClinicalExceptionReportTest` | 13 |
| `ClinicalReportIntegrityTest` | 3 |
| `ClinicalReportTest` | 15 |
| `ReportDeliveryConcurrencyTest` | 6 |
| `ReportDeliveryTest` | 25 |

Todos reportan `App\ClinicalExams\ClinicalConflict: Report issuance is temporarily unavailable.` en `ClinicalReportService.php:155`, código `report_issuance_failed`, HTTP 503. PostgreSQL registra `New report requires current versioned clinical and pending evidence`, desde `protect_report_issuance()`.

## Causa confirmada y migraciones involucradas

`StructuredClinicalExceptionTest::test_migration_preserves_preexisting_text_requests_decisions_report_snapshots_and_pdf` revierte el esquema, crea evidencia histórica y vuelve a aplicar cuatro migraciones:

1. `2026_10_25_200359_create_clinical_exception_reason_references.php`.
2. `2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php`.
3. `2026_10_29_150442_add_clinical_exception_evidence_reviews.php`.
4. `2026_10_29_164459_version_clinical_report_exception_snapshots.php`.

La base de reportes procede de `2026_10_11_000004_create_clinical_report_issuances.php`. La migración de versiones de snapshots incorpora `pending_snapshot` y la comprobación de formato/evidencia actual.

Durante la creación histórica, Eloquent carga las columnas guardables del modelo `LaboratoryReportIssuance` cuando aún no existe `pending_snapshot`. Su caché estática `Model::$guardableColumns` permanece en el mismo proceso tras reaplicar el esquema. La inspección de HEAD mostró:

```json
{
  "database_has_pending_snapshot": true,
  "model_cache_has_pending_snapshot": false,
  "missing_from_model_cache": ["pending_snapshot"],
  "pending_snapshot_fillable": false,
  "pending_snapshot_retained_by_model": false
}
```

El atributo se descarta al llenar el modelo; la inserción llega al trigger sin esa evidencia y se rechaza. Al retirar únicamente la entrada de ese modelo de la caché en memoria del proceso de prueba, el atributo vuelve a conservarse y los dos reportes pasan. Ese control mantiene intactos el código de aplicación y los triggers. Confirma un defecto de aislamiento/caché de esquema al alternar versiones dentro del mismo proceso de pruebas. La caché de autorización de Spatie se comprobó por separado y aprobó sus pruebas.

Las cuatro migraciones históricas, los servicios de reportes/finalización implicados y el caso histórico tienen el mismo contenido en HEAD y el árbol actual. La migración nueva `2026_10_30_000001_add_role_permission_revisions.php` opera sobre roles, permisos y su pivote; no redefine funciones ni tablas de reportes y no existe en la reproducción de HEAD.

## Diferencias relevantes entre HEAD y la implementación

| Área | HEAD | Árbol actual |
| --- | --- | --- |
| Catálogo operativo | Catálogo anterior | Añade el código de gestión; conteo calculado dinámicamente. |
| Plantilla owner | Subconjunto de capacidades operativas | Conjunto completo del catálogo autorizado. |
| API de matrices | Sin las tres operaciones nuevas | Catálogo, GET de matriz y PUT de reemplazo, con autorización delegable. |
| Revisión y auditoría | Sin revisión de matriz | Columna de revisión, locks PostgreSQL y auditoría transaccional existente reutilizada. |
| Último owner | Permitía retirarlo si quedaba administrator | Exige conservar otro owner activo bajo bloqueo del laboratorio. |
| Aprovisionamiento general | Sincronizaba matrices existentes | Inicializa roles faltantes y conserva las matrices existentes. |
| Caché de permisos | Configuración previa | Store array por operación, recarga HTTP y limpieza al iniciar trabajo de cola. |
| Reportes y migraciones históricas implicadas | Lógica anterior | Sin cambios de lógica; mismo fallo reproducido. |
| Fixtures del owner/catálogo | Expectativas anteriores | Expectativas ajustadas y aprobadas en la verificación actual. |

## Riesgos pendientes y recomendación de liberación

- **Integración frontend recomendada con observaciones.** Las tres operaciones cumplen el contrato y las decisiones ratificadas. No hay motivo confirmado para bloquear la integración del módulo por los fallos históricos de reportes.
- **Validación global pendiente.** Corregir en un trabajo separado el aislamiento de la prueba que alterna esquemas y estudiar el caso intermitente de concurrencia; después volver a ejecutar toda la suite. No declarar una liberación general completamente validada basándose solo en esta verificación.
- **Entorno de integración.** Aplicar la migración del módulo y regenerar configuración/reiniciar procesos persistentes por el procedimiento habitual. No se comprobó ni modificó el estado de una base compartida o un despliegue real.
- **Datos existentes.** La plantilla completa de owner corresponde al aprovisionamiento general nuevo. Usar la matriz y los permisos efectivos reales del laboratorio; no asumir una cantidad fija ni que un perfil DEMO esté autorizado para gestionar matrices.
- **Contrato frontend.** Autorizar la pantalla por `roles.permissions.manage`, enviar `X-Laboratory-ID`, lista completa de códigos, `expected_revision` y `reason`; tratar 409 recargando para revisión. Después de una modificación, refrescar `GET /auth/authorization`; revocar gestión puede producir 403 manteniendo la sesión autenticada.
- **Lista de roles.** `GET /laboratory/roles` conserva la función de roles asignables a usuarios y sus restricciones anteriores. No debe tratarse como catálogo de matrices para un gestor delegado. Las consultas de matriz admiten los seis nombres predefinidos como parámetro.
- **Costo de caché.** La coherencia por solicitud añade lecturas de base de datos. No se ejecutó una prueba de carga ni una validación de navegador/frontend en producción.
- No se cambian decisiones de producto ni se introducen listas de permisos no delegables. Las restricciones clínicas/profesionales y financieras existentes siguen aplicándose.

## Reproducción

Selección comparativa utilizada en la copia de HEAD y en el árbol actual, cada una en su propio PostgreSQL efímero:

```sh
php vendor/bin/phpunit --do-not-cache-result \
  tests/Feature/ClinicalFinalization/StructuredClinicalExceptionTest.php \
  tests/Feature/ClinicalReports tests/Feature/ReportDelivery \
  --filter 'test_migration_preserves_preexisting_text_requests_decisions_report_snapshots_and_pdf|ClinicalReports|ReportDelivery'
```

Control mínimo de dos reportes, ejecutado en un proceso nuevo:

```sh
php vendor/bin/phpunit --do-not-cache-result --filter 'test_not_performed_preserves_original_reference'
```

Los Compose, controles temporales, salidas de ambas versiones, inventario JSON completo y comparación de identificadores están en `/tmp/rbac-verification.2gLCv7`. El Compose original de pruebas mantiene el código montado de solo lectura y las guardas de PostgreSQL. El conteo del catálogo se obtuvo de la salida `DYNAMIC_OWNER_EVIDENCE` de `current-verification.log`.

## Anexo: diez fallos de aserciones del registro global

| Prueba del registro original | Mensaje | Clasificación |
| --- | --- | --- |
| `Tests\Feature\ClinicalReasons\ClinicalReasonAdoptionTest::test_restrictive_change_creates_pending_obligations_and_only_favorable_evaluation_unblocks` | Expected response status code [403] but received 201.<br>Failed asserting that 201 is identical to 403. | Fixture ajustado y aprobado en la selección actual |
| `Tests\Feature\ClinicalReasons\ClinicalReasonAdoptionTest::test_clinical_review_permission_is_explicit_and_isolated_across_a_b_a` | Expected response status code [403] but received 201.<br>Failed asserting that 201 is identical to 403. | Fixture ajustado y aprobado en la selección actual |
| `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_real_concurrent_incidence_change_during_render_rejects_stale_pending_snapshot_and_cleans_pdf` | Failed asserting that two strings are identical.<br>--- Expected<br>+++ Actual<br>@@ @@<br>-'report_evidence_conflict'<br>+'report_generation_unavailable' | Intermitente; causa no concluyente |
| `Tests\Feature\ClinicalReports\ClinicalReportConcurrencyTest::test_parallel_issuances_use_real_connections_and_operation_mutex#0 with data ('same_key')` | {"status":503,"code":"report_issuance_failed"}<br>Failed asserting that 503 is identical to 201. | Preexistente confirmado en HEAD y actual |
| `Tests\Feature\ClinicalReports\ClinicalReportConcurrencyTest::test_parallel_issuances_use_real_connections_and_operation_mutex#1 with data ('different_keys')` | {"status":503,"code":"report_issuance_failed"}<br>Failed asserting that 503 is identical to 201. | Preexistente confirmado en HEAD y actual |
| `Tests\Feature\ClinicalReports\ClinicalReportConcurrencyTest::test_parallel_issuances_use_real_connections_and_operation_mutex#2 with data ('changed_payload')` | {"status":503,"code":"report_issuance_failed"}<br>Failed asserting that 503 is identical to 201. | Preexistente confirmado en HEAD y actual |
| `Tests\Feature\ClinicalReports\ClinicalReportIntegrityTest::test_no_settings_or_issuance_permission_is_automatically_assigned_to_roles` | Failed asserting that an array is empty. | Fixture ajustado y aprobado en la selección actual |
| `Tests\Feature\ClinicalReports\ClinicalReportTest::test_identity_confirmation_is_required_for_older_orders_and_no_history_is_rewritten` | Expected response status code [201] but received 503.<br>Failed asserting that 503 is identical to 201. | Preexistente confirmado en HEAD y actual |
| `Tests\Feature\ClinicalValidation\ValidationSecurityTest::test_validation_checks_effective_authorization_and_line_state#3 with data ('owner', 403)` | Expected response status code [403] but received 200.<br>Failed asserting that 200 is identical to 403. | Fixture ajustado y aprobado en la selección actual |
| `Tests\Feature\LaboratoryOrderSeederTest::test_late_audit_failure_rolls_back_the_entire_dataset` | Failed asserting that table [permissions] matches expected entries count of 0. Entries found: 1.<br>. | Fixture ajustado y aprobado en la selección actual |

## Anexo: 62 errores de emisión del registro global

Cada identificador siguiente se reprodujo en HEAD y en el árbol actual con el mismo mensaje de emisión temporalmente no disponible. Los casos con varios datasets se identifican por su sufijo.

- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_not_performed_preserves_original_reference_and_private_evidence_is_not_patient_projection#0 with data ('global')`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_not_performed_preserves_original_reference_and_private_evidence_is_not_patient_projection#1 with data ('local')`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_new_catalog_version_or_deactivation_cannot_rewrite_archived_or_reissued_reason#0 with data ('global')`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_new_catalog_version_or_deactivation_cannot_rewrite_archived_or_reissued_reason#1 with data ('local')`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_legacy_issuance_bytes_snapshots_and_idempotency_survive_the_prospective_format_migration`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_mixed_report_keeps_validated_values_and_exception_without_fabricated_results`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_incomplete_is_only_informational_and_unvalidated_values_cannot_be_issued#0 with data (false)`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_incomplete_is_only_informational_and_unvalidated_values_cannot_be_issued#1 with data (true)`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_sql_cannot_forge_patient_projection_and_new_report_history_is_immutable`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_clinical_exception_does_not_authorize_financial_delivery_or_download_and_tenant_access`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_generic_historical_exception_is_not_reclassified_from_current_catalogue_text`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_sql_cannot_replace_informational_pending_exams_with_terminal_or_fabricated_content`
- `Tests\Feature\ClinicalReports\ClinicalExceptionReportTest::test_validated_but_not_finalized_exam_is_pending_and_is_not_inferred_to_be_incomplete`
- `Tests\Feature\ClinicalReports\ClinicalReportIntegrityTest::test_sql_cannot_insert_a_changed_validated_value`
- `Tests\Feature\ClinicalReports\ClinicalReportIntegrityTest::test_deferred_trigger_requires_all_report_lines_before_commit`
- `Tests\Feature\ClinicalReports\ClinicalReportIntegrityTest::test_down_refuses_existing_report_history`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_real_pdf_preserves_precise_history_and_has_private_hash_and_no_public_medical_data`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_complete_uses_closure_and_retry_keeps_bytes_and_only_one_issuance`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_public_verification_exposes_exact_allowlist_and_rate_limit_without_tenant`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_successive_emissions_keep_previous_files_visual_and_catalog_history`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_changed_payload_with_same_key_conflicts_without_another_pdf`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_internal_permissions_are_separate_and_foreign_order_is_not_visible`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_logo_is_reencoded_and_active_content_or_html_are_rejected`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_sql_cannot_update_or_delete_report_evidence#0 with data ('laboratory_report_issuances', 'UPDATE')`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_sql_cannot_update_or_delete_report_evidence#1 with data ('laboratory_report_issuances', 'DELETE')`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_sql_cannot_update_or_delete_report_evidence#2 with data ('laboratory_report_issuance_lines', 'UPDATE')`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_sql_cannot_update_or_delete_report_evidence#3 with data ('laboratory_report_issuance_lines', 'DELETE')`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_exception_issuance_contains_only_approved_exception_and_no_invented_values`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_prospective_identity_and_area_are_immutable_and_never_reconstructed_from_current_catalogs`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_existing_pdf_integrity_failure_prevents_internal_download`
- `Tests\Feature\ClinicalReports\ClinicalReportTest::test_later_publication_never_changes_applied_historical_references`
- `Tests\Feature\ReportDelivery\ReportDeliveryConcurrencyTest::test_delivery_races_use_independent_postgresql_connections@two exception authorizations with data ('two_authorizations', 'report_delivery_exception_rev...nflict')`
- `Tests\Feature\ReportDelivery\ReportDeliveryConcurrencyTest::test_delivery_races_use_independent_postgresql_connections@one exception cannot back two deliveries with data ('exception_reuse', 'report_delivery_exception_unavailable')`
- `Tests\Feature\ReportDelivery\ReportDeliveryConcurrencyTest::test_delivery_races_use_independent_postgresql_connections@same delivery retry is idempotent with data ('same_idempotency_key', 'ok')`
- `Tests\Feature\ReportDelivery\ReportDeliveryConcurrencyTest::test_delivery_races_use_independent_postgresql_connections@one preparation cannot back two deliveries with data ('same_preparation', 'report_delivery_preparation_consumed')`
- `Tests\Feature\ReportDelivery\ReportDeliveryConcurrencyTest::test_delivery_races_use_independent_postgresql_connections@policy change invalidates preparation with data ('policy_change', 'report_delivery_evidence_changed')`
- `Tests\Feature\ReportDelivery\ReportDeliveryConcurrencyTest::test_delivery_races_use_independent_postgresql_connections@commercial evidence change invalidates preparation with data ('commercial_evidence_change', 'report_delivery_evidence_changed')`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_default_is_restrictive_without_persisting_or_inventing_financial_evidence`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_policy_change_is_revisioned_audited_and_tenant_scoped`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_typed_commercial_evaluation_applies_each_policy@full paid with data ('full_payment_required', 'satisfied', 'full_payment', true)`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_typed_commercial_evaluation_applies_each_policy@full rejects credit with data ('full_payment_required', 'satisfied', 'authorized_credit', false)`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_typed_commercial_evaluation_applies_each_policy@credit policy paid with data ('payment_or_credit', 'satisfied', 'full_payment', true)`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_typed_commercial_evaluation_applies_each_policy@credit policy credit with data ('payment_or_credit', 'satisfied', 'authorized_credit', true)`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_typed_commercial_evaluation_applies_each_policy@known unpaid with data ('full_payment_required', 'not_satisfied', 'none', false)`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_typed_commercial_evaluation_applies_each_policy@provider unavailable with data ('payment_or_credit', 'unavailable', 'none', false)`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_exception_preparation_download_and_confirmed_delivery_are_separate_and_financially_inert`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_internal_download_and_prepared_copy_do_not_claim_delivery`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_manual_digital_and_multiple_legitimate_deliveries_are_independent_and_idempotent`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_recipient_authorization_must_be_explicit_and_compatible@unconfirmed with data ([false])`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_recipient_authorization_must_be_explicit_and_compatible@wrong patient basis with data (['legal_authority_verified'])`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_recipient_authorization_must_be_explicit_and_compatible@client patient name with data (['Injected name'])`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_recipient_authorization_must_be_explicit_and_compatible@representative without name with data (['legal_representative', 'legal_authority_verified'])`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_recipient_authorization_must_be_explicit_and_compatible@empty evidence with data (['   '])`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_unused_authorization_can_be_revoked_but_never_used_afterwards`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_exception_authorization_requires_its_sensitive_permission`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_expired_preparation_and_client_financial_flags_are_rejected`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_postgresql_rejects_policy_state_without_matching_history`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_down_refuses_existing_commercial_history`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_cross_tenant_resources_are_hidden_and_permissions_are_independent`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_commercial_endpoints_never_expose_clinical_values_or_public_access`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_delivery_history_and_report_evidence_are_immutable_in_postgresql`
- `Tests\Feature\ReportDelivery\ReportDeliveryTest::test_audit_failure_rolls_back_delivery_and_exception_consumption`
