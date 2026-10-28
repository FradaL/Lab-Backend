# BE-EXC-02.5 — Contratos compartidos y lecturas definitivas

Fecha: 2026-10-25. Solicitud: adjunto `88847e69-b079-4f46-a505-94729ffad9af`. Backend inicial `8acf147`, producto `bdfe29d`, ambos limpios al iniciar. Fuente funcional: [DF-019 APROBADO 1.4](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), [DF-016 APROBADO 1.0](../../../donqer-lab-product/03-decisiones/DF-016-finalizacion-clinica.md), plan y AUD-002. No se cambia ninguna regla aprobada ni se implementa frontend o 02.6.

## Resultado y clasificación

**BE-EXC-02.5 IMPLEMENTADO Y PROBADO en el backend.** El consumidor obtiene origen, versión aplicable, referencia BE08 y bloqueos explícitos desde una lectura; no necesita reconstruir elegibilidad por nombres, clases, últimas versiones o combinación de historiales. La respuesta sigue siendo informativa y la escritura revalida.

| Componente | Clasificación | Alcance |
| --- | --- | --- |
| Selector unificado, filtros y paginación | IMPLEMENTADO Y PROBADO | Globales adoptados/locales por origen autorizado; referencia consumida en POST BE08 real. |
| Proyección de elegibilidad y permisos/acciones | IMPLEMENTADO Y PROBADO | Misma política, sin concesiones ni autoridad clínica implícita. |
| Pendientes con referencia original y revisiones | IMPLEMENTADO Y PROBADO | Bloqueos separados, filtro de revisiones por solicitud y conservación de historia/snapshots. |
| Capacidades tenant y propias SaaS | IMPLEMENTADO Y PROBADO | Se reutilizan auth/authorization y auth/me; códigos de la propia cuenta, sin registros ajenos. |
| OpenAPI y guía de consumo | IMPLEMENTADO Y PROBADO | Esquemas/operación/adiciones documentados y generación real verificada. |
| Integración backend interna | IMPLEMENTADO Y PROBADO | No queda una política nueva sin consumidor ni referencia que el frontend deba inferir. |
| Frontend, 02.6 y alcance clínico BE-EXC-03/04/05 | FUERA DE ALCANCE | Persisten verificación transversal, mediciones representativas, evidencia/viabilidad y representación clínica integral. |

No hay componentes internos de 02.5 clasificados **IMPLEMENTADO, PENDIENTE DE INTEGRACIÓN** ni **PENDIENTE DE DECISIÓN**. El frontend se mantiene explícitamente fuera de alcance; los contratos ya son consumibles sin reconstruir reglas clínicas.

## Diagnóstico inicial y decisiones

Se contrastaron informes 02.1, 02.2-A/B, 02.3, 02.3-I, 02.4 y BE08 con rutas, controladores, modelos, permisos, política, SQL y pruebas reales. Laravel está en laravel/, versión instalada 13.32.0. Los motivos son distintos del catálogo de exámenes DF-018. El estado de aprobación funcional se separa del de implementación.

La lectura local y las adopciones tienen formas distintas; ninguna entrega un selector conjunto. La elegibilidad antigua global refleja dependencias de adopción, mientras BE08 comprueba también clasificación terminal y referencia vigente. El frontend no debe combinar esos datos para recrear reglas. Historial BE08 ya devuelve el motivo original, pero carece de bloqueos actuales de aprobación. La lectura local de revisiones no filtra por solicitud. El endpoint tenant de autorización ya expone roles/permisos; los endpoints de accesos SaaS requieren access.manage y no son consulta propia de un operador sin esa facultad.

No se identificaron decisiones clínicas nuevas pendientes. Se mantienen clasificación explícita, revisión favorable/no favorable, autoridad independiente, versiones originales y límites de evidencia/viabilidad. No se traslada el impacto global a locales. Una revisión local depende de la solicitud original; no impide por sí sola crear otra solicitud con una versión local vigente/activa válida.

## Matriz de endpoints auditados

Prefijo y método completos; HEAD se omite por ser el GET equivalente. auth/Sanctum y cuenta activa en ámbitos protegidos; tenant añade X-Laboratory-ID, membresía y suscripción. Las administraciones locales/adopciones requieren además owner, sin aprobación clínica implícita. Estado referido al diagnóstico previo a la ampliación de 02.5. Versiones/evaluaciones son sucesoras inmutables; no hay edición destructiva.

