# BE-EXC-02.3 — Declaraciones, adopción y revisión de motivos globales

## Actualización posterior — BE-EXC-02.3-I

La [integración clínica 02.3-I](BE-EXC-02.3-I-integracion-clinica.md), del 2026-10-25, incorpora referencia global adoptada obligatoria para nuevas solicitudes y aplica la política de catálogo en solicitud/aprobación BE08 dentro de su transacción. Conserva la referencia original, historial, snapshots e informes; los IDs de solicitudes anteriores quedan en un conjunto sellado sin asociación inferida a catálogo. Local depende de 02.4; evidencia/viabilidad integral conserva BE-EXC-03/04. El informe enlazado registra resultados y límites de verificación. No se acredita producción ni se declara terminado todo el alcance de excepciones.

El contenido inferior se conserva como expediente del corte anterior a 02.3-I: sus payloads con reason libre y sus menciones de integración pendiente no describen el contrato actual para nuevas solicitudes. La justificación nueva se recibe separadamente y reason se deriva de la versión seleccionada. Consultar el informe y OpenAPI vigentes antes de integrar consumidores.

Fecha: **2026-10-25**. Fuente: solicitud «Selección, adopción y revisión de motivos clínicos globales por laboratorio», adjunto `bbb3a9aa-6acf-4494-936b-fc3b79664fcb`. D1–D6 expresamente aprobadas por el PO. Implementación del catálogo/adopción y de una política estructurada; **pendiente de integrar en comandos clínicos BE08**. No se declara completado BE-EXC-02.3 de extremo a extremo.

## Decisiones y diagnóstico actualizado

Se revisaron AGENTS, DF-019 APROBADO 1.3, DF-016, plan PROPUESTO 1.3, AUD-002, diagnóstico anterior, código/documentación 02.1/02.2-A/B y autenticación, Teams, auditoría, procesamiento y finalización. [Diagnóstico actualizado](BE-EXC-02.3-seleccion-adopcion-diagnostico.md). No se localizó informe BE-EXC-01 independiente: se conserva como fuente la solicitud de diseño adjunta `0c2eab9e-58d2-4cdb-b66f-0447f80d2c0c` y las referencias del plan/diagnósticos, sin atribuir contenido a un informe no disponible.

D1–D6 no se mantienen como pendientes: impacto explícito, inmutable y separado de versión; clasificación SaaS independiente; revisión clínica tenant con permiso específico; obligaciones persistentes; bloqueos por motivo y selección individual sin sincronización. No se modifica DF-019 ni documentación de producto; conserva APROBADO v1.3.

El PO ratificó además, mediante respuestas explícitas en esta conversación:

- Evaluación clínica obligatoria y resultado favorable/non_favorable. Sólo favorable levanta ese bloqueo, sin aprobar una excepción.
- Toda adopción previa de esa identidad recibe obligación ante una declaración restrictiva; cambiar adopción o corregir impacto no elimina obligaciones. Primera incorporación comprueba restricciones vigentes, también usando versiones antiguas.
- Sin clasificación se permiten consultas y adopción administrativa, pero no uso clínico dependiente hasta clasificación explícita. No se fabrica un riesgo restrictivo.

La semántica de desactivación de DF-019 I.A se conserva separada: una transición explícita activo→inactivo genera revisión con su causa, sin inferir una clasificación de impacto. La revisión favorable no habilita solicitudes nuevas con motivo inactivo; sólo permite continuar la evaluación de aprobaciones dependientes bajo las demás reglas clínicas.

## Arquitectura y persistencia

Se reutilizan ClinicalReasonVersionStore, modelos/definiciones globales, SaasAccessAuthority, concesiones/revocaciones A, SaasAuditWriter, AuditWriter/AuditEvent y middleware tenant existentes. No se crea un segundo sistema de roles o excepciones. laboratory_clinical_reasons sigue representando motivos personalizados; no se reutiliza para copiar adopciones globales.

Migraciones nuevas, PostgreSQL obligatorio:

