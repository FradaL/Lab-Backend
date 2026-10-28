# BE-EXC-02.6 — Validación integral y cierre técnico

Fecha: 2026-10-25. Solicitud: adjunto `150444e3-e6f8-4f3c-94d2-5857efa8ca0d`. Laravel: `laravel/`, framework 13.32.0. Backend HEAD `8acf147079365f227490489e172523dde715d02f`, producto HEAD `bdfe29dd9968d3aff10e4336ffcd2c0ab522b6e0` con cambios BE-EXC-02.5 **preexistentes y conservados**, incluidos archivos no versionados. Línea base SHA-256 de 1.557 archivos: `/tmp/be-exc-026-baseline.json`; textos Markdown iniciales: `/tmp/be-exc-026-docs-before.json`.

## Alcance y método

Auditoría de BE-EXC-02 completo y su consumo BE08: persistencia, gobierno y accesos SaaS, catálogo/adopciones locales, impacto, revisiones/evaluaciones, política, referencias originales y lecturas compartidas. Se contrastan código, migraciones, rutas, OpenAPI, permisos, consumidores, eventos y pruebas reales; un informe anterior no sustituye la ejecución actual.

Fuentes funcionales consultadas: DF-019 APROBADO 1.4 e historial completo, DF-016 APROBADO 1.0, plan de excepciones, AUD-002, inventario, reglas de resultados/muestras y flujos. Se mantienen íntegros y sin cambios de esta entrega DF-019 y las demás decisiones. No hay nuevas decisiones clínicas o concesiones automáticas.

Evidencia anterior conservada: [02.1](BE-EXC-02.1-identidad-versionado-motivos-clinicos.md), [02.2-A](BE-EXC-02.2-A-frontera-autorizacion-saas.md), [02.2-B](BE-EXC-02.2-B-administracion-catalogo-global.md), [02.3](BE-EXC-02.3-implementacion.md), [02.3-I](BE-EXC-02.3-I-integracion-clinica.md), [02.4](BE-EXC-02.4-motivos-locales.md), [02.5 y guía de consumo](BE-EXC-02.5-contratos-lectura.md), [BE08](BE-S2-08-finalizacion-clinica-ordenes.md). Sus cifras corresponden a esas ejecuciones; no se atribuyen a 02.6.

La tarea no incorpora funcionalidades. Las correcciones se limitan a defectos demostrados de pruebas históricas y se amplía cobertura de seguridad/concurrencia/performance de los contratos existentes. Sin frontend, Trello, commits, push, migraciones de desarrollo/producción o eliminación de datos históricos. Pruebas exclusivamente en PostgreSQL temporal aislado.

## Inventario de componentes y consumidores

Los sufijos tenant llevan /api/v1/laboratory; los SaaS /api/v1/platform. auth/Sanctum en ámbitos protegidos; tenant añade usuario/laboratorio/membresía/suscripción activos y Teams. Cada escritura administrativa vuelve a autorizar en servicio. Las rutas y schemas concretos se conservan en la matriz de 02.5 y se verifican de nuevo con la suite completa.