| Endpoint | Alcance | Autorización | Servicio | Contrato request/response | Consumidor | Estado inicial |
| --- | --- | --- | --- | --- | --- | --- |
| GET /api/v1/auth/authorization | Laboratorio | Contexto tenant activo | RBAC Teams / User | data/meta, página 50 | Administración / capacidades | Reutilizable |
| GET /api/v1/auth/me | Cuenta propia | Sesión activa | UserResource | data de detalle / estado | Administración / capacidades | Requiere ampliación aditiva |
| POST /api/v1/laboratory-orders/{order}/exams/{line}/exceptions | Laboratorio | orders.clinical_exceptions.request | ClinicalResolutionService | clinical_revision, result_revision, reason_reference, justification → solicitud | Clínico | Reutilizable |
| GET /api/v1/laboratory-orders/{order}/exams/{line}/exceptions | Laboratorio | orders.clinical_exceptions.view | ClinicalResolutionService | data/meta, página 50 | Clínico | Requiere ampliación aditiva |
| POST /api/v1/laboratory-orders/{order}/exams/{line}/exceptions/{exception}/approve | Laboratorio | orders.clinical_exceptions.approve | ClinicalResolutionService | clinical_revision, request_revision, reason → decisión | Clínico | Reutilizable |
| POST /api/v1/laboratory-orders/{order}/exams/{line}/exceptions/{exception}/reject | Laboratorio | orders.clinical_exceptions.approve | ClinicalResolutionService | clinical_revision, request_revision, reason → decisión | Clínico | Reutilizable |
| GET /api/v1/laboratory/clinical-reason-adoptions | Laboratorio | clinical_reasons.adoptions.view | ClinicalReasonAdoptionManager | data/meta, página 50 | Administración / capacidades | Reutilizable |
| POST /api/v1/laboratory/clinical-reason-adoptions | Laboratorio | clinical_reasons.adoptions.create | ClinicalReasonAdoptionManager | Comando validado, actor servidor, versión/auditoría | Administración / capacidades | Reutilizable |
| GET /api/v1/laboratory/clinical-reason-adoptions/{adoption}/eligibility | Laboratorio | clinical_reasons.adoptions.view | ClinicalReasonEligibilityPolicy | data de detalle / estado | Clínico | Requiere ampliación aditiva |
| GET /api/v1/laboratory/clinical-reason-adoptions/{adoption}/reviews | Laboratorio | clinical_reasons.adoptions.view | ClinicalReasonAdoptionManager | data/meta, página 50 | Administración / capacidades | Reutilizable |
| POST /api/v1/laboratory/clinical-reason-adoptions/{adoption}/reviews/{review}/resolutions | Laboratorio | clinical_reasons.review_impact | ClinicalReasonAdoptionManager | expected_revision, outcome, assessment, justification → evaluación | Administración / capacidades | Reutilizable |
| GET /api/v1/laboratory/clinical-reason-adoptions/{adoption}/reviews/{review}/resolutions | Laboratorio | clinical_reasons.adoptions.history | ClinicalReasonAdoptionManager | data/meta, página 50 | Administración / capacidades | Reutilizable |
| GET /api/v1/laboratory/clinical-reason-adoptions/{adoption}/updates | Laboratorio | clinical_reasons.adoptions.view | ClinicalReasonAdoptionManager | data/meta, página 50 | Administración / capacidades | Reutilizable |
| POST /api/v1/laboratory/clinical-reason-adoptions/{adoption}/versions | Laboratorio | clinical_reasons.adoptions.update | ClinicalReasonAdoptionManager | expected_number/expected_revision y definición/version_id → sucesora | Administración / capacidades | Reutilizable |
| GET /api/v1/laboratory/clinical-reason-adoptions/{adoption}/versions | Laboratorio | clinical_reasons.adoptions.history | ClinicalReasonAdoptionManager | data/meta, página 50 | Administración / capacidades | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/global | Laboratorio | clinical_reasons.global.view | ClinicalReasonAdoptionManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/global/{reason} | Laboratorio | clinical_reasons.global.view | ClinicalReasonAdoptionManager | data de detalle / estado | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/global/{reason}/versions | Laboratorio | clinical_reasons.global.view | ClinicalReasonAdoptionManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/local | Laboratorio | clinical_reasons.local.view | LocalClinicalReasonManager | data/meta, página 50 | Administración | Reutilizable |
| POST /api/v1/laboratory/clinical-reasons/local | Laboratorio | clinical_reasons.local.create | LocalClinicalReasonManager | Comando validado, actor servidor, versión/auditoría | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/local/{reason} | Laboratorio | clinical_reasons.local.view | LocalClinicalReasonManager | data de detalle / estado | Administración | Reutilizable |
| POST /api/v1/laboratory/clinical-reasons/local/{reason}/activation | Laboratorio | clinical_reasons.local.manage | LocalClinicalReasonManager | expected_number, is_active, change_reason → versión/auditoría | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/local/{reason}/events | Laboratorio | clinical_reasons.local.history | LocalClinicalReasonManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/local/{reason}/reviews | Laboratorio | clinical_reasons.local.view | LocalClinicalReasonManager | data/meta, página 50 | Administración | Requiere ampliación aditiva |
| POST /api/v1/laboratory/clinical-reasons/local/{reason}/reviews/{review}/resolutions | Laboratorio | clinical_reasons.review_impact | LocalClinicalReasonManager | expected_revision, outcome, assessment, justification → evaluación | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/local/{reason}/reviews/{review}/resolutions | Laboratorio | clinical_reasons.local.history | LocalClinicalReasonManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/laboratory/clinical-reasons/local/{reason}/versions | Laboratorio | clinical_reasons.local.history | LocalClinicalReasonManager | data/meta, página 50 | Administración | Reutilizable |
| POST /api/v1/laboratory/clinical-reasons/local/{reason}/versions | Laboratorio | clinical_reasons.local.publish | LocalClinicalReasonManager | expected_number/expected_revision y definición/version_id → sucesora | Administración | Reutilizable |
| GET /api/v1/platform/access/capabilities | SaaS | saas.access.manage | SaasAccessManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/platform/access/history | SaaS | saas.access.manage | SaasAccessManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/platform/access/users | SaaS | saas.access.manage | SaasAccessManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/platform/access/users/{user}/capabilities | SaaS | saas.access.manage | SaasAccessManager | data/meta, página 50 | Administración | Reutilizable |
| POST /api/v1/platform/access/users/{user}/capabilities | SaaS | saas.access.manage | SaasAccessManager | Comando validado, actor servidor, versión/auditoría | Administración | Reutilizable |
| POST /api/v1/platform/access/users/{user}/capabilities/{assignment}/revoke | SaaS | saas.access.manage | SaasAccessManager | Comando validado, actor servidor, versión/auditoría | Administración | Reutilizable |
| GET /api/v1/platform/clinical-reasons | SaaS | saas.clinical_reasons.view | GlobalClinicalReasonManager | data/meta, página 50 | Administración | Reutilizable |
| POST /api/v1/platform/clinical-reasons | SaaS | saas.clinical_reasons.create | GlobalClinicalReasonManager | Comando validado, actor servidor, versión/auditoría | Administración | Reutilizable |
| GET /api/v1/platform/clinical-reasons/{reason} | SaaS | saas.clinical_reasons.view | GlobalClinicalReasonManager | data de detalle / estado | Administración | Reutilizable |
| POST /api/v1/platform/clinical-reasons/{reason}/activation | SaaS | saas.clinical_reasons.manage | GlobalClinicalReasonManager | expected_number, is_active, change_reason → versión/auditoría | Administración | Reutilizable |
| GET /api/v1/platform/clinical-reasons/{reason}/events | SaaS | saas.clinical_reasons.view | GlobalClinicalReasonManager | data/meta, página 50 | Administración | Reutilizable |
| GET /api/v1/platform/clinical-reasons/{reason}/versions | SaaS | saas.clinical_reasons.view | GlobalClinicalReasonManager | data/meta, página 50 | Administración | Reutilizable |
| POST /api/v1/platform/clinical-reasons/{reason}/versions | SaaS | saas.clinical_reasons.publish | GlobalClinicalReasonManager | expected_number/expected_revision y definición/version_id → sucesora | Administración | Reutilizable |
| GET /api/v1/platform/clinical-reasons/{reason}/versions/{version}/impact | SaaS | saas.clinical_reasons.classify_impact | GlobalClinicalReasonImpactManager | data de detalle / estado | Administración | Reutilizable |
| GET /api/v1/platform/clinical-reasons/{reason}/versions/{version}/impact/declarations | SaaS | saas.clinical_reasons.classify_impact | GlobalClinicalReasonImpactManager | data/meta, página 50 | Administración | Reutilizable |
| POST /api/v1/platform/clinical-reasons/{reason}/versions/{version}/impact/declarations | SaaS | saas.clinical_reasons.classify_impact | GlobalClinicalReasonImpactManager | Comando validado, actor servidor, versión/auditoría | Administración | Reutilizable |

