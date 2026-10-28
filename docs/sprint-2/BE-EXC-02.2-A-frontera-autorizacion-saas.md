# BE-EXC-02.2-A — Frontera de autorización y administración SaaS

Fecha: **2026-10-25**. Implementación limitada a facultades del proveedor y auditoría global. Fuente: solicitud «DONQER LAB — BE-EXC-02.2-A — Frontera de autorización y administración SaaS», adjunto `c8271dc4-90c0-4709-b3b9-43cd54bb2ea2`, que comunica aprobación del PO para users/Sanctum, facultades independientes, bootstrap controlado y separación de saas.access.manage respecto del catálogo clínico. No cambia DF-019 APROBADO 1.3 ni implementa BE-EXC-02.2-B.

## Diagnóstico y decisiones

Se revisaron AGENTS, DF-019 1.3, diagnóstico BE-EXC-02.2, BE-EXC-02.1, autenticación, middleware, Teams, auditoría, rutas y convenciones de pruebas. El diagnóstico previo sigue siendo evidencia histórica: el grupo saas existente protege un laboratorio y AuditWriter exige laboratory_id. La aprobación nueva permite resolver esa falta de frontera mediante infraestructura independiente.

Se reutilizan users, sesión web/Sanctum, usuario activo y el formato de conflictos existente. No se crean cuentas, contraseñas, superusuarios, booleanos globales, roles Spatie globales ni laboratorios ficticios. Las únicas modificaciones de producción a archivos previos son registro del middleware, seis rutas independientes y ejemplos de configuración; el resto de lógica se añade en archivos nuevos. Los cambios de pruebas existentes actualizan únicamente conteos globales por esas seis rutas.

## Persistencia

Migración: `2026_10_25_070208_create_saas_access_authorization.php`.

| Tabla | Datos e integridad |
| --- | --- |
| saas_capabilities | code PK y descripción. Registra inicialmente sólo saas.access.manage. Namespace saas, definiciones protegidas de UPDATE/DELETE; no incluye facultades clínicas ni concede asignaciones. |
| saas_capability_assignments | Usuario, facultad, concedente, fecha/justificación/auditoría de concesión; revocador, fecha/justificación/auditoría de revocación; revisión 1 activa o 2 revocada. FK restrictivas a usuarios/capacidad/auditoría. Un índice parcial permite una asignación no revocada por usuario/facultad. |
| saas_audit_logs | operation_id UUID único, actor_type/actor_id/actor_label, usuario afectado, facultad, acción, origen, resultado, justificación, referencias anterior/nueva y hora servidor. Sin laboratory_id; append-only mediante Eloquent y PostgreSQL. |

Modelos: SaasCapability, SaasCapabilityAssignment y SaasAuditLog. Relaciones a usuario, facultad, concedente/revocador y actores de auditoría. Revocar no elimina la asignación: sólo admite una transición de revocación; el estado previo y los eventos permanecen. Volver a conceder crea otra fila. La fecha de auditoría procede de statement_timestamp() PostgreSQL y se usa para la transición, evitando atribuir al operador horas de una transacción anterior a una espera de bloqueo.

Los triggers validan correspondencia de actor, usuario, facultad, acción, resultado, fecha y justificación con la evidencia de concesión/revocación. Sólo el bootstrap inicial puede tener concedente humano null, con actor_type=system y actor_label=SaasAccessBootstrapSeeder. Unicidad de source=bootstrap impide dos concesiones iniciales. No se altera audit_logs ni su contrato tenant.

El rollback de la migración rechaza asignaciones/auditoría existentes. PostgreSQL es obligatorio para las operaciones del servicio; no se promete equivalencia SQLite ni protección contra un administrador de base capaz de desactivar triggers. No se ha migrado la base de desarrollo/producción.

## Autorización y separación tenant

SaasAccessAuthority verifica usuario activo y asignación vigente exacta, sin cache de permisos ni uso de Spatie. saas.access.manage permite consultar y administrar facultades, no administrar catálogos, acceder a datos clínicos o saltar controles tenant. No hay herencia, comodines ni Gate::before universal.

EnsureSaasCapability es middleware reutilizable, alias saas.capability. Las rutas nuevas usan auth:sanctum, user.active y saas.capability:saas.access.manage. No ejecutan el grupo tenant saas y no requieren X-Laboratory-ID, membresía o suscripción de laboratorio. La cabecera no concede autoridad. Se limpian CurrentLaboratory, contexto Teams y relaciones cacheadas de roles/permisos al entrar y salir de la frontera. Las rutas tenant existentes mantienen sus middleware.