| Archivo / tabla | Responsabilidad e integridad |
| --- | --- |
| 2026_10_25_081115_create_global_clinical_reason_impacts.php | Facultad classify_impact; declaraciones y referencia de auditoría SaaS. |
| global_clinical_reason_impacts | Identidad/version_id, transición desde predecesora inmediata (null para versión 1), revision, previous_id, clasificación, justificación, actor/hora. FK compuestas, revisión única por versión y cadena consistente; inmutable. |
| 2026_10_25_081429_create_laboratory_clinical_reason_adoptions.php | Cuatro tablas tenant, inmutabilidad, secuencia y causas referenciadas. |
| clinical_reason_adoptions | Identidad de selección única por laboratorio/motivo global; actor miembro del laboratorio, hora e identidad estable. |
| clinical_reason_adoption_versions | Selecciones sucesoras, versión global explícita y FK compuesta al motivo, revisión esperada y predecesora; actor miembro, fecha, justificación, estado administrativo adopted. Actualizaciones sólo a una versión global posterior; sin downgrade ni cambio implícito. |
| clinical_reason_impact_reviews | Laboratorio/adopción/motivo, versión adoptada al abrir, versión causante, declaración restrictiva o desactivación, causa, actor/hora. Unicidad por adopción/declaración y por adopción/desactivación. No se elimina ni reescribe al adoptar otra versión. |
| clinical_reason_impact_resolutions | Evaluaciones inmutables: revisión, predecesora, outcome, assessment, justificación, actor miembro y hora. Secuencia serializada; una favorable cierra la revisión y no permite agregar decisiones posteriores a esa misma obligación. |

Las cinco tablas nuevas tienen triggers PostgreSQL de inmutabilidad; no hay UPDATE de definiciones o decisiones. La secuencia de declaración y evaluación se valida también en SQL. FK compuestas impiden atribuir versión a otro motivo o resolución/adopción a otro laboratorio. El trigger de causa exige declaración realmente restrictiva y versión/justificación coincidentes, o versión inactiva y causa de desactivación coincidente.

El estado de una revisión se proyecta desde su última evaluación: sin evaluación o con non_favorable sigue pending; con favorable es resolved. Las evaluaciones anteriores permanecen consultables. Una nueva declaración restrictiva sucesora puede crear otra obligación, aunque la anterior ya esté resuelta.

La versión/estado de adopción administrativa no constituye aprobación clínica. No hay borrado, auto-adopción, sincronización masiva, copia sobre motivos locales ni modificación de la versión global adoptada. Los valores/fechas/actores históricos permanecen.

Rollback: las migraciones nuevas rechazan reversión con historia o asignaciones de la facultad nueva. Sin uso se revierten en orden inverso; las pruebas de migraciones anteriores retiran primero estas dependencias. La reversión del registro de facultad sin uso retira/reinstala su trigger dentro de transacción PostgreSQL. No se ejecutaron migraciones ni seeders sobre desarrollo/producción.

## Autoridad SaaS y permisos tenant

Nueva facultad: **saas.clinical_reasons.classify_impact**. Se registra su definición, sin concederla. Se otorga/revoca mediante A a una cuenta existente activa; bootstrap sigue concediendo exclusivamente access.manage. Access manager/publisher/owner/administrator no la heredan. Las tres rutas de impacto y su servicio exigen esa facultad exacta, sin laboratorio/cabecera/membresía tenant.

Nuevos permisos tenant:

| Permiso | Alcance |
| --- | --- |
| clinical_reasons.global.view | Catálogo global de sólo lectura, detalle e historia de versiones. |
| clinical_reasons.adoptions.view | Selecciones, actualizaciones, elegibilidad e historial de obligaciones. |
| clinical_reasons.adoptions.create | Selección inicial individual; además exige owner conforme a I.E. |
| clinical_reasons.adoptions.update | Adopción explícita de versión posterior; además exige owner. |
| clinical_reasons.adoptions.history | Historia de selecciones y de evaluaciones clínicas del catálogo. |
| clinical_reasons.review_impact | Evaluar/resolver una revisión; no concede administración global ni adopción. |

RbacCatalog integra seis permisos adicionales (90 en total). RolePermissionSeeder registra las definiciones y asigna al owner los cinco de administración/consulta de catálogo; **no review_impact**. Los otros roles conservan sus permisos previos; administrator no recibe automáticamente los nuevos. La revisión se concede explícitamente por laboratorio mediante el mecanismo Spatie existente de permisos directos, independientemente del rol. No se añade un sistema de concesiones alternativo ni una API de permisos de usuarios fuera de esta tarea.

