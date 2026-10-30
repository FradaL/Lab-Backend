# DEMO de desarrollo — FE-EXC-01, FE-SaaS-02 y pruebas funcionales

Esta guía corresponde al entorno dedicado de catálogo y perfiles SaaS. Para
reconstruir el dataset general de **Laboratorio** con `DatabaseSeeder`, seguir
[Motivos para excepciones del dataset de Laboratorio](../../README.md#motivos-para-excepciones-del-dataset-de-laboratorio).
Con `SAAS_DEMO_CLINICAL_REASON_ID` vacío, ese dataset prepara sus motivos locales
automáticamente y no necesita ejecutar este procedimiento.

## Preparación para desarrolladores

Desde la raíz de `backend-lab`, con Docker, la imagen local `donqer-lab-workspace`
y las dependencias `laravel/vendor` instaladas:

```bash
./deploy/demo-exc/development.sh
```

El procedimiento pide una contraseña con entrada oculta (mínimo 12 caracteres),
levanta el Compose dedicado existente, aplica migraciones pendientes y ejecuta:

```bash
php artisan db:seed --class=DemoDevelopmentSeeder --no-interaction
```

`php artisan db --class=...` no ejecuta seeders; el comando correcto es `db:seed`.
El seeder requiere configuración de conexión autorizada y
`DONQER_EXC_DEMO_OWNER_PASSWORD`; el script encapsula esa preparación, sin configurar
manualmente un ID de administrador. También puede recibir esa variable desde un
gestor de secretos. No pasar contraseñas como argumentos, guardarlas en Git ni
activar trazas de shell. El script desactiva `xtrace` antes de leer el secreto.
El secreto sólo se introduce en el entorno del proceso/contenedor; Docker debe
ser accesible únicamente a operadores autorizados. En la BD se almacena su hash.

Para repetir, ejecutar el mismo script con **la contraseña original**. Las
contraseñas existentes no se cambian; un secreto distinto rechaza toda la operación.
No hay una contraseña predeterminada ni un mecanismo para recuperar su texto
original desde la BD: conservarla en el gestor de contraseñas del equipo y
compartirla con los desarrolladores mediante ese canal autorizado.

## Ocho perfiles y credenciales

Todos están activos. Los ocho usan el secreto elegido al preparar el entorno.
La matriz fue confirmada expresamente por el solicitante para FE-SaaS-02.

| Perfil | Correo | Laboratorios | Capacidades SaaS exactas |
| --- | --- | ---: | --- |
| 1. Solo laboratorio | `exc-demo-owner@donqerlab.test` | 2 | `[]` |
| 2. Solo consulta | `demo-saas-view@donqerlab.test` | 0 | `saas.clinical_reasons.view` |
| 3. Solo creación | `demo-saas-create@donqerlab.test` | 0 | `saas.clinical_reasons.create` |
| 4. Solo publicación | `demo-saas-publish@donqerlab.test` | 0 | `saas.clinical_reasons.publish` |
| 5. Solo administración | `demo-saas-manage@donqerlab.test` | 0 | `saas.clinical_reasons.manage` |
| 6. SaaS completo | `demo-saas-full@donqerlab.test` | 0 | Las cuatro anteriores |
| 7. Mixto | `demo-saas-mixed@donqerlab.test` | 1 | Las cuatro anteriores |
| 8. Sin accesos | `demo-no-access@donqerlab.test` | 0 | `[]` |

Ninguno de los ocho perfiles anteriores recibe `saas.access.manage` ni
`saas.clinical_reasons.classify_impact`. Su matriz permanece intacta.
`manage` sólo permite activar/desactivar motivos; no administra concesiones SaaS.

Hay además dos identidades auxiliares del procedimiento FE-EXC-01 existente:
`exc-demo-a` inicializa `saas.access.manage` mediante `SaasAccessManager::bootstrap()`;
A concede a B las cuatro capacidades clínicas y a los perfiles sus capacidades
exactas mediante `grant()`. B conserva la autoría de las 56 versiones. Sus
contraseñas son aleatorias, no se publican y no forman parte de los ocho perfiles.
Estas facultades quedan limitadas a la vida de la base desechable; no se desactivan
triggers ni se escriben asignaciones directamente. El bootstrap temporal restaura
la configuración anterior al terminar, incluso ante errores.

## Consumo frontend

API: `http://localhost:8086/api/v1`. Usar frontend en `http://localhost:5173` y
cookies con credenciales.

1. Obtener CSRF con `GET http://localhost:8086/sanctum/csrf-cookie`.
2. Enviar `POST /api/v1/auth/login` con `email`, `password` y `X-XSRF-TOKEN`.
3. Consultar `GET /api/v1/auth/me`: `data.saas_capabilities` contiene únicamente
   los códigos de la tabla, o `[]`; no inferir capacidades desde roles tenant.
4. Sara: usar el perfil 1 y `GET /api/v1/auth/laboratories` para alternar los dos
   laboratorios. Enviar el ID elegido en `X-Laboratory-ID`. Consultar
   `/laboratory/clinical-reasons/global` y `?page=2` (50 y 1 registros).
5. Para adoptar v1 de `demo_exc_003` y actualizar a v2, seguir los payloads de
   [FE-EXC-01](FE-EXC-01-datos-demo.md#consumo-por-sara). El catálogo comienza sin
   adopciones; las adopciones se realizan explícitamente por laboratorio.
6. Atziry: cerrar sesión al cambiar de perfil; verificar `/auth/me` para los ocho
   usuarios. Consulta, creación, publicación y activación tienen facultades
   independientes. Los perfiles sin laboratorio siguen autenticándose y accediendo
   a su superficie SaaS autorizada. El mixto tiene además acceso tenant.

Los roles tenant DEMO sólo permiten consultar, adoptar, actualizar y leer historial
clínico; no habilitan todos los módulos del laboratorio. El seeder conserva los
estados y el impacto sin clasificar; las declaraciones posteriores hechas por
Cristian se preservan en las repeticiones. El entorno no prepara escenarios de
diagnóstico clínico ni sustituye el dataset general de `DatabaseSeeder`.

## Perfil adicional para Cristian: clasificación de impacto

Usuario sintético activo: **`demo-saas-impact@donqerlab.test`**. Usa el mismo
secreto DEMO original y no tiene membresías de laboratorio. Preparar o ampliar
el entorno con `./deploy/demo-exc/development.sh`; cerrar la sesión anterior e
iniciar sesión con este correo, siguiendo el flujo CSRF/Sanctum descrito arriba.
No utilizar la cuenta real de Cristian para estas pruebas.

`GET /api/v1/auth/me` devuelve exactamente:

```json
{
  "data": {
    "saas_capabilities": [
      "saas.clinical_reasons.classify_impact",
      "saas.clinical_reasons.view"
    ]
  }
}
```

Este fragmento muestra sólo el campo relevante de la respuesta. Las facultades
se comprobaron contra las rutas y los servicios reales:

| Capacidad | Por qué se asigna |
| --- | --- |
| `saas.clinical_reasons.classify_impact` | Consultar impacto actual, historial de declaraciones y registrar clasificaciones. Es suficiente si ya se conocen los IDs. |
| `saas.clinical_reasons.view` | Consultar catálogo, detalle y versiones para seleccionar y leer el motivo que se clasificará; también permite consultar sus eventos. El contrato no la hereda desde `classify_impact`. |

No requiere `create`, `publish`, `manage`, `saas.access.manage` ni permisos tenant.
No enviar `X-Laboratory-ID` para esta superficie SaaS. La identidad auxiliar A
(`exc-demo-a`) concede ambas facultades al usuario nuevo mediante `grant()`, con
la misma procedencia, auditoría, transacción y guardas existentes; no hay
inserciones directas de concesiones ni autoconcesión. Las credenciales aleatorias
de A/B continúan privadas y no se cambian para iniciar sesiones manuales.

### Flujo de prueba

1. Consultar `GET /api/v1/platform/clinical-reasons` (paginación de 50) y elegir,
   por ejemplo, el código `demo_exc_003`; obtener su `id` como `{reason}`.
2. Consultar `GET /api/v1/platform/clinical-reasons/{reason}/versions`; elegir
   el `id` de la versión como `{version}` (no su `number`).
3. Consultar `GET /api/v1/platform/clinical-reasons/{reason}/versions/{version}/impact`.
   Sin declaración, retorna `classification: "unclassified"` y `declaration: null`.
4. Registrar `POST /api/v1/platform/clinical-reasons/{reason}/versions/{version}/impact/declarations`
   con el siguiente cuerpo y la cabecera CSRF de la sesión:

```json
{
  "expected_revision": 0,
  "classification": "non_restrictive",
  "justification": "FICTICIO: prueba de clasificación de impacto de Cristian."
}
```

Devuelve 201, `data.revision`, `audit_id` y `operation_id`. Los valores válidos son
`unclassified`, `non_restrictive` y `restrictive`; la justificación es obligatoria,
no vacía y de máximo 2000 caracteres. `expected_revision` debe ser un entero JSON:
0 sin declaración o la revisión actual obtenida en `data.declaration.revision`.
Una revisión desactualizada devuelve 409: recargar antes de corregir. Sólo se
aceptan los tres campos documentados. Las correcciones agregan declaraciones,
conservando el historial; una declaración restrictiva puede crear obligaciones
en laboratorios que hayan adoptado esa versión.

Consultar el historial con
`GET /api/v1/platform/clinical-reasons/{reason}/versions/{version}/impact/declarations`.
Crear motivos, publicar versiones, activar/desactivar y administrar concesiones
deben responder 403. Los ocho perfiles originales deben recibir 403 al leer o
registrar impacto, incluso `demo-saas-full` y `demo-saas-mixed`.

### Revocar mediante la API oficial

Un operador con una **sesión ya autorizada** con `saas.access.manage` debe ejecutar
este flujo exclusivamente sobre el usuario sintético en la base desechable. El
clasificador no puede revocarse ni concederse facultades por sí mismo. Esta guía
no concede administración a ninguna cuenta ni publica las credenciales de A/B.

1. `GET /api/v1/platform/access/users`: localizar por correo exacto
   `demo-saas-impact@donqerlab.test` y obtener `{user}`; paginar si hace falta.
2. `GET /api/v1/platform/access/users/{user}/capabilities`: seleccionar la asignación
   activa cuyo `capability_code` sea `saas.clinical_reasons.classify_impact` y
   obtener su `id` como `{assignment}` y su `revision` actual.
3. `POST /api/v1/platform/access/users/{user}/capabilities/{assignment}/revoke`,
   con cookies de esa sesión administrativa, `X-XSRF-TOKEN` y cuerpo:

```json
{
  "revision": 1,
  "reason": "FICTICIO: fin de las pruebas de clasificación de Cristian."
}
```

Usar la revisión leída, como entero JSON. Devuelve 200; una revisión obsoleta
produce 409 y exige recargar la asignación. En la sesión del clasificador,
`/auth/me` pasa inmediatamente a `["saas.clinical_reasons.view"]` y los tres
endpoints de impacto devuelven 403. Si también se desea retirar la lectura,
revocar por separado la asignación `saas.clinical_reasons.view` con el mismo flujo;
`/auth/me` devolverá `[]`.

El seeder **no restaura** facultades revocadas: una repetición detecta la
asignación alterada y rechaza la transacción sin reparar datos. Para volver al
estado inicial usar un entorno DEMO desechable nuevo, nunca modificar PostgreSQL
ni resetear credenciales. La revocación por API se valida automáticamente con la
identidad auxiliar autorizada, sin habilitar su login manual.

## Usuario adicional para pruebas funcionales: `demo-superadmin`

**Exclusivo para desarrollo local y pruebas aisladas.** Correo:
**`demo-superadmin@donqerlab.test`**. Está activo y usa el secreto elegido mediante
`./deploy/demo-exc/development.sh`, igual que los nueve perfiles especializados.
La contraseña se obtiene del gestor autorizado del equipo; no hay valor
predeterminado y el seeder nunca cambia la contraseña de una cuenta existente.
Los nueve perfiles anteriores conservan sus capacidades, roles y membresías.

Ejecutar desde la raíz del repositorio:

```bash
./deploy/demo-exc/development.sh
```

El comando prepara o amplía únicamente el entorno desechable autorizado. El
usuario no se instala en Laradock habitual, bases compartidas ni producción. No
exportar sus registros, hashes, secretos ni sesiones a un entorno real. Su nombre
no representa un rol global ni una excepción de autorización en tiempo de ejecución.

### Capacidades SaaS exactas

El catálogo real vigente contiene seis capacidades. Todas son concedibles por
otra identidad con `saas.access.manage`; ninguna necesita una excepción ni está
excluida de este perfil. `/api/v1/auth/me` devuelve en `data.saas_capabilities`:

```json
[
  "saas.access.manage",
  "saas.clinical_reasons.classify_impact",
  "saas.clinical_reasons.create",
  "saas.clinical_reasons.manage",
  "saas.clinical_reasons.publish",
  "saas.clinical_reasons.view"
]
```

Incluye consultar el catálogo, crear motivos, publicar versiones, activar o
desactivar, declarar impacto y administrar concesiones mediante `/platform/access`.
A concede las seis capacidades mediante `SaasAccessManager::grant()` y conserva
la auditoría SaaS. No se escribe directamente en las tablas de concesiones. La
cuenta no puede autoconcesionarse capacidades, aunque tenga `saas.access.manage`;
continúan vigentes la protección del último administrador y las reglas de revisión.
El seeder compara la lista explícitamente revisada con todo `saas_capabilities` y
rechaza un catálogo distinto: una capacidad futura exige revisión, sin concesión
automática por comodines ni omisión silenciosa.

### Laboratorios, rol y permisos efectivos

Posee membresía activa con rol **`owner`** en los dos laboratorios existentes:

| Laboratorio | NIT | Predeterminado |
| --- | --- | --- |
| DEMO FICTICIO EXC | `DEMO-EXC-01` | Sí |
| DEMO FICTICIO SEGUNDO | `DEMO-DEV-02` | No |

El `owner` de estos laboratorios DEMO tiene exactamente cinco permisos:
`clinical_reasons.global.view`, `clinical_reasons.adoptions.view`,
`clinical_reasons.adoptions.create`, `clinical_reasons.adoptions.update` y
`clinical_reasons.adoptions.history`. **No se amplía ese rol compartido.** La
matriz general de `RbacCatalog::rolePermissions()['owner']` tiene 75 permisos,
excluye facultades sensibles y tampoco se modifica ni se aplica a estos roles DEMO.

Para el nuevo usuario se complementan los cinco permisos con los **90 permisos
restantes de `RbacCatalog::PERMISSIONS`**, como permisos directos de Spatie,
limitados al usuario y a cada laboratorio. Se reutiliza el mecanismo de
aprovisionamiento de `AdminDemoPermissionSeeder` y de los seeders de validación y
cierre clínico: `Permission::findOrCreate()` y `User::givePermissionTo()`. No se
actualizan pivotes con SQL, no se modifica el catálogo ni se habilita un bypass de
Gate. Dos eventos `demo_development.permissions_granted` registran laboratorio,
usuario destinatario, lista concedida y origen del seeder; el actor nulo representa
aprovisionamiento de sistema, no una autoconcesión de la cuenta. `DemoDataset`
registra su procedencia y verifica el resultado exacto en cada repetición.

La autorización efectiva es de **95 permisos en cada laboratorio**, desglosados
abajo. Cada nombre resulta de unir el prefijo y el sufijo con un punto:

| Prefijo | Sufijos concedidos |
| --- | --- |
| `branches` | `view` |
| `patients` | `view`, `create`, `update`, `change_status` |
| `doctors` | `view`, `create`, `update`, `change_status` |
| `laboratory_areas` | `view`, `create`, `update`, `change_status` |
| `sample_types` | `view`, `create`, `update`, `change_status` |
| `laboratory_exams` | `view`, `create`, `update`, `change_status`, `create_draft`, `edit_draft`, `publish`, `import_template` |
| `commercial_clients` | `view`, `create`, `update`, `change_status` |
| `price_lists` | `view`, `create`, `update`, `change_status`, `set_default` |
| `exam_prices` | `view`, `manage` |
| `commercial_price_assignments` | `view`, `manage` |
| `pricing` | `resolve` |
| `orders` | `view`, `create`, `add_exam`, `remove_exam`, `manage_discount`, `change_status`, `clinical_finalize`, `exams.clinical_finalize`, `clinical_exceptions.request`, `clinical_exceptions.approve`, `clinical_exceptions.view`, `clinical_finalization.view`, `start_exam` |
| `results` | `view`, `capture`, `edit`, `view_history`, `validate`, `rectify`, `validation_history`, `validation_policy.manage`, `professionals.manage` |
| `reports` | `view_history`, `issue`, `download`, `settings.view`, `settings.edit`, `delivery_policy.view`, `delivery_policy.manage`, `delivery.evaluate`, `delivery_history.view`, `delivery.prepare`, `delivery.confirm`, `delivery_exceptions.authorize`, `delivery_exceptions.view` |
| `samples` | `view`, `collect`, `receive`, `evaluate`, `associate` |
| `users` | `view`, `create`, `assign_role`, `change_status` |
| `roles` | `view` |
| `clinical_reasons` | `global.view`, `adoptions.view`, `adoptions.create`, `adoptions.update`, `adoptions.history`, `review_impact`, `local.view`, `local.create`, `local.publish`, `local.manage`, `local.history` |

Estos permisos adicionales se obtienen ejecutando el aprovisionamiento autorizado
anterior, sin cambios a la matriz de producción. La API de roles permite asignar
roles existentes; no ofrece un endpoint genérico para conceder permisos directos.
No utilizarla como sustituto del aprovisionamiento DEMO ni escribir permisos en SQL.

### Uso y limitaciones

1. Cerrar la sesión anterior, obtener `/sanctum/csrf-cookie` e iniciar sesión por
   `POST /api/v1/auth/login` con el correo y el secreto DEMO, cookies y CSRF.
2. Verificar identidad y las seis capacidades en `GET /api/v1/auth/me`.
3. Consultar `GET /api/v1/auth/laboratories`: devuelve las dos membresías activas.
   Se mantiene el contrato existente: `/auth/me` no incorpora membresías ni roles.
4. Seleccionar un ID devuelto y enviarlo en `X-Laboratory-ID`; consultar
   `GET /api/v1/auth/authorization`, que devuelve ese laboratorio, `roles: ["owner"]`
   y los 95 nombres de permisos ordenados. Para cambiar de laboratorio enviar
   el otro ID en la siguiente petición; no es necesario volver a iniciar sesión.
5. Usar las operaciones SaaS en `/platform/...` y las de laboratorio con el
   contexto elegido. El aislamiento tenant, las membresías activas y la
   suscripción vigente se siguen comprobando en cada solicitud.

Tener todos los permisos no elimina requisitos de datos, revisiones, políticas ni
separación de funciones. En particular:

- No puede aprobar su propia solicitud de excepción clínica ni autorizarse a sí
  mismo como profesional. Se requiere otra identidad sintética legítimamente
  autorizada cuando el servicio exige una segunda persona; no se elevan los nueve
  perfiles existentes para resolverlo.
- Con `validation_policy=professional_required`, validar o rectificar requiere
  además un registro profesional autorizado en ese laboratorio. El seeder no
  crea habilitaciones profesionales ni cambia la política. El flujo oficial es
  registrar mediante `POST /laboratory/validation-professionals` y autorizar con
  **otra identidad habilitada** mediante
  `POST /laboratory/validation-professionals/{professional}/authorize`. Estos
  endpoints requieren `results.professionals.manage` y el contexto tenant.
- No crea sucursales, pacientes, órdenes, muestras, resultados o reportes de ejemplo. El
  catálogo clínico DEMO conserva sus 51 motivos y 56 versiones. Preparar los datos
  necesarios mediante las operaciones oficiales; los servicios pueden responder
  409 o 422 mientras falten requisitos de negocio. En particular, crear órdenes
  requiere una sucursal activa; este dataset no la incluye y el contrato actual
  sólo expone consulta de sucursales (`GET /branches/active`). Prepararla requiere
  un fixture o seeder sintético autorizado en la base desechable; no ampliar
  permisos ni ejecutar `DatabaseSeeder` sobre este dataset para resolverlo.
- Estos laboratorios sólo aprovisionan el rol `owner`. La administración de
  usuarios no crea definiciones de roles: utilizar otros roles requiere prepararlos
  mediante un aprovisionamiento sintético autorizado. No ejecutar el seeder general
  `RolePermissionSeeder` sobre este entorno: reemplazaría la matriz compartida del
  `owner` y alteraría los perfiles especializados.
- No accede a laboratorios ajenos. Una membresía, cuenta, laboratorio o suscripción
  inactiva puede impedir el acceso aunque conserve permisos.

Las repeticiones son idempotentes. Una capacidad revocada o un permiso directo,
rol o membresía alterado provoca rechazo y rollback; no se restaura automáticamente.
Para revocar una capacidad usar el flujo de API oficial de la sección de Cristian,
seleccionando ahora `demo-superadmin@donqerlab.test`. Si se revoca su
`saas.access.manage`, el actor A conserva la administración; el seeder no la repone.

## Restricciones e idempotencia

Sólo se admite `local` sobre `postgres-exc-demo/donqer_exc_demo`, usuario
`donqer_exc_demo`, puerto 5432; o `testing` sobre
`postgres-testing/donqer_lab_testing`, usuario homónimo. Se verifican configuración
efectiva, conexión `pgsql`, identidad de BD/usuario y ausencia de URL/réplicas.
Producción, otros entornos, bases habituales y conexiones alternativas se rechazan.
La ejecución en una base compartida ya inicializada **no es compatible** con el
bootstrap autorizado actual. No se crea un bypass para hacerlo posible.

La primera instalación exige ausencia de usuarios, laboratorios, motivos y
concesiones. Una instalación FE-EXC-01 registrada puede ampliarse sin recrearla.
La procedencia se acredita con `DemoDataset`, nunca sólo con prefijos o correos.
Una transacción exterior y el lock administrativo existente serializan el conjunto.
Una colisión o fallo de auditoría revierte toda la ejecución. Datos ajenos que se
incorporen después se conservan; ningún seeder elimina registros.

Resultado limpio: **12 usuarios** (9 perfiles especializados + superadmin + A/B),
**2 laboratorios**, **5 membresías**, **25 concesiones SaaS**, **51 motivos**,
**56 versiones**, **81 eventos SaaS** (61 iniciales + 20 concesiones de perfiles),
**180 asignaciones directas tenant** (90 por laboratorio, sólo al superadmin) y
**2 eventos tenant** de aprovisionamiento de permisos del superadmin.
Las repeticiones no añaden usuarios, membresías, roles, concesiones, motivos,
versiones ni eventos. Se conservan adopciones posteriores. Una revocación, cambio de
matriz, rol, membresía, vigencia de suscripción o historia clínica se informa y
rechaza sin reparación ni restauración automática.

El punto de entrada es `DemoDevelopmentSeeder`, deliberadamente fuera de
`DatabaseSeeder`. Internamente ejecuta `DemoClinicalReasonsSeeder`,
`DemoSaasUsersSeeder` y `DemoLaboratorySeeder`, en ese orden. Los dos últimos
requieren las dependencias DEMO anteriores y conservan su propia guarda de destino.
`demo:clinical-reasons` conserva su confirmación y comportamiento anterior,
compartiendo ahora `DemoClinicalReasonProvisioner` con los seeders.

La BD del Compose usa `tmpfs`: detener/recrear PostgreSQL pierde sus datos. Para
retirar exclusivamente este entorno desechable:

```bash
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml down
```

## Validación

```bash
docker compose -p donqer-demo-seeders-validation -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/DemoDevelopmentSeederTest.php tests/Feature/ClinicalReasons tests/Feature/SaasAccess tests/Feature/Api/V1/Auth
```

La batería comprueba login de los diez usuarios por sesión Sanctum,
`/auth/me` con matrices exactas y arrays vacíos, membresías, dos laboratorios,
autorización de consulta SaaS, revocación inmediata y no restauración, paginación,
adopción/actualización por ambos laboratorios y el mixto, idempotencia tras adopción,
rechazo de producción/conexiones/secretos inválidos, preservación de usuarios reales,
ampliación de FE-EXC-01 y rollback completo ante fallo de auditoría.

Validación previa de los ocho perfiles, ejecutada el **2026-10-28**:

- Regresión ampliada: **284 pruebas, 9.043 aserciones, sin fallos**; incluye nueve
  pruebas nuevas del seeder y la regresión previa de FE-EXC-01.
- HTTP real en el Compose dedicado: los ocho perfiles completaron CSRF, login,
  `/auth/me` con capacidades exactas, consulta de membresías y logout; login sin
  cabecera CSRF rechazado con 419 y consulta SaaS permitida/rechazada según matriz.
- Procedimiento completo ejecutado y seeder repetido en PostgreSQL 17 desechable.
  El entorno HTTP de validación se retiró al terminar; no se publica su secreto.
- Laravel Pint aplicado; sintaxis Bash y `git diff --check` correctos.

Validación del perfil de impacto, **2026-10-28**:

- Pruebas del seeder: **11 pruebas, 224 aserciones, sin fallos**.
- Regresión conjunta de seeders, clínica, acceso SaaS y autenticación:
  **286 pruebas, 9.115 aserciones, sin fallos** (PHP 8.4.25, PHPUnit 12.5.35).
- Login Sanctum y `/auth/me` exacto para nueve perfiles; lectura del catálogo,
  detalle, versiones y eventos; declaración e historial de impacto; 409 por
  revisión obsoleta; 403 en creación, publicación, activación y acceso SaaS.
- Los ocho perfiles originales conservan sus capacidades y reciben 403 en los
  tres endpoints de impacto. Concesiones del nuevo perfil auditadas por A.
- Revocación por API oficial, efecto inmediato y rechazo del seeder sin restaurar
  la capacidad; idempotencia también después de clasificar. Fallo de auditoría
  del perfil nuevo revierte el conjunto.
- Ampliación real desde el seeder original de ocho perfiles en otra base
  desechable: usuarios, concesiones, auditoría y catálogo anteriores conservados,
  perfil nuevo con las dos capacidades exactas y repetición idempotente.
- PostgreSQL 17 desechable en el proyecto exclusivo `donqer-impact-validation`;
  no se ejecutó el aprovisionamiento sobre bases compartidas ni producción.
  Ambos entornos de validación se retiraron al terminar; el usuario se crea para
  uso frontend al ejecutar `./deploy/demo-exc/development.sh` con el secreto DEMO.
- Laravel Pint aplicado a los archivos PHP modificados; sintaxis Bash y
  `git diff --check` correctos.

Validación del superadmin, **2026-10-28**:

- Regresión ampliada: **392 pruebas, 9.930 aserciones, sin fallos**, en PHP 8.4.25
  y PHPUnit 12.5.35. Incluye los 18 casos del seeder y las regresiones de clínica,
  SaaS, autenticación, RBAC, aislamiento tenant y separación de funciones.
- Se añadieron siete pruebas al seeder (18 en total): catálogo SaaS completo,
  operaciones SaaS por API, concesión y revocación auditadas, autoconcesión 403,
  permisos efectivos exactos, navegación entre ambos laboratorios con la misma
  sesión Sanctum, creación y aislamiento de pacientes, roles originales intactos,
  autoautorización profesional 403, membresía/cuenta/suscripción inactivas,
  laboratorio ajeno, revocaciones sin reparación, colisión con identidad ajena
  y rollback ante fallo de auditoría de permisos.
- Ampliación desde los nueve perfiles del commit anterior en un segundo
  PostgreSQL 17 desechable: todos los registros originales comparados en 14 tablas
  se conservaron, incluido el hash de las credenciales, roles y sus permisos.
  El nuevo usuario obtuvo seis capacidades, dos membresías `owner` y 95 permisos
  en cada laboratorio. Una segunda ejecución no produjo cambios.
- Los 95 nombres de permisos de la tabla documental se compararon contra el
  catálogo fuente: coinciden exactamente. Laravel Pint, sintaxis Bash y
  `git diff --check` verificados.

Ambos proyectos de validación (`donqer-superadmin-validation` y
`donqer-superadmin-upgrade-validation`) utilizaron PostgreSQL desechable y se
retiraron al terminar. No se modificaron bases compartidas ni producción. El
usuario queda disponible para uso frontend al ejecutar el script de preparación
con el secreto DEMO original.

Comando de regresión ampliada, desde la raíz del repositorio:

```bash
docker compose -p donqer-superadmin-validation -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result \
  tests/Feature/DemoDevelopmentSeederTest.php \
  tests/Feature/ClinicalReasons tests/Feature/SaasAccess \
  tests/Feature/Api/V1/Auth tests/Feature/Authorization \
  tests/Feature/ClinicalValidation/ValidationSecurityTest.php \
  tests/Feature/ClinicalFinalization/ClinicalResolutionTest.php \
  tests/Feature/ReportDelivery/ReportDeliveryTest.php
```

Archivos modificados para este perfil:

| Archivo | Cambio |
| --- | --- |
| `laravel/database/seeders/DemoSaasUsersSeeder.php` | Décimo perfil con seis capacidades revisadas y guarda ante cambios del catálogo. |
| `laravel/database/seeders/DemoLaboratorySeeder.php` | Dos membresías owner y permisos directos exclusivos del superadmin, con procedencia, auditoría y verificación exacta. |
| `laravel/tests/Feature/DemoDevelopmentSeederTest.php` | Autenticación, autorización, aislamiento, idempotencia y seguridad del perfil nuevo; regresión de los nueve anteriores. |
| `docs/sprint-2/DEMO-development-seeders.md` | Credenciales, capacidades, permisos efectivos, límites y evidencia. |
| `README.md` | Entrada al perfil funcional en la guía DEMO. |

## Archivos del aprovisionamiento original

| Archivo | Cambio |
| --- | --- |
| [DemoDevelopmentSeeder.php](../../laravel/database/seeders/DemoDevelopmentSeeder.php) | Nuevo: orquestación atómica explícita. |
| [DemoClinicalReasonsSeeder.php](../../laravel/database/seeders/DemoClinicalReasonsSeeder.php) | Nuevo: catálogo existente mediante componente compartido. |
| [DemoSaasUsersSeeder.php](../../laravel/database/seeders/DemoSaasUsersSeeder.php) | Nuevo: matriz confirmada y concesiones por servicio. |
| [DemoLaboratorySeeder.php](../../laravel/database/seeders/DemoLaboratorySeeder.php) | Nuevo: segundo laboratorio, membresías y roles registrados. |
| [DemoClinicalReasonProvisioner.php](../../laravel/database/seeders/DemoClinicalReasonProvisioner.php) | Nuevo: extracción del aprovisionamiento FE-EXC-01, sin cambiar dominio. |
| [ProvisionDemoClinicalReasons.php](../../laravel/app/Console/Commands/ProvisionDemoClinicalReasons.php) | Adaptado: delega en el componente compartido y conserva confirmación. |
| [development.sh](../../deploy/demo-exc/development.sh) | Nuevo: preparación y secreto con entrada oculta. |
| [DemoDevelopmentSeederTest.php](../../laravel/tests/Feature/DemoDevelopmentSeederTest.php) | Nuevo: regresión funcional y de seguridad de seeders. |
| [README.md](../../README.md) | Actualizado: entrada al procedimiento para frontend. |
| [Esta guía](DEMO-development-seeders.md) | Nueva: cuentas, instrucciones, restricciones y evidencia. |