## Contratos implementados y compatibilidad

| Contrato | Cambio | Compatibilidad |
| --- | --- | --- |
| GET /api/v1/laboratory/clinical-reasons | NUEVO: selector unificado paginado y filtrado con referencia BE08, estado y acciones. | No existe una lectura anterior que unifique ambos orígenes. Se conserva cada API administrativa existente. |
| GET /api/v1/laboratory/clinical-reason-adoptions/{adoption}/eligibility | AMPLIADO: selection.reason_reference y selection.eligibility. | Los campos antiguos conservan su semántica de dependencias de adopción; can_request antiguo no incluía clase terminal ni permiso clínico. El selector nuevo sí incluye la clase. No usar el booleano antiguo aislado para seleccionar. |
| GET /api/v1/laboratory-orders/{order}/exams/{line}/exceptions | AMPLIADO: reason_eligibility separado por pendiente estructurada, con bloqueos sobre su referencia original. | Solicitud, texto, justificación, referencia, decisión y paginación conservados. Nuevos metadatos no se guardan en filas clínicas ni snapshots. Para decisiones ya tomadas y cohortes anteriores sin referencia: null, sin asociaciones inferidas o reevaluación retroactiva. |
| GET /api/v1/laboratory/clinical-reasons/local/{reason}/reviews | AMPLIADO: filtro opcional exception_request_id. | Sin filtro conserva la consulta anterior. El filtro exige referencia del mismo laboratorio y motivo; 404 si no corresponde. |
| GET /api/v1/auth/me | AMPLIADO: data.saas_capabilities. | data.user conserva identidad pública. Sólo códigos vigentes de la propia cuenta, incluso sin laboratorio ni access.manage; no concesiones, actores, motivos, fechas de grants u otras cuentas. Login no cambia. |
| GET /api/v1/auth/authorization | REUTILIZADO sin cambios. | Roles/permisos efectivos del laboratorio. No mezcla autoridad SaaS con tenant. |
| Historia/versiones, actualizaciones, revisiones/evaluaciones y administración | REUTILIZADOS con sus permisos existentes. | No se añaden alternativas de escritura ni se cambia el modelo inmutable. |