Provisionamiento operativo futuro: ejecutar el seeder existente en el entorno autorizado para actualizar el catálogo RBAC; conceder review_impact explícitamente al usuario clínico seleccionado en el team correcto, usando el mecanismo existente. Para consultar la bandeja completa también necesita adoptions.view y, para historia de evaluaciones, adoptions.history. No se ejecutó ese procedimiento sobre datos reales ni se crearon cuentas demo nuevas.

ClinicalReasonTenantAuthority revalida en servicios: contexto Teams correcto, laboratorio/cuenta/membresía activos, suscripción iniciada/no vencida y permiso efectivo. Mantiene bloqueos compartidos sobre identidad/membresía/asignaciones y elimina relaciones/caché de permisos antes de autorizar. Exigir owner para adoptar no concede autoridad clínica; un owner con review_impact explícito sí puede evaluar conforme a D3. SaaS y tenant conservan fronteras independientes.

## Contratos administrativos SaaS

Prefijo `/api/v1/platform/clinical-reasons/{reason}/versions/{version}/impact`. IDs de razón/versión deben corresponder. Todas requieren classify_impact y sesión/Sanctum; sin X-Laboratory-ID.

| Método/sufijo | Contrato |
| --- | --- |
| GET / | data.classification y data.declaration; ausencia = unclassified/null, sin escritura. |
| GET /declarations | Historia por revision descendente, page opcional, 50 filas. |
| POST /declarations | expected_revision entero JSON >=0, classification en unclassified/non_restrictive/restrictive, justification no blanca hasta 2000. 201 con data, audit_id y operation_id. |

expected_revision=0 inicial; las correcciones usan la última revisión. El backend determina previous_id, versión origen, actor y hora. No se cambia la definición evaluada; no se admite actor/fecha/laboratorio en payload. Misma revisión concurrente: sólo una declaración confirma; la otra obtiene 409 clinical_reason_impact_revision_conflict.

Una restrictiva abre obligaciones en las adopciones existentes de esa identidad, independientemente de la versión seleccionada. Una corrección posterior no borra obligaciones ya abiertas. Al crear una selección se comprueban declaraciones **vigentes** por cada versión del motivo: las restrictivas generan obligaciones incluso al escoger una versión antigua o más nueva. Una declaración histórica corregida antes de la primera adopción no crea por sí sola una obligación que nunca existió; las obligaciones de adopciones anteriores permanecen referenciadas al original.

## Contratos tenant

Prefijo de consulta global: `/api/v1/laboratory/clinical-reasons/global`. Prefijo de selecciones: `/api/v1/laboratory/clinical-reason-adoptions`. Todas bajo grupo tenant existente saas, sesión/Sanctum, X-Laboratory-ID, membresía/suscripción activas y can:permiso exacto; el servicio vuelve a autorizar.

| Método/ruta | Permiso y resultado |
| --- | --- |
| GET global | global.view; identidades y latest_version. Incluye vigencia visible: consultar no acredita uso clínico. |
| GET global/{reason} | global.view; detalle. |
| GET global/{reason}/versions | global.view; historia global, más reciente primero. |
| GET adoptions | adoptions.view; sólo selecciones del laboratorio, con versión adoptada. |
| POST adoptions | adoptions.create + owner; reason_id/version_id enteros JSON y justificación; 201 con data.adoption/data.selection. |
| POST adoptions/{adoption}/versions | adoptions.update + owner; version_id, expected_revision >=1 y justificación; 201 con selección sucesora. |
| GET adoptions/{adoption}/versions | adoptions.history; historia inmutable de selecciones. |
| GET adoptions/{adoption}/updates | adoptions.view; todas las versiones posteriores paginadas, declaración vigente y diferencias de hechos respecto de la adoptada. Las diferencias no clasifican riesgo. |
| GET adoptions/{adoption}/eligibility | adoptions.view; estado de dependencias del motivo, no autorización de una operación posterior. |
| GET adoptions/{adoption}/reviews | adoptions.view; obligaciones pendientes/resueltas, causa y última evaluación. status permite distinguirlas sin perder historia. |
| POST adoptions/{adoption}/reviews/{review}/resolutions | review_impact; expected_revision >=0, outcome favorable/non_favorable, assessment obligatorio hasta 10000, justification obligatoria hasta 2000. 201 con evaluación y review_status. |
| GET adoptions/{adoption}/reviews/{review}/resolutions | adoptions.history; todas las evaluaciones de esa obligación. |

