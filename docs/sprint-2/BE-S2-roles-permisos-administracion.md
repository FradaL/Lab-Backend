# BE-S2 — Administración de permisos por rol y laboratorio

## Arquitectura y alcance

Se reutilizan Spatie Laravel Permission 8.3, Sanctum, `teams=true`, `laboratory_id` y los pivotes existentes. `RbacCatalog::PERMISSIONS` es la única lista autorizada de permisos operativos: contiene **99 códigos** (98 anteriores + `roles.permissions.manage`). No se consulta la tabla de permisos como catálogo autorizado: puede contener registros ajenos o históricos. No existe una lista de permisos no delegables ni un segundo sistema RBAC.

`RolePermissionController` expone el contrato; `ReplaceRolePermissionsRequest` valida el reemplazo; `RolePermissionManager` resuelve exclusivamente roles del laboratorio activo y ejecuta la escritura. Se conservan los seis nombres predefinidos. No hay endpoints de creación de roles o permisos.

Las rutas conservan la cadena existente: autenticación, identidad activa, resolución de `X-Laboratory-ID`, membresía activa, contexto Teams y acceso SaaS por suscripción. Las tres rutas exigen el permiso efectivo `roles.permissions.manage`. No exigen `roles.view` ni un nombre de rol del actor. La ruta previa `GET /laboratory/roles` conserva su función de listar roles **asignables a usuarios** y sus restricciones estructurales; no representa el catálogo de matrices administrables. Para la interfaz de matrices se utilizan los seis nombres ratificados y las consultas siguientes.

## Endpoints

Todas las rutas tienen prefijo `/api/v1`, reciben `X-Laboratory-ID` y devuelven un objeto `data`.

| Método y ruta | Resultado |
| --- | --- |
| `GET /laboratory/permissions` | Catálogo: `code`, `group`, `label`. Agrupa por primer segmento del código. La etiqueta legible se deriva del código; no pretende ser una traducción. |
| `GET /laboratory/roles/{role}/permissions` | `id`, `name`, `permissions`, `revision`, `protected`, `editable`. |
| `PUT /laboratory/roles/{role}/permissions` | Reemplaza el conjunto completo y devuelve la matriz con la nueva revisión. |

`{role}` admite el nombre ratificado o un ID. Un ID de otro laboratorio responde 404, incluso si el actor pertenece a ambos laboratorios. Los roles globales o de otro guard tampoco son accesibles.

```json
{
  "expected_revision": 4,
  "permissions": ["orders.view", "patients.view", "roles.permissions.manage"],
  "reason": "Actualización de responsabilidades"
}
```

`permissions: []` es válido y retira todos los permisos de ese rol. `reason` es obligatorio, debe contener texto y tiene máximo 1000 caracteres. La revisión es un entero positivo. Se rechazan códigos desconocidos, SaaS, duplicados, listas asociativas y campos adicionales, incluidos `laboratory_id` y `guard_name`, aunque sean nulos. Un permiso del catálogo sin registro aprovisionado se rechaza con 422; el endpoint nunca crea permisos.

Respuestas: 200 éxito; 400 contexto ausente/inválido; 401 sin autenticación; 403 acceso, permiso o rol protegido; 404 laboratorio/rol inexistente o ajeno; 409 `role_permissions_revision_conflict`; 422 validación. Un fallo de auditoría causa error y rollback completo.

## Delegación y roles protegidos

`owner` y `administrator` pueden consultarse pero no modificarse mediante PUT: `protected=true`, `editable=false`. Los otros cuatro roles son configurables. Un actor autorizado puede conceder y retirar `roles.permissions.manage` a cualquiera de ellos, incluido su propio rol. La siguiente operación vuelve a comprobar los permisos efectivos.

Se admiten todos los permisos operativos del catálogo, incluidos los administrativos, clínicos y financieros. Concederlos habilita la comprobación de autorización correspondiente; las reglas clínicas, requisitos profesionales, separación de actores y políticas financieras de sus servicios continúan vigentes. Las capacidades SaaS siguen en sus tablas, autoridades y rutas separadas.