Las ampliaciones de JSON son aditivas. Se actualiza la expectativa exacta de la prueba de /auth/me para incluir la lista propia vacía; no se elimina la comprobación de identidad. Clientes con validación cerrada de respuestas deben admitir este campo y los metadatos aditivos de elegibilidad antes de adoptarlos. Se recomienda migrar la selección al contrato unificado o al campo selection de la elegibilidad global, manteniendo las lecturas administrativas para administrar. No se retira ni se cambia silenciosamente la semántica del booleano antiguo.

## Contrato del catálogo unificado

Requiere sesión/Sanctum, X-Laboratory-ID, laboratorio/usuario/membresía activos, suscripción vigente y **clinical_reasons.adoptions.view O clinical_reasons.local.view**. Se reutiliza PermissionMiddleware de Spatie y la misma autoridad tenant con comprobación fresca de cualquiera de esos permisos. No se crean permisos, roles, concesiones o un sistema de autorización paralelo: continúan 95 permisos y la plantilla owner no recibe solicitud, revisión o aprobación clínica.

Cada origen se consulta sólo con su permiso específico. Sin source, meta.visible_sources identifica los orígenes autorizados; no se combina con datos de orígenes ocultos. Pedir explícitamente un origen sin su permiso devuelve 403. Conocer un ID o enviar laboratory_id en un filtro no concede acceso. El usuario que sólo tiene request tampoco recibe implícitamente permisos de lectura administrativa. El owner puede leer/administrar sin obtener autorización clínica.

Sólo entran motivos globales **adoptados explícitamente por el laboratorio** y locales del mismo laboratorio con una versión existente. Un motivo global disponible pero no adoptado se consulta por el catálogo administrativo global, no aparece seleccionable aquí. Las identidades de bajo nivel aún sin versión permanecen en sus lecturas administrativas: no se inventa un estado draft ni una definición clínica para seleccionarlas.

| Parámetro | Valores y significado |
| --- | --- |
| search | Subcadena literal del nombre de la versión aplicable, máximo 200 caracteres, sin distinguir mayúsculas; vacía o nula omite el filtro, conforme a las búsquedas existentes. % y _ se escapan como texto, con valores vinculados SQL. No clasificación inferida por nombre. |
| source | global o local. Global significa adoptado en el laboratorio seleccionado. |
| classification | incidence_only o terminal_candidate; no se confunde con declaración de impacto global. |
| active | Cadenas true/false; activación de la versión aplicable. Una versión global aplicable activa puede estar bloqueada por desactivación global posterior. |
| eligible | Cadenas true/false; elegibilidad de catálogo para solicitud nueva, calculada por la política. No incluye permiso clínico ni estado de una orden/línea. |
| page | Entero positivo; tamaño fijo 50. Filtros aplicados antes del conteo/paginación y orden estable source/reason_id. |

Otros parámetros se rechazan con 422. No se ofrecen ordenaciones SQL arbitrarias ni filtros de tenant. Todas las versiones de motivos son publicadas por su creación/sucesora inmutable; publication_status=published describe ese registro existente, sin crear un ciclo de borradores/aprobación distinto.

### Esquema y ejemplo de respuesta

key combina source y reason_id; el contexto de laboratorio está en meta.laboratory_id. Los ID globales y locales pueden tener el mismo número sin compartir identidad. reason_reference es el objeto íntegro que BE08 requiere; no se deriva del nombre, código, posición de lista o latest_version.

Ejemplo **FICTICIO**, IDs ilustrativos, usuario con permiso explícito de solicitud y lectura local:

```json
{
  "data": [{
    "key": "local:10",
    "source": "local",
    "reason_id": 10,
    "code": "ficticio",
    "reason_reference": {"source": "local", "reason_id": 10, "version_id": 20},
    "publication_status": "published",
    "version": {
      "id": 20, "number": 1,
      "name": "FICTICIO: motivo clínico",
      "description": "FICTICIO: definición local",
      "classification": "terminal_candidate",
      "evidence_requirements": {"schema_version": 1, "requirements": ["synthetic_evaluation"]},
      "is_active": true, "created_at": "2026-10-25T12:00:00.000000Z"
    },
    "latest_version": {"id": 20, "number": 1, "is_active": true},
    "eligibility": {
      "can_select_for_request": true,
      "request_blockers": [], "pending_review_count": 0, "non_favorable_review_count": 0,
      "scope": "catalog", "informational": true
    },
    "actions": {
      "can_select_for_request": true,
      "can_publish_local": false, "can_manage_local_activation": false,
      "can_update_adoption": false, "can_view_history": false, "can_review_pending": false
    }
  }],
  "meta": {
    "laboratory_id": 1, "current_page": 1, "last_page": 1, "per_page": 50, "total": 1,
    "visible_sources": ["local"], "eligibility_scope": "catalog", "informational": true
  }
}
```