Lecturas paginadas a 50 con meta.current_page/last_page/per_page/total. IDs crecientes para catálogo/selecciones/obligaciones, revisiones descendentes para historia; actualizaciones globales crecientes para no ocultar transiciones intermedias. No hay endpoint de adopción masiva. Repetir la misma selección da conflicto, no duplicado silencioso.

Entradas de escritura rechazan claves desconocidas, IDs/revisiones que no sean enteros JSON, evaluación o justificación en blanco y outcomes no admitidos. Resolver exige revisión del laboratorio/adopción correctos, aún no favorable y revisión esperada exacta. Una evaluación no favorable sigue bloqueando; puede recibir otra evaluación justificada sucesora. Se conserva el texto de evaluación, no sólo una confirmación booleana.

Errores: 401 sin sesión; 400 cabecera tenant ausente/inválida; 403 cuenta/membresía/permiso/suscripción no efectivos o adopción sin owner; 404 recurso inexistente o ajeno al laboratorio; 409 duplicidad, versión no posterior, revisión obsoleta o revisión ya favorable; 422 payload inválido. Códigos: clinical_reason_already_adopted, clinical_reason_adoption_revision_conflict, clinical_reason_adoption_version_conflict, clinical_reason_review_revision_conflict. Protección CSRF existente, con eventual 419.

OpenAPI: GlobalClinicalReasonImpactDocumentation y ClinicalReasonAdoptionDocumentation documentan las 15 operaciones nuevas, schemas, errores y permisos exactos (x-saas-capability / x-tenant-permission). Inventario esperado: 165 operaciones OpenAPI, 173 rutas en el bootstrap de tests y 140 rutas con can: tenant.

## Política de elegibilidad y límites clínicos

ClinicalReasonEligibilityPolicy ofrece:

- eligibility(): consulta tenant autorizada con IDs de versiones sin clasificar y revisiones pendientes, can_request/can_approve y bloqueos específicos.
- assertAllowed(): verificación reutilizable para request/approve; exige el permiso clínico correspondiente y una transacción externa ya abierta. Mantiene el bloqueo de dependencias hasta que el consumidor confirme su escritura en esa misma transacción. Fuera de transacción falla, para evitar autorización y escritura separadas.

Se inspeccionan todas las versiones publicadas del motivo y la declaración vigente de cada una; falta de declaración o unclassified bloquea ambas operaciones dependientes según aprobación expresa del PO. No se interpreta como restrictiva ni crea revisión ficticia. Obligaciones sin evaluación favorable bloquean ambas. Una transición explícita de desactivación crea revisión por I.A; aun resuelta favorablemente, motivo global actual o versión adoptada inactivos impiden nuevas solicitudes. No se alteran resultados, procesamiento o motivos independientes.

can_request/can_approve sólo expresan las dependencias **del motivo**. No sustituyen permisos clínicos, evidencia mínima M01–M07, evaluación de reposición, validaciones actuales, prohibición de autoaprobación, resolución terminal o cierre. Consultar elegibilidad tampoco reserva una aprobación: el futuro comando debe llamar assertAllowed bajo su transacción.

La identidad clínica vinculada debe proceder de referencias estructuradas confiables, nunca del nombre/texto de reason ni de un adoption_id arbitrario enviado en una aprobación. El consumidor debe verificar que esa adopción/versión sea la dependencia original de la solicitud del mismo laboratorio.

## Auditoría y concurrencia

SaasAuditWriter::recordImpact genera saas.clinical_reasons.impact_declared y referencia global_impact_declaration_id, actor, facultad, causa, UUID y resultado. Se amplía la tabla existente con FK/índice único nullable, sin reescribir eventos previos. Las referencias global_reason_id/version_id existentes quedan null en este tipo de evento: la declaración inmutable referenciada identifica razón/transición y evita alterar los contratos/triggers previos de publicaciones B. La forma nueva y correspondencia de actor/causa/autorización se comprueban en SQL; un constraint trigger diferido impide confirmar una declaración sin auditoría.

`/platform/access/history` excluye eventos de catálogo e impacto: access.manage no abre una vía de consulta funcional. Historial de impacto requiere su facultad específica.

