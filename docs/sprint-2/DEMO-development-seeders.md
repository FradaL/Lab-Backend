# DEMO de desarrollo — FE-EXC-01 y FE-SaaS-02

## Ejecución para Sara, Atziry y Cristian

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
compartirla con Sara/Atziry/Cristian mediante ese canal autorizado.

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

Resultado limpio: **11 usuarios** (8 perfiles originales + clasificador + A/B), **2 laboratorios**,
**3 membresías**, **19 concesiones SaaS**, **51 motivos**, **56 versiones**,
**75 eventos SaaS** (61 anteriores + 14 concesiones de perfiles).
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

La batería comprueba login de los nueve usuarios por sesión Sanctum,
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

## Archivos entregados

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