Un global incluye además adoption_id/adoption_version_id en reason_reference. version es la versión aplicable adoptada; latest_version informa ID/número/activación de la más reciente y nunca reemplaza la selección. Local no contiene campos de adopción. La lista no expone actores SaaS, auditoría de proveedor ni evaluaciones/grants ajenos. Requisitos de evidencia son metadata estructural existente; no se certifica suficiencia clínica por JSON válido.

## Elegibilidad única y bloqueos

Se amplía **ClinicalReasonEligibilityPolicy**; no se crea otra política. Sus proyecciones SQL por origen alimentan el selector, sus filtros y la evaluación utilizada por los comandos. El evaluador global conserva los booleanos/códigos anteriores y añade selection. El chequeo terminal antes situado en ClinicalExceptionReasonGuard pasa a un método de esta misma política, usado por el guard clínico. Las aprobaciones locales y sus lecturas comparten el cálculo de obligaciones no favorables o ausentes; el cambio de implementación no añade causas de revisión.

| Código informativo estable | Significado |
| --- | --- |
| impact_unclassified | Hay una versión global de la identidad cuyo impacto vigente no está clasificado. No aplica a locales. |
| clinical_review_pending | Revisión global pendiente para solicitud nueva, o revisión aplicable a la solicitud original al aprobar. Incluye obligación local que no podría omitirse por falta de fila. |
| clinical_review_non_favorable | La última evaluación aplicable es no favorable; además de pendiente, explica el bloqueo. No crea una nueva regla: ya bloqueaba la política. |
| reason_inactive | Versión aplicable inactiva o, para globales, versión global más reciente inactiva. Impide solicitudes nuevas, no cancela historia automáticamente. |
| clinical_reason_not_terminal | La versión aplicable es incidence_only. Para aprobación se usa la clasificación de la versión original. |

El catálogo siempre utiliza la selección vigente: una adopción antigua no se sustituye por la última global, y no aparece un global no adoptado. Una referencia ya leída puede quedar obsoleta al cambiar adopción/versión: BE08 mantiene 409 clinical_reason_selection_conflict. No se inventa un código de restricción por adopción diferente al del contrato vigente.

eligibility.can_select_for_request sólo refleja catálogo; actions.can_select_for_request añade el permiso efectivo orders.clinical_exceptions.request. No autoriza una línea, comprueba viabilidad o equivale a aprobación. actions de publicación/activación/adopción combinan permiso y owner; actualizar adopción requiere además una versión posterior disponible. El frontend presenta estos booleanos sin reinterpretar clases o contar revisiones para decidir.

Global: falta de clasificación, revisión pendiente/no favorable o inactivación bloquean nuevas solicitudes según política. Local: versión vigente activa y terminal; las obligaciones de solicitudes anteriores se muestran como resumen, pero no bloquean otra solicitud nueva válida. No se aplican declaraciones de impacto global a locales. Favorable sólo levanta su obligación; no aprueba ni genera una solicitud/resolución.

En historial BE08, reason_eligibility.can_approve_from_catalog sólo describe el catálogo de la referencia original para una pendiente estructurada, con approval_blockers, conteos y review_context. Desactivación/reclasificación locales conservan todas las obligaciones, incluso cambios revertidos. La aprobación sigue requiriendo permisos, otro usuario, revisiones exactas, línea/orden abiertas y sin obsolescencia/validación histórica, conforme a BE08. Una decisión ya registrada no se reevalúa retroactivamente en la lectura; reason_eligibility=null conserva ese límite.

## Versiones, historia y privacidad

Se reutilizan las consultas paginadas de versiones locales/globales, sucesoras de adopción, diferencias, eventos administrativos tenant, revisiones y evaluaciones con sus fechas, actores y justificaciones. Las facultades de lectura de historia y administración SaaS conservan sus permisos exactos. No se exponen registros de auditoría o asignaciones SaaS en el catálogo tenant.

El historial clínico continúa devolviendo reason_reference.definition y justification originales mediante FK a versiones inmutables; reason_eligibility es un campo separado y actual. No se reescriben solicitudes, decisiones, resoluciones ni tablas/migraciones de informes. El conjunto previo sellado mantiene texto y referencia null, sin asociación por nombre. No se añaden columnas a filas serializadas con to_jsonb ni se alteran snapshots/PDF.

El filtro local exception_request_id verifica primero que exista una referencia local para ese laboratorio/motivo/solicitud; no basta conocer un ID. Para globales se reutiliza la consulta de revisiones de la adopción que figura en review_context. Cada lista de evaluaciones mantiene su endpoint paginado, revisión esperada y permisos separados de approve.

## Aislamiento y rendimiento

ClinicalReasonTenantAuthority revalida cuenta, Teams, membresía, suscripción y permisos bajo el lock transaccional compartido ya existente. Las dos ramas SQL parten del laboratorio del contexto autenticado. La proyección no acepta identificadores de tenant, nombres de tablas, expresiones SQL ni direcciones de orden enviados por el cliente. Las consultas usan bindings para filtros. Los nombres de tablas/condiciones del agregador son constantes internas.

La unión SQL, filtros, conteo y LIMIT/OFFSET ocurren antes de serializar; sólo se devuelven hasta 50 registros con la versión aplicable y metadata de última versión, sin hidratar el catálogo o historial completos. Revisiones se agregan con su última evaluación; las pendientes del historial se resuelven por lote de referencias de la página, no por consulta por fila. La conversión a colección base conserva las claves de solicitud al seleccionar referencias; una colección Eloquent indexa only por ID de modelo y no debe usarse para ese cruce.