Adopción, actualización, obligación y evaluación usan audit_logs mediante AuditWriter/AuditEvent, con laboratorio real. Eventos clinical_reason.adopted, adoption_updated, review_required y review_evaluated: referencias allowlist de selección/versiones/resolución, actor, justificación y resultado. La evaluación completa se conserva en su fila inmutable, no se vuelca un request/model completo al log. No se registran pacientes, credenciales, cookies ni snapshots clínicos.

Una obligación causada por declaración global registra como actor causal al clasificador SaaS en el evento del laboratorio afectado; no lo convierte en miembro ni revisor clínico. La resolución sí exige actor miembro y permiso review_impact. Obligaciones derivadas de selección registran al selector. No se fabrican laboratorios o actores anónimos.

Todas las escrituras y evidencia obligatoria comparten transacción. La declaración restrictiva y su apertura de obligaciones/auditoría en laboratorios confirman juntas: fallo tenant revierte también la declaración global. Fallo de auditoría al seleccionar, actualizar o evaluar revierte el cambio. La auditoría funcional registra éxitos confirmados; no se añade una política de log de todos los rechazos.

Orden común: transacción → advisory lock SaaS `(182045,1)` → autorización efectiva → filas de selección/revisión. Se reutiliza el lock de A/B para ordenar publicación, clasificación, adopción y evaluaciones. La clasificación que confirma antes de una primera selección se detecta al seleccionar; si confirma después, abre su obligación. Resolver y publicar/clasificar concurrentemente no autoriza dependencias posteriores: un nuevo impacto genera otra obligación y una nueva versión sin clasificar bloquea hasta clasificación.

Coste: operaciones administrativas serializadas globalmente; una declaración abre obligaciones de todos los laboratorios afectados dentro de una transacción. Mantiene consistencia, pero el coste crece con adopciones. No se añade una cola que deje ventanas sin revisión; una optimización futura debe preservar el mismo cierre transaccional o una barrera equivalente. Nuevos consumidores deben respetar el orden de locks para evitar interbloqueos.

## Integración pendiente con BE-S2-08 — explícita

ClinicalResolutionService::requestException recibe reason libre y decideException no tiene referencia a motivo/adopción global. No se infiere por texto. **Los endpoints actuales de solicitud/aprobación BE08 todavía no consumen esta política.** Sus operaciones no pueden declararse protegidas por las nuevas revisiones. No hay una nueva ruta de solicitud que prometa protección mientras permita eludirla por una ruta anterior.

Cambio mínimo posterior propuesto, no implementado:

1. Vincular cada nueva solicitud estructurada a laboratory_id, identidad global, adopción y versión original mediante una referencia inmutable. Evaluar una tabla de dependencias aparte evita añadir columnas silenciosamente a filas completas que los snapshots actuales serializan con to_jsonb; requiere diseño/regresión propios.
2. En requestException, verificar relación/versión y llamar assertAllowed(request) dentro de la transacción que crea solicitud y dependencia.
3. En decideException al aprobar, cargar la dependencia **persistida** de esa solicitud y llamar assertAllowed(approve) antes de decisión/resolución. Rechazo, consultas e historia siguen su finalidad vigente.
4. Aplicarlo en todos los caminos reales de ejecución y servicios, con contratos prospectivos que no permitan omitir la dependencia para eludir el control. No tratar texto coincidente como identificación ni fabricar vinculaciones a solicitudes históricas.
5. Probar solicitudes/aprobaciones HTTP reales, carreras con publicación/restricción, autorización, snapshots e informes. Mantener controles BE08 de revisión clínica/resultados, autoaprobación, reposición y cierre. No se introduce aquí migración comercial ni un circuito legacy adicional.

No ejecutables como prueba de protección actual: bloqueo de POST real de solicitud BE08 por un impacto global y bloqueo de su aprobación real por revisión pendiente. Sí se prueban ambas operaciones contra la política estructurada y se ejecuta regresión del comportamiento BE08 existente; son evidencias distintas.

## Pruebas ejecutadas y clasificación de componentes

Entorno Compose `donqer-be-exc-023`, PostgreSQL 17 temporal en tmpfs, red interna sin puertos, aplicación/configuración de testing en lectura y TestDatabaseGuard. No se utilizó .env ni base de desarrollo/producción.

