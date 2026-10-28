# BE-EXC-02.2 — Auditoría de autorización y propuesta de gobierno global

Fecha: **2026-10-25**. **Implementación detenida por el criterio de seguridad de la solicitud.** No se encontró una frontera de autorización administrativa SaaS adecuada en el código inspeccionado. Este documento registra diagnóstico y propuesta **PROPUESTO**, pendiente de aprobación del Product Owner; no implementa rutas, permisos, migraciones, roles o auditoría global.

Fuente de la tarea: solicitud **«DONQER LAB — BE-EXC-02.2 — Gobierno global y auditoría SaaS de motivos clínicos»**, adjunto `201dc18a-49c6-4fd2-b600-f94534868461`. Su apartado 3 exige detener la implementación antes de crear rutas, permisos o migraciones cuando falte autorización SaaS, y prohíbe introducir unilateralmente roles globales, superusuarios, bypass tenant o laboratorios ficticios.

## Fuentes y alcance de la inspección

Se revisaron AGENTS aplicables, [DF-019 APROBADO 1.3](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), [DF-016](../../../donqer-lab-product/03-decisiones/DF-016-finalizacion-clinica.md), [plan PROPUESTO 1.3](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md), [AUD-002 documental 1.3](../../../donqer-lab-product/07-auditorias/AUD-002-excepciones-clinicas-v1.md), propuesta BE-EXC-01 disponible en la conversación y [BE-EXC-02.1](BE-EXC-02.1-identidad-versionado-motivos-clinicos.md), incluido su código actual. El informe BE-EXC-01 no tiene archivo independiente localizado en los repositorios y conserva carácter propuesto.

Método: lectura estática de autenticación, rutas API/web/console, middleware, proveedores registrados, User, RBAC, configuración y migraciones de Teams, administración tenant, audit_logs/AuditWriter y almacenamiento de motivos. Búsquedas de roles globales, flags de administrador, gates y rutas de plataforma contrastadas con los mecanismos efectivos; la ausencia no se deduce sólo de palabras clave. No se consultó la base de desarrollo, no se accedió a secretos ni se ejecutaron pruebas, migraciones, seeders o servicios. Configuración aplicada, grants reales y despliegue son **NO VERIFICABLES** mediante esta revisión.

Los once archivos de BE-EXC-02.1 y los cambios documentales previos de producto ya estaban presentes al comenzar esta tarea; no constituyen cambios de BE-EXC-02.2.

## Arquitectura encontrada — EXISTENTE Y VERIFICADO EN CÓDIGO

| Área | Evidencia | Conclusión |
| --- | --- | --- |
| Inicio de sesión | [AuthController::login](../../laravel/app/Http/Controllers/Api/V1/Auth/AuthController.php), LoginRequest, config/auth.php | Sesión guard web; credenciales y usuario activo, regeneración de sesión. El contrato SPA usa cookie HttpOnly y CSRF. Autenticar no concede autoridad global. |
| Protección de API | [routes/api.php](../../laravel/routes/api.php), [bootstrap/app.php](../../laravel/bootstrap/app.php) | auth:sanctum y statefulApi. El grupo llamado saas contiene user.active, laboratory.context, permission.team y subscription.active: protege operación de un laboratorio, no administración del proveedor. |
| Laboratorio activo | [ResolveLaboratoryContext](../../laravel/app/Http/Middleware/ResolveLaboratoryContext.php) | X-Laboratory-ID obligatorio, laboratorio activo y membresía activa; fija CurrentLaboratory. La cabecera selecciona contexto, no demuestra autoridad SaaS. |
| Contexto de permisos | [SetPermissionTeamContext](../../laravel/app/Http/Middleware/SetPermissionTeamContext.php), config/permission.php | Teams activado, laboratory_id como team_foreign_key, relaciones de permisos limpiadas al cambiar contexto; se restaura team null al terminar. |
| Roles y grants | [RbacCatalog](../../laravel/app/Authorization/RbacCatalog.php), RolePermissionSeeder | Seis roles y 84 permisos del catálogo vigente. Owner y administrator son roles tenant. El seeder crea/sincroniza roles por laboratorio. Los nombres de cuentas demo no acreditan administración SaaS. |
| Restricciones Teams | [Migración de Teams](../../laravel/database/migrations/2026_10_07_044641_enable_permission_teams_for_laboratories.php) | roles.laboratory_id obligatorio, FK a laboratorio y FK compuestas de asignación; rechaza roles previos sin mapeo seguro/null. No basta utilizar team null/0 para obtener un rol de proveedor. |
| Administración de usuarios | [LaboratoryUserManager::authorizeActor](../../laravel/app/UserAdministration/LaboratoryUserManager.php) | Usuario/membresía activos, contexto Teams y permiso del laboratorio; bloqueo/revalidación en escrituras. No administra facultades SaaS. |
| Frontera global | User, AppServiceProvider, bootstrap/providers.php, rutas y RBAC | No se localizaron autorización de operador SaaS, grants de proveedor, guard/policy administrativa global o mecanismo de provisión/revocación global utilizable. |
| Auditoría administrativa | [AuditWriter::record](../../laravel/app/Audit/AuditWriter.php), [migración audit_logs](../../laravel/database/migrations/2026_10_06_120000_create_audit_logs_table.php), AuditLog | El writer exige Laboratory persistido; audit_logs.laboratory_id es NOT NULL y FK. Auditoría tenant, no global. Actor nullable en ese esquema no proporciona autoridad global. |
| Persistencia de motivos | [ClinicalReasonVersionStore](../../laravel/app/ClinicalExams/ClinicalReasonVersionStore.php), modelos/migración BE-EXC-02.1 | Identidades y versiones globales/locales separadas, inmutabilidad y concurrencia implementadas. appendGlobal es almacenamiento interno, explícitamente sin frontera de autorización. FK created_by identifica usuario, no certifica facultad SaaS. |