Se aprovechan índices únicos existentes por reason/number, adoption/revision y review/revision, además de identidades/FK tenant. La prueba con 55 motivos (dos páginas, 50/5) exige menos de 35 consultas totales incluyendo autenticación/permisos y respuesta, detectando N+1. No se introducen índices o migraciones especulativos ni caché de elegibilidad. El servidor aún debe verificar historia relevante para clasificar y contar obligaciones; el costo del filtro eligible depende de los motivos/historia del tenant. 02.6 conserva mediciones con volúmenes representativos y seguimiento del lock global compartido que serializa temporalmente lecturas/escrituras. No se declara rendimiento de producción a partir del fixture.

El catálogo unificado, la elegibilidad de adopción, el historial BE08 y las capacidades propias devuelven Cache-Control private, no-store; no reservan autorización. Los comandos BE08 mantienen el mismo lock antes de filas clínicas y comprueban de nuevo permisos, catálogo y revisiones dentro de su transacción. Una respuesta previa elegible no sirve como token, reserva o bandera para omitir esos controles.

## Guía de integración frontend

### Contexto y capacidades

Los sufijos de API de esta guía llevan el prefijo /api/v1, salvo que ya aparezca completo.

Usar sesión/Sanctum y X-Laboratory-ID en operaciones tenant; descartar selecciones y disponibilidad previas al cambiar de laboratorio. GET /api/v1/auth/authorization entrega roles/permisos efectivos; reutilizarlo para navegación y acciones administrativas generales. GET /api/v1/auth/me entrega identidad y saas_capabilities **de la propia cuenta**; el operador de proveedor puede conocer sus códigos sin access.manage ni laboratorio. No deducir capacidades SaaS por rol owner ni usar el inventario de facultades /platform/access/capabilities como concesiones efectivas. El backend revalida toda operación.

### Administración local

GET /laboratory/clinical-reasons/local y /{reason}, /versions y /events siguen siendo las lecturas administrativas. POST local crea identidad/versión; POST /{reason}/versions publica definición completa con expected_number. Activación usa POST /{reason}/activation con expected_number, is_active y change_reason. Las nuevas versiones no sustituyen las usadas en solicitudes. Usar permisos efectivos y acciones por motivo; no conceder request/review/approve por administrar.

### Adopción global

GET /laboratory/clinical-reasons/global permite consultar el catálogo para seleccionar una adopción administrativa; no es un selector clínico. GET /{reason}/versions conserva versiones disponibles. POST /laboratory/clinical-reason-adoptions requiere reason_id, version_id y justification; una actualización usa POST /{adoption}/versions con expected_revision y versión posterior explícita. GET /{adoption}/updates entrega diferencias y declaraciones de impacto. No copiar motivos globales a locales ni asumir adopción automática. La lectura unificada entrega después la referencia adoptada exacta.

### Selección al solicitar

1. GET /api/v1/laboratory/clinical-reasons?eligible=true, opcionalmente search/source. Paginar con meta; los orígenes sin permiso no se mezclan. No elegir versiones por nombre ni utilizar latest_version como referencia.
2. Mostrar version y evidencia definida. Usar actions.can_select_for_request para la disponibilidad de selección del actor; la política ya decidió clasificación y bloqueos. No convertir conteos en reglas.
3. Obtener revisiones de orden/línea por las lecturas BE08 existentes. Copiar reason_reference íntegro en el POST de solicitud y añadir justification clínica separada, clinical_revision y result_revision. El laboratorio/actor se derivan del servidor.
4. Tratar 409 como conflicto vigente: recargar motivo/selección/revisiones/contexto pertinente. No cambiar IDs manualmente ni reutilizar un true anterior para forzar la escritura. Solicitar no aprueba ni finaliza automáticamente.

Ejemplo **FICTICIO**, IDs ilustrativos:

```json
{
  "clinical_revision": 3,
  "result_revision": 0,
  "reason_reference": {"source": "global", "reason_id": 10, "version_id": 20, "adoption_id": 30, "adoption_version_id": 40},
  "justification": "FICTICIO: justificación clínica separada"
}
```

El ejemplo local omite adoption_id/adoption_version_id y usa directamente el objeto local entregado por el catálogo. BE08 rechaza campos de adopción para locales y cualquier sustitución de referencia al aprobar.

### Pendientes y revisión clínica

GET /api/v1/laboratory-orders/{order}/exams/{line}/exceptions devuelve estado, motivo/versión/justificación originales y reason_eligibility por pendiente estructurada. Mostrar approval_blockers y conteos sin reemplazar la definición original por la versión vigente. review_context identifica explícitamente la adopción global o reason_id/exception_request_id locales.