La asignación de roles a usuarios y los cambios de membresía conservan las restricciones existentes de owner/administrator. Delegar la edición de matrices no elimina esas restricciones estructurales.

## Concurrencia y transacción

`roles.permissions_revision` empieza en 1. Cada PUT aceptado incrementa la revisión, incluso si el conjunto no cambia. La revisión no es un contador de permisos efectivos de un usuario.

La escritura toma `FOR UPDATE` sobre el laboratorio y después sobre el rol. Comparte el primer bloqueo con `LaboratoryUserManager`, serializando cambios de roles, membresías y matrices del mismo laboratorio. Tras esperar el bloqueo, recarga relaciones y vuelve a autorizar al actor: una solicitud que había pasado el middleware antes de una revocación no puede aprovechar permisos obsoletos. Las lecturas de matriz toman un bloqueo compartido del laboratorio para obtener pivotes y revisión coherentes.

Dos escritores con la misma revisión producen un éxito y un 409. El cliente debe recargar y permitir al usuario revisar el conflicto antes de reenviar; no debe reintentar automáticamente con una revisión nueva.

La protección del último owner exige **otro owner con identidad global y membresía activas** antes de retirar el rol owner o desactivar su membresía. Un administrator o un owner inactivo no lo sustituye. La comprobación y modificación ocurren bajo el mismo bloqueo del laboratorio; dos operaciones concurrentes no pueden retirar los dos últimos owners. Se conserva además la protección administrativa previa. Código de conflicto nuevo: `laboratory_last_owner`.

## Auditoría

Se usa `AuditWriter` y el registro inmutable `audit_logs`, dentro de la misma transacción que pivotes y revisión:

- `event`: `role.permissions_replaced`.
- `laboratory_id`, `user_id` (actor), `created_at`.
- `auditable_type=role`, `auditable_id` y `metadata.role_name`.
- `old_values`: revisión y lista anterior; `new_values`: revisión y lista nueva.
- `metadata`: `reason`, `added`, `removed`.

Las listas se ordenan para facilitar comparación. Si la inserción falla, se revierten pivotes y revisión. El motivo queda auditado; no debe incluir secretos ni información clínica de pacientes.

## Caché y autorización efectiva

La versión instalada de Spatie invalida caché durante `syncPermissions()` y al guardar un Role, antes de que una transacción externa haga commit. Para este endpoint se utiliza la relación `permissions()` de Spatie para sincronizar su mismo pivote y una actualización explícita de revisión, evitando esos eventos prematuros. La invalidación posterior a la escritura se registra con `DB::afterCommit`.

Spatie también conserva una colección en memoria, además del backend de caché. Una invalidación compartida por sí sola no limpia colecciones ya cargadas en otros workers; un lector concurrente puede además repoblar datos antiguos. Se usa el store `array` para Spatie y se limpia el registrador y las relaciones del usuario al inicio de cada solicitud con contexto Teams. Las colas limpian el registrador antes de procesar cada trabajo. Por ello cada nueva operación carga datos confirmados desde PostgreSQL y no depende de mensajes de invalidación, un TTL o una sesión nueva. La limpieza local previa a reautorizar dentro de la transacción no publica invalidaciones ni datos sin confirmar a otros procesos.

Una sesión autenticada permanece abierta y puede perder o ganar permisos en su siguiente solicitud. Se calcula la unión de roles y permisos directos usando Spatie. Retirar un permiso de un rol no garantiza que el usuario lo pierda si lo conserva por otra vía. `GET /auth/authorization` continúa exponiendo la autorización efectiva.

Costo: más lecturas de base de datos por solicitud, incluida la carga del catálogo/relaciones de Spatie. No cambiar el store de Spatie por Redis/database sin implementar y verificar una estrategia de coherencia equivalente. Los trabajos con autorización deben establecer contexto Teams, resolver usuarios actuales y limpiar el contexto al terminar; no conservar modelos con relaciones cargadas entre operaciones. Una operación ya autorizada y en ejecución no se cancela retroactivamente; la escritura de matrices sí reautoriza tras esperar su bloqueo.