| Subentregable | Modelos / migraciones | Servicios / política / controladores | Rutas y protección | Auditoría / pruebas |
| --- | --- | --- | --- | --- |
| 02.1 identidad/versiones | GlobalClinicalReason/Version y LaboratoryClinicalReason/Version; 2026_10_25_062239 | ClinicalReasonVersionStore, traits ImmutableClinicalReasonRecord/BelongsToLaboratory; consumidores reales: managers global/local. | Sin ruta directa al store. Autorización en fronteras de aplicación; el store es persistencia de bajo nivel, no un comando clínico alternativo. | Actor/fecha/justificación inmutables; ClinicalReasonPersistenceTest y ClinicalReasonConcurrencyTest. |
| 02.2-A acceso SaaS | SaasCapability/Assignment, SaasAuditLog; 2026_10_25_070208 | SaasAccessAuthority, SaasAccessManager, SaasAuditWriter, bootstrap explícito, SaasAccessController. | /platform/access/* con saas.access.manage; middleware saas.capability. No rol tenant equivalente. | saas.access.granted/revoked; SaasAccessTest y 7 carreras de accesos. |
| 02.2-B gobierno global | Identidades/versiones globales; ampliación auditoría 2026_10_25_073106 | GlobalClinicalReasonManager → VersionStore/AuditWriter; GlobalClinicalReasonController. | /platform/clinical-reasons: crear/leer, versiones/eventos, publicación/activación; capacidades exactas view/create/publish/manage. | saas.clinical_reasons.created/published/activation_changed; GlobalClinicalReasonAdministrationTest y 5 carreras. |
| 02.3 impacto/adopción/revisión | GlobalClinicalReasonImpact, ClinicalReasonAdoption/Version, ClinicalReasonImpactReview/Resolution; 2026_10_25_081115 y 081429 | GlobalClinicalReasonImpactManager, ClinicalReasonAdoptionManager, ClinicalReasonReviewStore, ClinicalReasonEligibilityPolicy; controladores impacto/adopción. | Impacto SaaS classify_impact; tenant global.view, adoptions.view/create/update/history, review_impact. Owner sólo para adoptar/actualizar. | impact_declared, adopted/adoption_updated, review_required/review_evaluated; pruebas impacto/adopción y 9 carreras. |
| 02.3-I integración global | ClinicalExceptionReasonReference y conjunto previo sellado; 2026_10_25_200359 | ClinicalExceptionReasonGuard y ClinicalResolutionService consumen la política; ClinicalFinalizationController. Consumidores demo también usan el servicio. | POST .../exams/{line}/exceptions y /{exception}/approve/reject: permisos request/approve; GET con view. Sin escritura textual alternativa para nuevas solicitudes. | order_clinical.exception_requested/approved/rejected, referencia y evidencia de elegibilidad; StructuredClinicalExceptionTest y 9 carreras. |
| 02.4 administración/uso local | Identidad/versiones existentes, ClinicalExceptionLocalReasonReference, LocalClinicalReasonReview/Resolution; 2026_10_25_213438 | LocalClinicalReasonManager/ReviewStore, política y guard existentes; LocalClinicalReasonController y BE08. | /clinical-reasons/local con local.view/create/publish/manage/history; escritura además owner. Evaluación usa review_impact independiente. | local_created/local_published/local_activation_changed, local_review_required/local_review_evaluated; LocalClinicalReasonTest y 15 carreras locales tras ampliación 02.6. |
| 02.5 lecturas compartidas | Sin tablas nuevas; proyecciones de referencias/versiones/evaluaciones existentes. | ClinicalReasonReadService/Controller, política única, historial BE08 y auth/me/auth/authorization. | GET /clinical-reasons: adoptions.view O local.view, con cada origen restringido a su permiso. Sin nueva facultad. | Lecturas sin mutación/auditoría de éxito ficticia; ClinicalReasonReadTest, contratos y benchmark 02.6. |
| 02.6 validación | Sin migraciones/modelos nuevos. | Auditoría, suite completa, metadatos PostgreSQL y cobertura suplementaria. | Sin endpoints ni permisos nuevos. | Este informe y ClinicalReasonIntegralAuditTest; fixtures históricos corregidos manteniendo sus aserciones. |

No se encontró un servicio clínico sensible sin consumidor o una ruta pública directa a las primitivas de persistencia. Los almacenes de revisión global/local son distintos por causas y relaciones aprobadas; no constituyen políticas de elegibilidad paralelas. Las evaluaciones globales son por adopción; las locales por solicitud original. No se elimina ningún componente por mera apariencia de redundancia.

## Matriz de seguridad multi-tenant

| Superficie | Protección comprobada | Evidencia verificable |
| --- | --- | --- |
| Identidades/versiones locales | Filtro forLaboratory en lectura/escritura, actor/parent FK tenant, identidad/versiones inmutables. | PersistenceTest: padre/actor ajenos y SQL; LocalTest: motivo/versión ajenos; auditoría nueva: usuario con permisos en ambos laboratorios recibe 404 al trasplantar IDs en contexto A. |
| Adopciones/selecciones globales | Laboratorio de contexto + razón/adopción/versión compuestas; no basta un motivo global válido. | AdoptionTest: historia/updates/eligibility/reviews/evaluación/actualización ajenas; StructuredExceptionTest: referencia extranjera/no adoptada/manipulada. |
| Revisiones/evaluaciones | Parent tenant, causa original y última revisión exacta; scope de motivo/adopción/solicitud. | Casos existentes de cruce, sucesión e inmutabilidad; auditoría nueva de evaluación local extranjera, sin nueva resolución/auditoría. |
| Solicitud/aprobación/historial BE08 | Orden, línea y solicitud pertenecen al lab autenticado. Aprobación recupera referencia persistida y exige otro usuario autorizado. | StructuredExceptionTest y FinalizationIntegrityTest; auditoría nueva: consultar/aprobar orden B bajo contexto A falla aun siendo actor válido en ambos labs. |
| Auditoría | Lectura de eventos por laboratorio/identidad; SaaS exige capacidad separada. | Eventos locales extranjeros 404; no cambios en audit_logs tras intentos; SaasAccessTest y gobierno global. |
| Catálogo unificado | Cada rama SQL parte del laboratorio del servidor y sólo del origen autorizado; no acepta laboratory_id como filtro. | ReadTest: origen sin permiso 403, lab ajeno, búsqueda SQL literal, referencias ready-to-post; auditoría A sólo devuelve su motivo. |
| Base de datos | FK compuestas verifican pertenencia aun con actor miembro válido de ambos labs. | Inserción SQL de versión B bajo lab A rechazada con SQLSTATE 23503; no fila parcial. No se basa sólo en X-Laboratory-ID. |

X-Laboratory-ID selecciona contexto, no concede autoridad. Middleware exige sesión/estado SaaS y membresía; los servicios revalidan usuario, Teams y permisos. Conocer IDs no cambia esos controles. La autoridad de SQL administrativo no se equipara a una petición autenticada: las restricciones garantizan estructura/inmutabilidad, mientras la autorización funcional permanece en los servicios y middleware existentes.

## Matriz de separación de autoridades

| Facultad | Autoridad exigida | No concede |
| --- | --- | --- |
| Gestionar accesos proveedor | saas.access.manage explícita y cuenta activa | Crear/publicar/clasificar motivos, roles tenant o aprobar. |
| Crear/leer/publicar/activar globales | saas.clinical_reasons.create/view/publish/manage exactas | Otra facultad SaaS por implicación o uso clínico tenant. |
| Declarar impacto | saas.clinical_reasons.classify_impact | Gobierno global completo, revisión tenant o aprobación. |
| Crear/publicar/activar locales | owner + local.create/publish/manage; lab/membresía/suscripción activos | review_impact, request, approve. Otros roles no administran V1 sólo por recibir permiso. |
| Adoptar/actualizar global | owner + adoptions.create/update | Incorporación automática, nueva clasificación global o autorización clínica. |
| Evaluar revisión | clinical_reasons.review_impact explícito | Solicitar, aprobar, resolver línea o cerrar orden. |
| Solicitar | orders.clinical_exceptions.request + guardas BE08/política | Aprobar la propia solicitud o finalizar. |
| Aprobar/rechazar | orders.clinical_exceptions.approve; independencia adicional para aprobar | Cierre automático de orden ni reemplazo de referencia. |
| Leer catálogo/acciones | Permisos de lectura por origen y contexto válido | Autoridad de escritura por un booleano informativo o por rol owner. |

Se conservan 95 permisos tenant y seis roles; catálogo SaaS separado. La suite ejerce facultades parciales, revocación, usuario desactivado y contexto engañoso tanto por HTTP como por servicios. auth/me sólo devuelve códigos propios vigentes; auth/authorization conserva permisos del tenant. Las acciones del frontend son informativas y no participan como autorización de escritura.

## Integridad PostgreSQL, versiones y auditoría

Se inspeccionaron migraciones y catálogo real del servidor temporal: FK, claves compuestas, unique, CHECK, predecesoras, índices y triggers. Artefacto `/tmp/be-exc-026-artifacts/postgresql-schema.json`: 165 restricciones (76 FK, 37 unique, 22 PK, 22 CHECK y 8 constraint triggers), 46 triggers de aplicación y 68 índices de las tablas de motivos, excepciones y SaaS incluidas en la consulta. No son conteos de toda la aplicación ni sustituyen los casos de escritura rechazados.

- Identidad/código estables; sucesoras con número consecutivo y predecesora exacta. UPDATE/DELETE rechazados por modelo y SQL.
- Versiones contienen nombre, descripción, clase, evidencia estructural, actor, fecha y justificación originales. No se reconstruyen desde catálogo actual.
- Adopciones/versiones y referencias clínicas enlazan razón/versión/laboratorio/solicitud con FK compuestas; una solicitud nueva requiere exactamente un origen global o local al confirmar.
- Revisiones se crean con causas explícitas verificadas; locales conservan las obligaciones ante desactivación/cambio de clase, incluso revertido. Un commit de versión local con pendientes no puede omitir las revisiones obligatorias.
- Evaluaciones y declaraciones son sucesoras inmutables, con revisión y predecesora exactas. Sólo favorable levanta una obligación; no puede sobrescribirse o eliminarse para eludir otra.
- Auditoría SaaS exige actor/capacidad/razón/versiones coincidentes y evidencia de declaración; tenant registra referencia, causa y evaluaciones en la misma transacción. Los fallos inyectados de auditoría revierten la operación completa.
- El conjunto anterior de solicitudes es finito y sellado. No se extiende por payload ni se asocia a catálogo por texto. Rollback con historia protegida se niega; no se usa CASCADE para silenciar dependencias.

Los validadores Laravel cubren además límites/forma de entrada y permisos; no se presentan los CHECK como una réplica de toda autorización o validación de interfaz. Los consumidores reales del VersionStore pasan por managers autorizados. Se mantiene la frontera de persistencia de bajo nivel, utilizada también en fixtures sintéticos; no existe un endpoint que la publique como comando sin autorización.

## Elegibilidad y BE-S2-08

La misma ClinicalReasonEligibilityPolicy sirve proyecciones de lectura y evaluación de escritura; el guard exige clase terminal y selección estructurada vigente al solicitar. Aprobación utiliza la versión original persistida. No hay una política clínica implementada en el frontend ni una segunda política por origen.

Globales: se verifican adopción/versión exactas, falta de clasificación vigente, inactivación, declaraciones no restrictivas/restrictivas, obligaciones, resultados favorables/no favorables y persistencia de obligación al actualizar adopción/corregir impacto. Global no adoptado no entra en el selector y no se usa con una referencia inventada.

Locales: versión vigente activa/terminal al solicitar; original al aprobar. Desactivación y cambio explícito de clasificación crean revisiones de pendientes; revertir o reactivar no las borra. Nombre/metadata sin cambio clínico explícito no reciben automáticamente impacto global. Una revisión local de otra solicitud no bloquea una solicitud nueva válida. No se exige declaración SaaS a locales.

Todas las vías de crear/decidir excepciones desembocan en ClinicalResolutionService con validación transaccional, revisiones exactas, usuario/membresía/permisos frescos y política antes de escribir. Rechazar sigue permitido con sus guardas sin exigir favorable del catálogo. finalizeLine resuelve resultados validados; finalizeOrder cierra explícitamente con resoluciones ya existentes. No crean una excepción autorizada alternativa ni aprueban implícitamente por cierre.

Se conservan ausencia de autoaprobación, bloqueo por validaciones históricas/obsolescencia, no invención de procesamiento/muestras/resultados, versión original, rechazo y cierre DF-016. Los consumidores demo usan el mismo servicio con catálogo FICTICIO explícito, sin bootstrap SaaS automático. Los campos de lectura reason_eligibility se añaden aparte de la referencia original y no modifican filas/snapshots.

## Matriz de concurrencia real

Las pruebas usan pcntl_fork y conexiones PostgreSQL independientes, con sincronización y comprobación de espera efectiva mediante pg_stat_activity. Una secuencia de dos llamadas en un proceso no se cuenta como carrera real. El orden aplicado a comandos es advisory lock compartido (182045,1) antes de filas de catálogo/orden/línea y autorización; se conserva hasta commit.

| Escenario requerido | Prueba concurrente real | Resultado que debe verificarse |
| --- | --- | --- |
| 1. Dos publicaciones | GlobalClinicalReasonAdministrationConcurrencyTest publish; LocalClinicalReasonConcurrencyTest duplicate version | Una sucesora, otra operación conflictiva; no bifurcación. |
| 2. Dos adopciones | ClinicalReasonAdoptionConcurrencyTest adopt | Una adopción; segunda conflicto, sin sobrescritura. |
| 3. Dos actualizaciones | ClinicalReasonConcurrencyTest global/local por store y SQL; publicación/activación local/global | Predecesora/número exactos, actualizador obsoleto rechazado. |
| 4. Desactivación/solicitud | LocalConcurrency ambos órdenes | Si desactiva primero, referencia previa no válida; si solicita primero, obligación posterior preservada. |
| 5. Desactivación/aprobación | LocalConcurrency ambos órdenes | Bloqueo si obligación confirma antes; si aprueba antes, decisión no revocada retroactivamente. |
| 6. Restrictiva/aprobación | StructuredClinicalExceptionConcurrencyTest ambos órdenes | Restricción previa bloquea; aprobación ya confirmada conserva evidencia. |
| 7. Favorable/aprobación | StructuredConcurrency y LocalConcurrency | Favorable sólo levanta su obligación; aprobación separada con contexto original. |
| 8. No favorable/aprobación | StructuredConcurrency y nuevo caso local 02.6 | Permanece bloqueada; no resolución clínica accidental. |
| 9. Cambio de versión/solicitud | Dos casos locales explícitos añadidos en 02.6 | Publicación previa invalida referencia vieja; solicitud previa conserva original al publicarse sucesora. |
| 10. Laboratorios distintos | StructuredConcurrency other_lab y nuevo caso de publicación local en dos labs | Serialización técnica sin mezclar datos ni bloquear funcionalmente otro motivo/lab tras commit. |
| 11. Revocación/operación sensible | SaasAccessConcurrency, GlobalAdministrationConcurrency, StructuredConcurrency y BE08Concurrency | Revalidación bajo locks; revocación confirmada primero impide operar. |
| 12. Dos aprobaciones de una solicitud | BE08Concurrency global y nuevo caso local | Una decisión/resolución; segunda rechazada por revisión/estado. |

No se encontró un bypass por una lectura de elegibilidad anterior: las pruebas de lectura seguida de cambio y las carreras de escritura son complementarias. Las carreras entre escritores son reales; el GET informativo no se presenta como una reserva o una garantía de éxito futuro.

## Contratos REST y OpenAPI

Se contrastan 176 operaciones OpenAPI, 184 rutas del bootstrap de tests, 150 rutas tenant con can: y la lectura unificada con OR de permisos existentes mediante Spatie. auth/Sanctum, contexto y suscripción se aplican por el grupo SaaS tenant; las rutas de proveedor usan capacidades independientes.

Exportaciones regeneradas en el contenedor aislado: `/tmp/be-exc-026-artifacts/openapi.json` (OpenAPI 3.1.0) y `routes.json`. Las 176 operaciones documentadas tienen correspondencia exacta de método/ruta en Laravel, IDs de operación únicos y 986 referencias internas sin destinos ausentes. El ámbito directo contiene 33 operaciones de motivos, seis de accesos SaaS y cuatro de excepciones; finalización/cierre también se revisan como consumidores. Las 150 rutas con `can:` aparecen en la exportación CLI con su middleware expandido `Illuminate\Auth\Middleware\Authorize`; la lectura unificada aparece con `Spatie\Permission\Middleware\PermissionMiddleware` y sus dos permisos OR.

Las pruebas de contratos ejercen `GlobalClinicalReasonAdministrationTest`, `ClinicalReasonAdoptionTest`, `LocalClinicalReasonTest`, `ClinicalReasonReadTest`, `SaasAccessTest` y `StructuredClinicalExceptionTest`, además de autorización/transversal y BE08. Comprueban permisos exactos, contexto, sesión, estados y schemas en sus superficies; las operaciones antiguas expresan parte de la autoridad en `summary`, las nuevas además usan extensiones OpenAPI. La ausencia de una extensión opcional no se confunde con la ausencia de middleware.

Se revisan métodos, parámetros, cuerpos, orígenes, IDs, versiones, campos aditivos, paginación/filtros, códigos de bloqueo y acciones. El selector entrega reason_reference listo para POST y diferencia global adoptado/local. No se deriva identidad por nombre ni latest_version. Los códigos informativos no se aceptan como prueba de autorización.

La elegibilidad global antigua conserva sus campos de dependencias de adopción por compatibilidad y añade selection con el estado completo de selección; la guía 02.5 indica el contrato a consumir. El backend revalida en la transacción clínica. Las capacidades SaaS propias no exponen concesiones/auditoría ajenas. No hay nuevas rutas, permisos o schemas en 02.6.

## Rendimiento medido

Se registra un fixture sintético con 60 motivos locales, 300 versiones, una adopción global, 20 obligaciones de revisión global y 60 evaluaciones inmutables (dos no favorables y una favorable por obligación). El selector debe devolver sólo 50 registros por página y menos de 35 consultas incluyendo autorización, sin hidratar historias por fila. EXPLAIN ANALYZE/BUFFERS y tiempos de consultas se guardan en `/tmp/be-exc-026-artifacts/selection-performance.json`.

Medición ejecutada en la regresión relacionada: **18 consultas** incluyendo autorización, **21.42 ms** de suma SQL; EXPLAIN de la selección: **2.221 ms** de planificación, **2.333 ms** de ejecución, **50 filas**. La consulta COUNT de paginación sigue incluida en el presupuesto de consultas. La fixture no contiene historia de revisiones locales ni representa el volumen real de producción.

Las claves únicas reason/number, adoption/revision y review/revision cubren las búsquedas de sucesoras y última evaluación. Se revisa el plan real, sin proponer índices por mera presencia de una columna en WHERE. La comprobación es de carga sintética limitada, no un benchmark de producción ni un SLA. El lock global serializa temporalmente operaciones de distintos tenants; es un riesgo de capacidad conocido, no una filtración de datos. No se introduce caché de elegibilidad ni optimización especulativa.

## Hallazgos y correcciones

| ID / severidad | Descripción y evidencia | Impacto / componente | Corrección / prueba | Estado |
| --- | --- | --- | --- | --- |
| EXC026-01 / MEDIO | Seis fixtures de migraciones históricas intentaban retirar BE08 conservando FK de referencias 02.3-I/02.4; SQLSTATE 2BP01 reproducido. | La suite completa no podía validar compatibilidad histórica de exámenes, resultados y composición. PostgreSQL protegía correctamente las dependencias. | Retirar 02.4 y luego 02.3-I antes de padres; reaplicar en orden inverso al finalizar. Se conservan las aserciones de historia, indisponibilidad y ausencia de backfill; sin CASCADE ni cambios a migraciones. | CORREGIDO: 6/42 focalizadas, 126/748 relacionadas y suite completa final satisfactoria. |
| EXC026-02 / INFORMATIVO | Faltaban casos locales explícitos de publicación/solicitud, dos labs publicando, no favorable/aprobación y doble aprobación. | Brecha de evidencia concurrente, no defecto de producto demostrado. | Cinco carreras añadidas a la batería existente, con dos conexiones y espera real; no nuevas reglas. | CERRADO como brecha de evidencia: cinco carreras nuevas pasan en regresión relacionada y suite completa final. |
| EXC026-03 / INFORMATIVO | Tres casos exclusivos de SQLite se omiten al ejecutar PostgreSQL. | No cubren el catálogo PostgreSQL ni son una omisión de sus carreras o aislamiento. | Conservar omisión explícita y motivo por caso; no declarar todos los drivers verificados. | Justificado; detalle en resultados finales. |
| EXC026-04 / INFORMATIVO | Serialización global y costo de agregados de historia en lecturas frecuentes. | Límite de capacidad, sin SLA de producción definido o deadlock demostrado en los casos ejecutados. | Medición del fixture/plan y presupuesto N+1; conservar consistencia y no cachear decisiones clínicas. | Seguimiento residual; sin cambio funcional. |

Casos reproducidos y reparados de EXC026-01:

| Archivo | Casos históricos conservados |
| --- | --- |
| [ClinicalExamTest](../../laravel/tests/Feature/ClinicalExams/ClinicalExamTest.php) | test_legacy_metadata_edit_does_not_change_published_snapshot_or_existing_order_line; test_progressive_migration_preserves_legacy_exams_and_order_snapshots |
| [ClinicalResultTest](../../laravel/tests/Feature/ClinicalResults/ClinicalResultTest.php) | test_legacy_line_is_readable_as_unavailable_but_cannot_capture_or_infer_parameters |
| [AtomicOrderCompositionTest](../../laravel/tests/Feature/OrderComposition/AtomicOrderCompositionTest.php) | test_legacy_cohort_is_fixed_at_migration_and_never_enables_processing; test_migration_preserves_actual_historical_lines_without_clinical_backfill; test_legacy_adoption_uses_publication_for_new_lines_without_rewriting_compatibility_lines |

Cobertura suplementaria: [ClinicalReasonIntegralAuditTest](../../laravel/tests/Feature/ClinicalReasons/ClinicalReasonIntegralAuditTest.php) y [LocalClinicalReasonConcurrencyTest](../../laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php). Ninguna prueba nueva habilita funcionalidad o concede permisos fuera de sus fixtures sintéticos.

No se debilitan aserciones para ocultar fallos. Las migraciones y los controles de integridad del producto no se alteran para hacer pasar los fixtures. No se eliminan componentes por aparente duplicación.

## Ejecuciones y recomendación de cierre

Entorno: PHP 8.4.25, Laravel 13.32.0, PHPUnit 12.5.35 y PostgreSQL 17 real. Configuración normal `laravel/phpunit.xml`, colecciones **Unit y Feature**, sin filtros en ambas ejecuciones completas. Docker Compose dedicado `donqer-be-exc-026-full`, PostgreSQL tmpfs, red interna sin puertos publicados, código montado sólo lectura, caches/storage temporales. `TestDatabaseGuard` verifica la identidad real de la base de pruebas. Proyecto separado `donqer-be-exc-026-audit` para reproducciones/regresión, sin compartir el servidor de la suite completa.

| Ejecución | Pruebas | Aserciones | Fallos | Errores | Omitidas | Tiempo PHPUnit |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Suite completa inicial | 3.571 | 22.731 | 0 | 6 | 3 | 22:05.173 |
| Seis casos reproducidos y corregidos | 6 | 42 | 0 | 0 | 0 | Ver log focalizado |
| Suplementaria inicial | 18 | 162 | 0 | 0 | 0 | 00:49.284 |
| Regresión relacionada final | 126 | 748 | 0 | 0 | 0 | 02:38.761 |
| **Suite completa final** | **3.579** | **22.850** | **0** | **0** | **3** | **22:34.135** |

Memoria máxima final: 215.00 MB. El aumento de ocho casos respecto de la colección inicial corresponde a tres pruebas de auditoría integral y cinco datasets concurrentes locales. Las baterías dirigidas se solapan con la completa: no sumar cifras como pruebas únicas. La primera ejecución fallida queda registrada; no se presenta como verde.

- Unit: 46 pruebas / 70 aserciones; 0 errores, 0 fallos, 0 omitidas.
- Feature: 3.533 pruebas / 22.780 aserciones; 0 errores, 0 fallos, 3 omitidas.

Omisiones finales, conservadas por nombre y motivo, sin atribuir cobertura SQLite a esta ejecución PostgreSQL:

| Caso | Motivo comunicado por PHPUnit |
| --- | --- |
| LaboratoryOrderEconomicRecalculationTest::test_invalid_in_memory_representable_intent_rolls_back_on_sqlite | PostgreSQL prevents invalid intent before the calculator can receive it. |
| LaboratoryOrderDiscountMigrationTest::test_sqlite_value_triggers_survive_rollback_and_forward_migration | SQLite trigger audit. |
| LaboratoryOrderDiscountMigrationTest::test_sqlite_does_not_add_a_trigger_to_emulate_the_postgresql_discount_check | SQLite-specific documented enforcement difference. |

Comando completo final, desde la raíz backend (el proyecto inicial y el final comparten sólo su base temporal dedicada):

```bash
docker compose -p donqer-be-exc-026-full -f deploy/testing/compose.yaml run --rm \
  -v /tmp/be-exc-026-artifacts:/artifacts tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never \
  --display-skipped --display-incomplete --display-warnings --display-deprecations \
  --log-junit /artifacts/full-suite-final.xml \
  > /tmp/be-exc-026-full-final.log 2>&1
```

La corrida inicial usó el mismo comando con `full-suite.xml` y log `/tmp/be-exc-026-full-suite.log`. Las reproducciones conservan el mismo entorno y sus filtros `--filter`; logs `/tmp/be-exc-026-reproduction.log`, `/tmp/be-exc-026-result-reproduction.log`, `/tmp/be-exc-026-composition-reproduction.log`. Los seis casos finales están en `/tmp/be-exc-026-six-repaired.log`; el intento intermedio previo a completar la reparación de composición permanece en `/tmp/be-exc-026-six-intermediate.log`.

Comando de regresión relacionada, que también exporta esquema y medición:

```bash
docker compose -p donqer-be-exc-026-audit -f deploy/testing/compose.yaml run --rm \
  -v /tmp/be-exc-026-artifacts:/artifacts \
  -e DONQER_AUDIT_ARTIFACT_DIR=/artifacts tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never \
  --log-junit /artifacts/related.xml \
  tests/Feature/ClinicalExams/ClinicalExamTest.php \
  tests/Feature/ClinicalResults/ClinicalResultTest.php \
  tests/Feature/OrderComposition/AtomicOrderCompositionTest.php \
  tests/Feature/ClinicalReasons/ClinicalReasonIntegralAuditTest.php \
  tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php \
  > /tmp/be-exc-026-related.log 2>&1
```

Artefactos locales de ejecución: `/tmp/be-exc-026-artifacts/` contiene JUnit inicial/final/relacionado/suplementario, esquema PostgreSQL, rendimiento y exportaciones OpenAPI/rutas. Son evidencia temporal de esta sesión, sin datos de clientes; los comandos y resultados duraderos quedan en este informe. Los dos proyectos temporales se retiraron tras finalizar mediante Docker Compose down; se conservaron los artefactos locales y no se modificó ninguna base de desarrollo/producción.

### Resultado de concurrencia de la suite completa

**65 casos concurrentes reales satisfactorios** en las siete clases de catálogo/autoridad/BE08 inventariadas, incluidos los cinco casos locales añadidos. No hay errores, fallos u omisiones en esas clases. Esta cifra no cuenta otras carreras de módulos ajenos que también ejecuta la suite completa.

| Clase | Casos concurrentes reales satisfactorios |
| --- | ---: |
| ClinicalReasonConcurrencyTest | 4 |
| GlobalClinicalReasonAdministrationConcurrencyTest | 5 |
| ClinicalReasonAdoptionConcurrencyTest | 9 |
| StructuredClinicalExceptionConcurrencyTest | 9 |
| LocalClinicalReasonConcurrencyTest | 15 |
| SaasAccessConcurrencyTest | 7 |
| ClinicalFinalizationConcurrencyTest | 16 |

### Estado final por subentregable

| Subentregable | Estado técnico en este árbol | Límite |
| --- | --- | --- |
| BE-EXC-02.1 | Implementado y validado integralmente | Identidad/versionado, no autorización clínica por sí solos. |
| BE-EXC-02.2-A | Implementado y validado integralmente | Acceso SaaS explícito, sin grants de operación reales. |
| BE-EXC-02.2-B | Implementado y validado integralmente | Gobierno global separado de adopción/revisión/uso. |
| BE-EXC-02.3 | Implementado y validado integralmente dentro del catálogo | La marca parcial anterior correspondía al corte sin integración completa. 02.3-I/02.4 y la validación actual comprueban consumidores reales; evidencia/viabilidad integral pertenecen a 03/04. |
| BE-EXC-02.3-I | Implementado y validado integralmente | Uso global BE08 e historia previa conservada. |
| BE-EXC-02.4 | Implementado y validado integralmente | Administración y uso local con el contrato ratificado, sin impacto SaaS. |
| BE-EXC-02.5 | Implementado y validado integralmente | Contratos de lectura informativos y permisos por origen. |
| BE-EXC-02.6 | Validación integral completada | Este informe recomienda cierre técnico; el PO conserva el cierre administrativo. |

### Archivos de esta tarea y conservación de cambios previos

Comparación SHA-256 contra la línea base tomada al inicio de 02.6, incluyendo los cambios sin commit de 02.5:

- Nuevo: `laravel/tests/Feature/ClinicalReasons/ClinicalReasonIntegralAuditTest.php` (aislamiento privilegiado, SQL compuesto y medición).
- Ampliado: `laravel/tests/Feature/ClinicalReasons/LocalClinicalReasonConcurrencyTest.php` (cinco carreras).
- Reparados: `laravel/tests/Feature/ClinicalExams/ClinicalExamTest.php`, `laravel/tests/Feature/ClinicalResults/ClinicalResultTest.php`, `laravel/tests/Feature/OrderComposition/AtomicOrderCompositionTest.php` (orden de dependencias en seis fixtures; aserciones preservadas).
- Documentación backend: este informe y enlace desde `README.md`.
- Documentación producto: `README.md`, `01-contexto/inventario-funcional.md`, `02-reglas-negocio/resultados.md`, `03-decisiones/README.md`, `03-decisiones/registro-decisiones.md`, `04-flujos/README.md`, `06-especificaciones/README.md`, `06-especificaciones/plan-excepciones-clinicas-v1.md`, `07-auditorias/AUD-002-excepciones-clinicas-v1.md`, `07-auditorias/README.md`.

Los archivos de aplicación/rutas/modelos/migraciones/OpenAPI conservan sus hashes iniciales, incluida la implementación 02.5. Sus cambios documentales previos se conservan y reciben seguimiento adicional en esta entrega. DF-019 completo conserva exactamente su hash inicial, así como DF-016 y las demás fichas. AUD-002 conserva íntegros los cortes previos, y el plan conserva íntegro el antecedente 1.3. No se ejecutan commits/push ni operaciones Trello.

### Riesgos residuales y recomendación

No se encontraron defectos CRÍTICOS o ALTOS abiertos dentro del catálogo auditado. EXC026-01 está corregido y comprobado por la suite completa; EXC026-02 está cubierto por carreras reales. Persisten como límites informativos la capacidad del lock global y la ausencia de una prueba de carga de producción; las tres omisiones corresponden exclusivamente al driver SQLite.

El cierre técnico sólo corresponde al catálogo y sus integraciones. Evidencia clínica mínima, viabilidad/reposición, incidentes y representación/informes finales de BE-EXC-03/04/05 se mantienen pendientes. AUD-002-H01–H10 conservan sus estados integrales parciales/pendientes y no se dan por cerrados mediante esta auditoría. No se declara completado BE-EXC-06 en su alcance futuro transversal a 03/04/05, ni se acredita frontend o despliegue.

**A. BE-EXC-02 APTO PARA CIERRE TÉCNICO.**

La recomendación se sustenta en componentes implementados, contratos coherentes, facultades separadas, aislamiento HTTP/servicios/PostgreSQL, política compartida en operaciones reales, versionado e historia preservados, doce escenarios concurrentes con evidencia real y suite completa final satisfactoria. No equivale al cierre funcional de excepciones clínicas. La decisión administrativa final de cierre corresponde al Product Owner; no se modifica Trello.