Global: GET /api/v1/laboratory/clinical-reason-adoptions/{adoption}/reviews. Local: GET /api/v1/laboratory/clinical-reasons/local/{reason}/reviews?exception_request_id={request}. Ambas consultas paginan las obligaciones y última evaluación. Sus /{review}/resolutions muestran el historial paginado y reciben POST con expected_revision, assessment, justification y outcome favorable/non_favorable, sólo con clinical_reasons.review_impact. Tras evaluar, recargar la pendiente. Favorable no crea una decisión: aprobar sigue siendo otro POST BE08 con revisiones vigentes, permiso y aprobador independiente.

### Errores y alcance

401 requiere sesión; 400 falta de contexto donde corresponda; 403 permiso/estado SaaS o tenant insuficiente; 404 recurso fuera del ámbito; 422 filtros/cuerpos inválidos. La lectura unificada no devuelve 409 por un motivo bloqueado: lo devuelve como dato con códigos. BE08 conserva 409 clinical_reason_selection_conflict, clinical_reason_use_blocked, clinical_reason_not_terminal y conflictos de revisiones/obsolescencia. Los mínimos clínicos/viabilidad y representación No realizado siguen en BE-EXC-03/04/05; no deducir suficiencia por una etiqueta terminal_candidate.

## OpenAPI y comprobaciones

Se documenta la operación nueva, todos los filtros, paginación, referencia oneOf global/local, campos aditivos en elegibilidad/historia/auth/me y filtro local de revisiones. Se corrige la descripción antigua de adopción que todavía afirmaba que BE08 consumía texto libre. La compatibilidad de esquemas y operaciones se verifica con generación real l5-swagger y contratos/rutas. Inventario: 176 operaciones, 184 rutas de bootstrap, 150 rutas con can: más la nueva ruta con OR de los permisos existentes mediante Spatie.

## Verificación ejecutada

Pruebas sobre PostgreSQL 17 temporal exclusivo, PHP 8.4.25, Laravel 13.32.0, PHPUnit 12.5.35, configuración testing y código en lectura; TestDatabaseGuard verifica identidad del servidor. Proyectos separados donqer-be-exc-025 y donqer-be-exc-025-contracts, redes internas sin puertos ni datos de desarrollo/producción.

| Batería ejecutada | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| Motivos, finalización BE08 e informes | 320 | 2.052 | Sin fallos, errores u omisiones |
| Autenticación, tenancy, permisos y SaaS | 151 | 954 | Sin fallos, errores u omisiones |
| Contratos, rutas y OpenAPI | 195 | 1.921 | Sin fallos, errores u omisiones |
| Lecturas finales 02.5 | 25 | 142 | Sin fallos, errores u omisiones |

**No se ejecutó la suite completa del repositorio.** Las baterías se solapan y no se suman como casos únicos. Los resultados históricos de 02.1–02.4 no se atribuyen a esta ejecución. Los 25 casos de lectura están incluidos en la regresión clínica; la batería final volvió a comprobar el último ajuste de búsqueda vacía y la disponibilidad de nuevas versiones. Las pruebas dirigidas reutilizan las carreras existentes de adopción, solicitud/aprobación global y administración/uso local; no se creó una batería duplicada de procesos concurrentes.

Los casos nuevos prueban ambos orígenes y consumo real de reason_reference, búsqueda literal/vacía e inyección, filtros y paginación, permisos por origen, acceso ajeno, cuentas sin permisos, capacidades propias/revocadas, original global tras cambio de adopción, original local tras reclasificación/reversión, evaluaciones no favorables/favorables, ausencia de autoaprobación, falta de clasificación global y lectura elegible seguida de desactivación antes de escribir. El fixture de 55 motivos devuelve páginas disjuntas de 50 y 5 y satisface el presupuesto de consultas; no se presenta como benchmark de producción.

La primera batería focalizada detectó dos ajustes de fixture (restablecer Teams tras HTTP y evitar un nombre de esquema OpenAPI ya existente) y un defecto de asociación por claves de colección al añadir reason_eligibility. Se corrigieron y verificaron las lecturas y sus escrituras dependientes. La matriz nueva no cambia los cuerpos de solicitud/aprobación ni las restricciones clínicas; todas las pruebas de regresión clínica pasaron.

Logs locales: /tmp/be-exc-025-clinical.log, /tmp/be-exc-025-security.log, /tmp/be-exc-025-contracts.log y /tmp/be-exc-025-read-final-verified.log. Un caso focal de revocación pasó con 5 aserciones antes de la comprobación final de lecturas, sin sumarlo como caso único adicional.

Comandos desde la raíz backend, sólo sobre las bases testing temporales:

```bash
docker compose -p donqer-be-exc-025 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalReasons tests/Feature/ClinicalFinalization tests/Feature/ClinicalReports
docker compose -p donqer-be-exc-025-contracts -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/Api/V1/Auth tests/Feature/Authorization tests/Feature/SaasAccess tests/Feature/Saas
docker compose -p donqer-be-exc-025-contracts -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature --filter 'contract|openapi|route'
docker compose -p donqer-be-exc-025-contracts -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalReasons/ClinicalReasonReadTest.php
```

No ejecutar baterías simultáneas sobre una misma base. Los proyectos separados tienen redes y PostgreSQL propios. Ambos entornos fueron retirados al finalizar, sin tocar otros proyectos.


## Dependencias y clasificación final