Se pueden reutilizar autenticación, usuario activo, validación, formato de errores, transacciones y almacenamiento BE-EXC-02.1. No se puede reutilizar una facultad tenant para suplir la autorización global faltante. Tampoco deben alterarse los requisitos de auditoría tenant para alojar artificialmente eventos del proveedor.

## Riesgos de utilizar directamente la autorización actual

- Añadir permisos globales al catálogo tenant podría permitir otorgarlos mediante administración de un laboratorio y producir escalada al proveedor.
- Interpretar owner/administrator, correo demo o mera autenticación como autorización global concedería poderes no aprobados.
- Quitar laboratory.context/permission.team/subscription.active de rutas existentes debilitaría controles tenant sin crear un control global equivalente.
- Hacer nullable laboratory_id o inventar un laboratorio propietario para registrar auditoría global mezcla ámbitos y debilita el contrato actual.
- Exponer appendGlobal directamente permitiría escritura sin verificar una facultad SaaS ni registrar una auditoría administrativa global atómica.

La existencia de tablas globales no representa una vulnerabilidad HTTP ya expuesta: no se encontraron endpoints administrativos globales de motivos. El riesgo aparece al conectarlas a una frontera insuficiente.

## Alternativas — PROPUESTO, sin implementación

| Alternativa | Ventajas | Costes y riesgos |
| --- | --- | --- |
| A. Reutilizar users y sesión actual con facultades SaaS independientes de Teams | Menor duplicación; conserva identidad de actor y FK actuales. Facultades limitadas al catálogo y revocables, sin herencia tenant. | Requiere aprobar quién otorga/revoca, provisión inicial y separación de contexto. Una cuenta comprometida con grant SaaS tiene alcance global. |
| B. Identidades/guard administrativos separados | Separación explícita de acceso administrativo y cuentas de laboratorio. | Duplica ciclo de cuentas/sesiones y requiere definir correspondencia con created_by de BE-EXC-02.1, recuperación y provisión. No se puede introducir sin decisión de seguridad. |
| C. Ampliar Spatie para roles globales además de Teams | Reutiliza biblioteca de permisos. | El esquema vigente exige laboratorio; requiere cambios delicados de constraints, resolución y cache de permisos. Mayor superficie de regresión en aislamiento. No equivale a usar team null sin más. |

**Recomendación: A**, con capacidades explícitas de lectura/escritura limitadas al catálogo, sin superusuario ni Gate::before que permita universalmente todas las acciones. Los nombres concretos de tablas, middleware y permisos quedan por especificar. No se crean grants, cuentas ni roles en esta entrega.

Una herramienta CLI podría ser un canal administrativo futuro, pero no sustituye la identificación de operador, autorización y auditoría aprobadas. No se propone un atajo CLI para evitar el criterio de detención.

## Decisión necesaria del Product Owner

**PENDIENTE DE APROBACIÓN DEL PRODUCT OWNER:** reutilizar las identidades users y la autenticación existente para operadores Donqer con facultades SaaS explícitas, separadas de roles/permisos de laboratorio, y auditoría global independiente.

La decisión debe identificar quién puede otorgar/revocar esas facultades y cómo se autoriza/provisiona el primer operador sin autoescalada ni modificación desde rutas tenant. Confirmar el alcance de lectura frente a gestión y el uso de cuentas existentes frente a identidades separadas. No se atribuye este mecanismo de seguridad a la aprobación funcional de DF-019 I.E: esa regla exige gobierno independiente, pero no aprobó su implementación concreta.

## Cambios necesarios después de la aprobación — PROPUESTO