## Migración y aprovisionamiento

`2026_10_30_000001_add_role_permission_revisions.php`:

1. Añade `permissions_revision` a los roles existentes, valor inicial 1.
2. Registra el nuevo permiso global `roles.permissions.manage`, guard `web`.
3. Lo concede de forma **aditiva** a los roles owner/administrator existentes de laboratorios. No sustituye sus conjuntos ni modifica roles configurables.
4. Invalida después del commit. El rollback retira ese permiso y la columna; conservar un respaldo de la auditoría antes de revertir una versión que ya recibió modificaciones.

`LaboratoryRoleProvisioner::provision($laboratory)` es la operación explícita para aprovisionamiento inicial: registra el catálogo y crea los seis roles faltantes. La plantilla owner se obtiene directamente de **todo `RbacCatalog::PERMISSIONS`**, incluyendo administración de matrices y excluyendo SaaS. Administrator conserva su plantilla previa más el nuevo permiso de administración. Los seeders DEMO generales que crean roles desde `rolePermissions()` reciben la plantilla owner corregida al crear esos roles. Los datasets aislados de motivos clínicos definen matrices sintéticas propias (owner con cinco permisos y complementos directos por usuario); no representan el aprovisionamiento general y se conserva su configuración dirigida, descrita en `DEMO-development-seeders.md`.

`RolePermissionSeeder` reutiliza el aprovisionador y ya no ejecuta `syncPermissions` sobre roles existentes. Repetir el aprovisionamiento preserva matrices personalizadas y revisiones. Los roles owner ya existentes no reciben retrospectivamente los otros permisos que faltaban en la plantilla antigua: corregir la plantilla de nuevos laboratorios no autoriza a sobrescribir instalaciones existentes. La migración concede únicamente el nuevo permiso de gestión a roles protegidos.

No se añade reseeding periódico. Cuando se incorpore un permiso operativo:

1. Añadirlo al catálogo operativo autorizado y probar sus reglas de dominio.
2. Registrar el nuevo código mediante una migración de datos versionada; los endpoints lo admitirán sin cambios.
3. Definir explícitamente si la versión concede el permiso a roles protegidos existentes. Usar concesiones aditivas, acotadas al código nuevo; no sincronizar plantillas completas.
4. Preservar roles configurables: su asignación corresponde a un actor autorizado mediante PUT, con revisión y auditoría. Cualquier cambio directo de matrices en una migración futura debe actualizar también la revisión y limpiar caché después del commit.
5. Los laboratorios nuevos obtienen automáticamente el catálogo completo para owner mediante el aprovisionador.

## Despliegue y operación

Aplicar la migración mediante el procedimiento habitual, regenerar OpenAPI y configuración cacheada, y reiniciar workers/procesos persistentes para cargar la estrategia de caché nueva. No ejecutar seeders DEMO ni sincronizaciones completas en producción. El aprovisionamiento de futuros laboratorios debe invocar el servicio explícito dentro de su flujo de alta; actualmente no hay un endpoint público de alta de laboratorios en esta aplicación.

La migración requiere un bloqueo breve de esquema sobre `roles`; programarla según el tamaño y tráfico de la instalación. El bloqueo por laboratorio serializa sus cambios administrativos, permitiendo independencia entre laboratorios. Escritores externos que alteren pivotes directamente deben respetar el mismo protocolo: no quedan cubiertos por el control optimista de estos endpoints.

No se realizaron migraciones ni seeders sobre bases compartidas, commits o push. La validación utiliza el Compose de testing, con PostgreSQL 17 efímero, red interna y código montado de solo lectura; `TestDatabaseGuard` comprueba conexión, usuario, base y versión antes de permitir migraciones.

## Pruebas y resultados

`RolePermissionAdministrationTest` verifica catálogo, matrices, delegación y redelegación, revocación, roles protegidos, permisos clínicos/financieros, campos prohibidos, códigos inválidos/SaaS/duplicados, revisión, auditoría completa, rollback por trigger PostgreSQL, múltiples roles, permisos directos, aislamiento, contexto/suscripción, último owner y preservación de personalizaciones.