La lectura compartida no cambia reglas de aprobación clínica, no resuelve evidencia/viabilidad, no altera historia y no implementa frontend. 02.6 conserva validación transversal y mediciones representativas; BE-EXC-03/04/05 mantienen sus brechas clínicas. No se aplicaron migraciones/seeders a desarrollo/producción; no se modificó Trello, sin commits ni push. Las clasificaciones y resultados anteriores se limitan al backend y al alcance realmente comprobado. Sin nuevas decisiones clínicas ni componentes internos de 02.5 pendientes de integración.

## Archivos creados y modificados

Comparación contra los HEAD iniciales de ambos repositorios, sin commits nuevos. Se reutilizan servicios/modelos/migraciones anteriores; no se crean tablas, columnas, modelos o concesiones. Los ajustes de inventario de pruebas son aditivos (176 operaciones y 184 rutas), sin reducir controles de dominio.

- [backend-lab/README.md](../../README.md)
- [backend-lab/laravel/app/Authorization/ClinicalReasonTenantAuthority.php](../../laravel/app/Authorization/ClinicalReasonTenantAuthority.php)
- [backend-lab/laravel/app/Authorization/SaasAccessAuthority.php](../../laravel/app/Authorization/SaasAccessAuthority.php)
- [backend-lab/laravel/app/ClinicalExams/ClinicalReasonEligibilityPolicy.php](../../laravel/app/ClinicalExams/ClinicalReasonEligibilityPolicy.php)
- [backend-lab/laravel/app/ClinicalExams/ClinicalReasonReadService.php](../../laravel/app/ClinicalExams/ClinicalReasonReadService.php)
- [backend-lab/laravel/app/ClinicalExams/LocalClinicalReasonManager.php](../../laravel/app/ClinicalExams/LocalClinicalReasonManager.php)
- [backend-lab/laravel/app/ClinicalFinalization/ClinicalExceptionReasonGuard.php](../../laravel/app/ClinicalFinalization/ClinicalExceptionReasonGuard.php)
- [backend-lab/laravel/app/ClinicalFinalization/ClinicalResolutionService.php](../../laravel/app/ClinicalFinalization/ClinicalResolutionService.php)
- [backend-lab/laravel/app/Http/Controllers/Api/V1/Auth/AuthController.php](../../laravel/app/Http/Controllers/Api/V1/Auth/AuthController.php)
- [backend-lab/laravel/app/Http/Controllers/Api/V1/ClinicalFinalizationController.php](../../laravel/app/Http/Controllers/Api/V1/ClinicalFinalizationController.php)
- [backend-lab/laravel/app/Http/Controllers/Api/V1/ClinicalReasonAdoptionController.php](../../laravel/app/Http/Controllers/Api/V1/ClinicalReasonAdoptionController.php)
- [backend-lab/laravel/app/Http/Controllers/Api/V1/ClinicalReasonReadController.php](../../laravel/app/Http/Controllers/Api/V1/ClinicalReasonReadController.php)
- [backend-lab/laravel/app/Http/Controllers/Api/V1/LocalClinicalReasonController.php](../../laravel/app/Http/Controllers/Api/V1/LocalClinicalReasonController.php)
- [backend-lab/laravel/app/OpenApi/ApiDocumentation.php](../../laravel/app/OpenApi/ApiDocumentation.php)
- [backend-lab/laravel/app/OpenApi/ClinicalFinalizationDocumentation.php](../../laravel/app/OpenApi/ClinicalFinalizationDocumentation.php)
- [backend-lab/laravel/app/OpenApi/ClinicalReasonAdoptionDocumentation.php](../../laravel/app/OpenApi/ClinicalReasonAdoptionDocumentation.php)
- [backend-lab/laravel/app/OpenApi/ClinicalReasonReadDocumentation.php](../../laravel/app/OpenApi/ClinicalReasonReadDocumentation.php)
- [backend-lab/laravel/app/OpenApi/LocalClinicalReasonDocumentation.php](../../laravel/app/OpenApi/LocalClinicalReasonDocumentation.php)
- [backend-lab/laravel/routes/api.php](../../laravel/routes/api.php)
- [backend-lab/laravel/tests/Feature/Api/V1/Auth/AuthenticationTest.php](../../laravel/tests/Feature/Api/V1/Auth/AuthenticationTest.php)
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
- [backend-lab/laravel/tests/Feature/ClinicalReasons/ClinicalReasonReadTest.php](../../laravel/tests/Feature/ClinicalReasons/ClinicalReasonReadTest.php)
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

Sintaxis PHP verificada en 32 archivos, cero errores. Pint --dirty --format agent final satisfactorio. Los modelos de solicitudes/decisiones/resoluciones y migraciones de snapshots se contrastan contra HEAD al finalizar; no se afirma ejecución o despliegue en entornos reales.

Verificación documental final: 13 documentos, 655 enlaces locales/anclas, cero errores; historial DF-019 íntegro, otras fichas DF, AUD-002 y prioridades/planificación 1.3 preservados. Catálogo RBAC, modelos clínicos y migración de snapshots idénticos a HEAD. Generación final OpenAPI satisfactoria (`/tmp/be-exc-025-openapi-final.log`). git diff --check satisfactorio en ambos repositorios.