SaasAccessManager revalida autorización en todas sus operaciones, incluso cuando se llama sin HTTP. La concesión a sí mismo está prohibida; no se admite actor/número de revisión de concesión desde payload. La revocación propia es posible sólo si conserva otro administrador activo. Las asignaciones no revocadas de una cuenta inactiva siguen en el historial, pero no otorgan autoridad efectiva.

El catálogo de definiciones de facultades no tiene CRUD público. Sólo se registra la capacidad aprobada; funciones SaaS futuras deberán definir sus facultades explícitamente y proteger sus propios servicios. El administrador de accesos no recibe por defecto esos permisos funcionales.

## Bootstrap controlado

SaasAccessBootstrapSeeder no forma parte de DatabaseSeeder ni de ningún flujo de arranque o petición. Usa configuración efectiva de config/saas.php:

- SAAS_BOOTSTRAP_ENABLED=true, habilitación explícita; false por defecto.
- SAAS_BOOTSTRAP_USER_ID, ID positivo de usuario existente y habilitado; sin predeterminado.
- SAAS_BOOTSTRAP_ALLOW_PRODUCTION=true, opt-in adicional si se ejecuta manualmente en producción; false por defecto.

Procedimiento futuro de ejecución, **no ejecutado contra desarrollo/producción en esta entrega**:

1. Verificar el usuario y su ID en el entorno autorizado. El ID 123 del comando es ilustrativo y debe sustituirse por el usuario existente seleccionado.
2. Revisar la configuración efectiva, incluida la cache de configuración: variables de shell no sustituyen una configuración previamente cacheada. No mostrar credenciales en diagnósticos.
3. Ejecutar explícitamente sólo el seeder de bootstrap:

```bash
SAAS_BOOTSTRAP_ENABLED=true SAAS_BOOTSTRAP_USER_ID=123 php artisan db:seed --class=SaasAccessBootstrapSeeder
```

En producción, si se autoriza esa ejecución manual, se requiere además SAAS_BOOTSTRAP_ALLOW_PRODUCTION=true y el --force habitual de Artisan. No hay ejecución automática de este seeder en producción.

4. Verificar la única asignación y evento inicial; retirar habilitación/opt-in en la configuración operativa tras provisión.

Falla sin asignaciones ni eventos si falta configuración, el usuario no existe/está inactivo o no se autorizó el entorno. No crea usuarios, contraseñas ni membresías. Reejecutar para el mismo usuario no duplica nada ni restaura una concesión posteriormente revocada. Cambiar el usuario configurado después de inicializar se rechaza: se usan las operaciones administrativas autorizadas para conceder a otros usuarios.

La concesión inicial se atribuye al componente system/SaasAccessBootstrapSeeder, no a un humano inventado ni al propio beneficiario como autoservicio. Se registra usuario configurado, facultad, fecha, justificación fija, resultado y operation_id verificable. La identidad humana de quien ejecuta el comando pertenece al control operativo de ejecución; no se fabrica a partir de datos del proceso.

## Contratos implementados

Prefijo `/api/v1/platform/access`. Todas las operaciones requieren la facultad explícita saas.access.manage.

| Método/ruta | Entrada y respuesta |
| --- | --- |
| GET /capabilities | Definiciones disponibles; 200 con data. Sólo saas.access.manage inicialmente. |
| GET /users | Usuarios con asignaciones no revocadas, incluyendo is_active para distinguir autoridad efectiva; 200, paginación de 50. |
| GET /users/{user}/capabilities | Asignaciones no revocadas de usuario existente; 200 paginado. |
| POST /users/{user}/capabilities | capability conocido y reason no blanco, hasta 2000; 201 con asignación, concedente y grant_audit_id. Sin autoservicio. |
| POST /users/{user}/capabilities/{assignment}/revoke | revision entero JSON y reason; 200 con asignación revision=2 y revocation_audit_id. La asignación debe corresponder al usuario. |
| GET /history | Auditoría global paginada por ID descendente. |

GET valida page entero positivo cuando está presente. Las escrituras rechazan campos desconocidos. Actor, resultado y fecha se calculan en backend; no se reciben del cliente. Los usuarios listados exponen ID/nombre/email/estado, sin contraseñas, cookies, tokens o secretos.

Errores: 401 sin autenticación, 403 sin facultad/usuario inactivo/autoconcesión, 404 usuario o asignación correspondientes inexistentes, 422 validación, 409 para saas_target_inactive, saas_capability_already_granted, saas_assignment_revision_conflict y saas_last_access_manager. Se conserva protección CSRF de sesión y su 419 cuando corresponda. OpenAPI está en SaasAccessDocumentation, con seis operaciones y schemas de entrada/salida; no documenta una cabecera de laboratorio obligatoria.