`RolePermissionConcurrencyTest` ejecuta procesos reales con conexiones independientes: reemplazos de la misma revisión, dos degradaciones de owner, desactivación frente a cambio de rol, caché caliente en otro proceso y revocación mientras otra escritura espera su bloqueo. `RolePermissionDeploymentTest` verifica actualización y rollback de una instalación con matrices previas.

`RolePermissionSeederTest` prueba por igualdad de conjuntos que owner recibe los **99/99 permisos operativos**, seis roles por laboratorio y ninguna capacidad SaaS. Se ajustan las expectativas del catálogo en las pruebas DEMO y el inventario de rutas protegidas (incluidas rutas clínicas previas que faltaban en ese inventario).

Comando reproducible desde la raíz:

```sh
docker compose -p donqer-lab-rbac-tests -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-lab-rbac-tests -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result
docker compose -p donqer-lab-rbac-tests -f deploy/testing/compose.yaml down
```

Resultados sobre PostgreSQL 17 aislado:

| Selección | Resultado |
| --- | --- |
| Autorización, membresías, nuevos endpoints, despliegue, concurrencia, autorización efectiva y contrato de reposición | **110 pruebas, 4302 aserciones, aprobadas** |
| Inventarios de rutas y OpenAPI actualizados | **17 pruebas, 351 aserciones, aprobadas** |
| Adopción de motivos clínicos, con fixtures ajustados a la plantilla owner completa | **21 pruebas, 7510 aserciones, aprobadas** |
| Reportes y validación clínica, incluida política profesional aplicada al owner | **122 pruebas, 699 aserciones, aprobadas** |
| Seeder de órdenes, incluido rollback que conserva el catálogo previo | **13 pruebas, 897 aserciones, aprobadas** |
| Entrega y políticas financieras | **33 pruebas, 133 aserciones, aprobadas** |
| Pint y `git diff --check` | Aprobados |

Las selecciones se solapan; sus cantidades no deben sumarse como pruebas distintas.

La ejecución amplia inicial completó **3784 pruebas y 34529 aserciones**, con 62 errores, 23 fallos y 3 omisiones. Durante esa ejecución se actualizaron expectativas de inventario y fixtures que aún estaban cargados con sus versiones anteriores en el runner; las selecciones correspondientes de la tabla se ejecutaron nuevamente sobre el código corregido. La segunda ejecución amplia completó **3787 pruebas y 34671 aserciones**, con 62 errores, 10 fallos y 3 omisiones. De sus diez fallos, cinco correspondían a los fixtures de owner y rollback cargados antes de su ajuste y ya fueron revalidados en las selecciones aprobadas; los otros cinco fueron fallos de emisión/concurrencia de reportes asociados al problema histórico descrito abajo. La comprobación nueva del requisito profesional del owner se añadió durante ese diagnóstico y está incluida en las 122 pruebas aprobadas de reportes/validación.

**Incidencia previa de la suite global:** `StructuredClinicalExceptionTest::test_migration_preserves_preexisting_text_requests_decisions_report_snapshots_and_pdf` afecta las emisiones posteriores dentro del mismo proceso. PostgreSQL rechaza la inserción con `New report requires current versioned clinical and pending evidence`, y la aplicación responde `report_issuance_failed`/503. Se reprodujo sobre una copia temporal de **HEAD**, sin esta implementación, con esta selección mínima: 3 casos, el caso histórico aprobado y 2 errores de emisión.

```sh
php vendor/bin/phpunit --do-not-cache-result --filter 'test_migration_preserves_preexisting_text_requests_decisions_report_snapshots_and_pdf|test_not_performed_preserves_original_reference'
```

Los mismos casos de reportes pasan aislados, y la selección completa de reportes/validación pasa con 122 casos. Queda pendiente corregir el aislamiento de esa prueba histórica; no se modifican las reglas ni los triggers clínicos para ocultar el fallo. El resultado global no se presenta como aprobado. También se confirmaron 12 fallos previos de inventario contra HEAD; sus conteos ya están actualizados y pasan en la selección de 17 pruebas.