1. Especificar registro de facultades del proveedor y su historial de concesión/revocación, ligado a actor real y sin laboratory_id. Definir el procedimiento administrativo inicial autorizado.
2. Añadir una frontera de autorización global que reutilice auth:sanctum y usuario activo y compruebe únicamente facultades SaaS explícitas. La selección de laboratorio no concede autoridad; mantener las rutas tenant existentes sin cambios y prevenir contaminación de contexto Teams/CurrentLaboratory entre solicitudes.
3. Revalidar bajo bloqueo usuario/facultad vigentes antes de confirmar escrituras. La revocación concurrente no debe quedar anulada por cache o por un permiso tenant.
4. Crear el escritor y almacenamiento de auditoría global append-only, independientes de AuditWriter. Conservar actor, acción, motivo, versión afectada, hora servidor, justificación, resultado y referencias anterior/nueva a versiones inmutables. No registrar contraseñas, cookies, tokens ni datos clínicos arbitrarios.
5. Orquestar creación de identidad/publicación con ClinicalReasonVersionStore y auditoría global en la misma transacción; fallo de auditoría revierte la escritura. Para publicación usar número esperado y bloquear identidad. Vigencia cambia mediante una nueva versión, nunca UPDATE de una histórica.
6. Especificar/documentar endpoints y OpenAPI sólo después de asegurar la frontera. No tocar solicitudes clínicas pendientes ni implementar selección/adopción tenant. Definir qué metadatos de cambio relevante consumirán entregables posteriores sin afirmar una reevaluación ya implementada.

Los eventos administrativos exitosos deben quedar atómicos con el catálogo. Si se registran denegaciones/fallos, usar un canal de seguridad explícitamente definido y sanitizado, separado del evento exitoso revertido; no modificar catálogo o evidencia clínica ni fabricar una auditoría de éxito. La política de retención/acceso de esos eventos requiere especificación.

## Borrador de API condicionado a aprobación — NO IMPLEMENTADO

Prefijo candidato `/api/v1/platform/clinical-reasons`, no encontrado en las rutas actuales. Es una propuesta de namespace, no contrato aprobado. Sin X-Laboratory-ID como fuente de autoridad. Autenticación de sesión/cookie y CSRF reutilizables; facultades de lectura/gestión SaaS por definir, independientes de permisos tenant.

| Método/sufijo propuesto | Facultad | Entrada | Respuesta esperada |
| --- | --- | --- | --- |
| GET `/` | Lectura SaaS | page y filtros validados | 200, identidades/última definición, paginación propuesta de 50. |
| POST `/` | Gestión SaaS | code y definición inicial completa | 201, identidad y versión 1 con referencia de auditoría; transacción atómica. |
| GET `/{reason}/versions` | Lectura SaaS | page | 200, definiciones históricas inmutables paginadas. |
| POST `/{reason}/versions` | Gestión SaaS | expected_number y definición completa BE-EXC-02.1 | 201, versión sucesora y referencia de auditoría; 409 ante revisión obsoleta. |
| GET `/{reason}/events` | Lectura de auditoría SaaS | page | 200, eventos administrativos autorizados y sanitizados, paginados. |

Vigencia se administra con la publicación de nueva versión y su is_active, usando la misma operación. No se añade una mutación in situ ni un endpoint que adopte versiones por laboratorio.

Validaciones propuestas: código canónico/único, definición con name/description/classification/evidence_requirements/is_active/change_reason según el store; actor y hora derivados del backend, rechazo de campos desconocidos. Conservar requisito de justificación para cambio de vigencia. No permitir que seleccionar clasificación apruebe una excepción.

Errores propuestos: 401 sin autenticación; 403 sin facultad SaaS o usuario inactivo, también owner/administrator sin grant independiente; 404 motivo inexistente para actor autorizado; 409 código duplicado o número esperado obsoleto; 422 payload inválido. Se mantienen los controles CSRF de sesión y su eventual 419, sin confundirlos con autorización. Una denegación no debe crear identidad, versión o resolución. Los errores y payloads aún no están publicados en OpenAPI ni implementados.

## Pruebas y estado de ejecución

**No se ejecutaron pruebas en BE-EXC-02.2**, porque se activó el criterio de detención antes de implementar la frontera. No se levantó PostgreSQL ni se crearon pruebas de una autorización inexistente. Los resultados previos BE-EXC-02.1 no se atribuyen a esta revisión.

Después de aprobación e implementación deberán ejecutarse en PostgreSQL real: sin sesión, usuario sin grant, owner/administrator tenant denegados, operador SaaS explícito permitido, usuario inactivo/revocación concurrente, acceso con distintos laboratorios o cabecera omitida sin elevación de privilegios, cache/contexto A-B-A sin contaminación, identidad/versión/vigencia e inmutabilidad, carreras, auditoría+rollback, denegaciones sin mutaciones de dominio y regresión BE-EXC-02.1/BE08. El operador global no recibe por ello acceso clínico a laboratorios.

## Resultado y dependencias

Único archivo creado por esta tarea: este documento. **Cero migraciones, rutas, permisos, roles globales o auditoría SaaS implementados.** Código BE-EXC-02.1, BE-S2-08, informes/snapshots y documentación aprobada conservados. Sin cambios de datos, Trello, commits o push.

BE-EXC-02.3 no se inicia. Su selección individual/adopción necesitará catálogo global gobernado y versionado, lecturas tenant autorizadas de globales disponibles, diferencias y contrato de cambio restrictivo relevante. La autoridad de selección seguirá siendo del owner del laboratorio, distinta de la facultad de modificar el catálogo Donqer. Esas dependencias no se implementan por documentarlas.