## Concurrencia y último administrador

SaasAccessAuthority::lock y triggers PostgreSQL comparten pg_advisory_xact_lock(182045,1). El servicio lo adquiere dentro de transacción antes de leer autorización o bloquear filas. Los triggers de sentencia lo adquieren antes de cambios de asignación y antes de desactivación/borrado de usuarios, también por SQL directo. Las lecturas administrativas del servicio usan el mismo protocolo para devolver autorización y datos coherentes; el coste de serialización es deliberado para este reducido plano administrativo.

La revocación se serializa, compara revisión, verifica actor efectivo y mantiene al menos otro usuario activo con saas.access.manage. Los triggers impiden revocar, desactivar o borrar al último activo, incluyendo carreras entre esas operaciones. No se introduce un endpoint de modificación global de cuentas: se protege la restricción en PostgreSQL para los caminos actuales/futuros. Los llamadores futuros de esas escrituras deben respetar el orden de bloqueo y mapear los errores de integridad pertinentes; un cliente SQL privilegiado aún puede provocar un deadlock al invertirlo, que PostgreSQL debe abortar sin perder el invariante.

## Auditoría y atomicidad

SaasAuditWriter registra concesiones y revocaciones exitosas en la misma transacción que la asignación. Resultado succeeded, actores reales o system bootstrap, justificación, acción y referencias quedan preservados. Un fallo de auditoría revierte concesión/revocación; una asignación sin evidencia correspondiente se rechaza en PostgreSQL. No se registran payloads libres ni se cambian membresías tenant.

Denegaciones de autorización no escriben asignaciones ni falsos eventos exitosos. No se implementa una bitácora de todos los intentos HTTP rechazados. Los campos de resultado admiten ampliación futura, pero esta entrega sólo emite eventos de operaciones exitosas confirmadas. No existe seeder que conceda facultades clínicas.

## Pruebas y resultados

Entorno aislado: Compose donqer-be-exc-022a, deploy/testing/compose.yaml, PostgreSQL 17 en tmpfs, red interna sin puertos y código/configuración de testing en lectura. TestDatabaseGuard verifica identidad y configuración antes de conectar. Sin pruebas sobre datos de desarrollo/producción.

La batería nueva comprueba autenticación real de sesión y sin sesión, roles tenant denegados, operador sin membresía, concesión/revocación y regrant, no autoescalada, aislamiento de Teams, seeder controlado/idempotente y sin usuario, no restauración de concesiones revocadas, último administrador, account status, historial inmutable, rollback de auditoría y correspondencia OpenAPI/rutas.

Siete carreras usan procesos y conexiones independientes, con espera real acreditada por pg_stat_activity: bootstrap duplicado, concesión duplicada, revocación duplicada, revocación frente a nueva concesión del actor afectado, dos autorrevocaciones, desactivación frente a autorrevocación y dos desactivaciones. Siempre queda al menos un administrador activo.

Resultados ejecutados en PostgreSQL real:

- Regresión dirigida de SaasAccess, Api/V1/Auth, Authorization, Saas, ClinicalReasons y ClinicalFinalization: **250 pruebas, 1.389 aserciones**, sin fallos. Incluye 29 casos nuevos de SaaS, siete de ellos de concurrencia.
- Inventarios de contratos, rutas y OpenAPI de tests/Feature, filtro `(contract|openapi|route)`: **183 pruebas, 1.548 aserciones**, sin fallos. Es una ejecución separada con casos que pueden solaparse con la anterior; no se suman como pruebas únicas.
- Laravel Pint y git diff --check sin incidencias. No se ejecutó la suite completa.

Los inventarios globales de pruebas existentes avanzan por seis operaciones: 143 operaciones OpenAPI y 151 rutas en el bootstrap de tests; no se cambia el inventario de 128 rutas con permisos tenant can:.

## Compatibilidad y trabajo posterior

Se conserva la persistencia BE-EXC-02.1 y el código/contratos BE-S2-08, informes y snapshots. La autorización SaaS no deriva de FK de actor de motivos ni de roles de laboratorio. No se implementan gobierno del catálogo clínico, selección/adopción, motivos locales, solicitudes estructuradas, viabilidad o frontend.

BE-EXC-02.2-B necesitará facultades funcionales de catálogo separadas, orquestación de versiones/auditoría global y endpoints propios. Puede reutilizar la frontera y el esquema de auditoría, pero deberá definir sus acciones/subjects y sus controles de acceso; saas.access.manage por sí solo no autoriza esa función. No se inicia ese entregable ni BE-EXC-02.3. Sin Trello, commits o push.