Pruebas nuevas: GlobalClinicalReasonImpactTest, ClinicalReasonAdoptionTest y ClinicalReasonAdoptionConcurrencyTest; fixture ClinicalReasonAdoptionScenario con ejemplos FICTICIO. Cobertura de facultades separadas, ausencia de inferencia, correcciones inmutables, auditoría diferida SQL, selección individual/actualización, aislamiento A/B/A, autoridad clínica explícita/revocada, obligaciones conservadas, deactivación, evaluación favorable/no favorable, elegibilidad de motivo independiente y rollback de cada escritura/fanout. Nueve carreras con procesos/conexiones independientes acreditan espera de PostgreSQL mediante pg_stat_activity.

Regresión dirigida en PostgreSQL real: **321 pruebas, 2.164 aserciones, sin fallos**. Incluye 36 casos nuevos, nueve de ellos de concurrencia. Directorios ejecutados: tests/Feature/ClinicalReasons, tests/Feature/SaasAccess, tests/Feature/Api/V1/Auth, tests/Feature/Authorization, tests/Feature/Saas y tests/Feature/ClinicalFinalization. Cubre 02.1, 02.2-A/B y regresión del comportamiento existente de BE08; no acredita integración clínica nueva allí.

Inventarios de contratos/rutas/OpenAPI: **191 pruebas, 1.877 aserciones, sin fallos**, ejecutadas en tests/Feature con el filtro `(contract|openapi|route)`. Verifican 165 operaciones OpenAPI, 173 rutas en el bootstrap de tests y 140 rutas tenant con can:. Pint y git diff --check correctos. No se ejecutó la suite completa. Las dos baterías pueden solaparse y no se suman como pruebas únicas.

| Componente | Clasificación al cierre |
| --- | --- |
| Declaraciones, facultad SaaS, historia y auditoría | IMPLEMENTADO Y PROBADO. |
| Selección/adopción individual, permisos y aislamiento | IMPLEMENTADO Y PROBADO. |
| Obligaciones/evaluaciones explícitas e historia | IMPLEMENTADO Y PROBADO. |
| Política estructurada request/approve | IMPLEMENTADO, PENDIENTE DE INTEGRACIÓN: probada como servicio, sin consumidor clínico BE08. |
| Protección clínica completa (política y consumidores reales) | IMPLEMENTADO, PENDIENTE DE INTEGRACIÓN: sólo el núcleo de política está implementado; falta aplicarlo en solicitudes/aprobaciones BE08. |
| Motivos locales, contratos compartidos definitivos, frontend y validación integral 02.6 | FUERA DE ALCANCE. |

No se detectaron decisiones clínicas adicionales pendientes dentro del alcance implementado; D1–D6 y las tres ratificaciones constan como aprobadas.

## Archivos y dependencias

Nuevos: dos migraciones; modelos GlobalClinicalReasonImpact, ClinicalReasonAdoption, ClinicalReasonAdoptionVersion, ClinicalReasonImpactReview y ClinicalReasonImpactResolution; ClinicalReasonTenantAuthority; GlobalClinicalReasonImpactManager, ClinicalReasonAdoptionManager, ClinicalReasonReviewStore y ClinicalReasonEligibilityPolicy; dos controladores, dos clases OpenAPI; tres clases de pruebas y su fixture; este informe.

Modificados: SaasAuditWriter/SaasAuditLog y separación de historial en SaasAccessManager; RbacCatalog; GlobalClinicalReasonManager (apertura de obligación en desactivación); routes/api.php; diagnóstico; pruebas de inventario/RBAC/autorización y rollback de migraciones anteriores para respetar nuevas dependencias. Las pruebas demo ya comparan administrator contra su matriz propia, que ahora difiere de owner. No se cambian permisos anteriores de otros roles.

BE-EXC-02.4: motivos personalizados conservan tablas locales, sin tomar selección global como clon local. BE-EXC-02.5: distinguir origen, versión adoptada, vigencia global y estado de revisión; no reducir a un booleano is_active histórico. El contrato compartido definitivo y la integración clínica requieren sus trabajos propios.

Sin cambios en DF-019, BE08, tablas clínicas existentes, informes, snapshots o frontend. Sin migraciones de desarrollo/producción, Trello, commits ni push. No se afirma finalización integral de BE-EXC-02.3 mientras falte la integración real de protección clínica.
