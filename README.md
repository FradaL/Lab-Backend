# Donqer Lab Backend

## Excepción comercial exclusiva — BE-D02-09, 2026-11-10

[Contrato, migración, pruebas y límites](docs/sprint-2/BE-D02-09-excepcion-preparacion.md): autorización con vencimiento fijo máximo de 15 minutos, vínculo irreversible a una preparación y emisión exactas, revalidación en descarga/confirmación y consumo único. Cambios locales sobre `c91dc31`, sin commit; 57 pruebas de entrega / 240 aserciones satisfactorias en PostgreSQL desechable. La regresión ampliada conserva nueve incidencias reproducidas en el commit base, detalladas en el informe. Sin aplicación a la base habitual, frontend, Trello, push ni despliegue. Los cortes inferiores conservan su evidencia histórica.

Historial: 2026-11-10, solicitud expresa del usuario; implementación y documentación por el agente. D02 1.2 y D12 1.1 conservan sus aprobaciones.

## Integración de anulaciones y devoluciones — 2026-11-06

Resolución de conflictos entre `f75c844` (BE11) y `0890873` (`develop`, BE12), conservando las dos rutas, validaciones, evidencia y auditoría. Se corrige también la incompatibilidad de las migraciones: soporte SQL compartido y migración correctiva `000004` para bases con las originales ejecutadas. Regresión final: 807 casos / 5.162 aserciones, 806 satisfactorios y 1 omisión previa, cero fallos/errores. [Cambios, pruebas y activación](docs/sprint-2/BE-D02-11-12-integracion-migraciones.md). Sin aplicación a la base habitual, commit, push ni despliegue. Los apartados inferiores conservan sus cortes anteriores.

## Anulaciones BE-D02-11 — 2026-11-06

[Implementación local, migración, pruebas y límites](docs/sprint-2/BE-D02-11-anulaciones-trazables.md), sobre `105d1f1` más cambios sin commit. Anulación completa e idempotente con `payments.void`, cobro original inmutable, motivo y auditoría/revisión atómicas; bloqueo del neto negativo. No genera devoluciones ni integra entrega. Requiere migración adicional en el entorno que adopte el cambio; sin aplicación a la base habitual, Trello ni despliegue. Los cortes anteriores se conservan.
## Devoluciones manuales de excedente — BE-D02-12, 2026-11-06

Implementación local en `7fd3aab`: `POST /api/v1/laboratory-orders/{order}/refunds`, con `payments.refund`, monto positivo limitado al excedente actual, motivo/medio/referencia, actor/fecha del servidor, idempotencia y auditoría/revisión atómicas. Migración aditiva con guardas PostgreSQL y resumen BE06 actualizado por los movimientos confirmados. [Contrato, pruebas, activación y límites](docs/sprint-2/BE-D02-12-devoluciones-excedente.md). Sin aplicación de migraciones a la base habitual, frontend, cambios de Trello, push ni despliegue; anulación, crédito e integración de entrega conservan sus tareas. Los cortes inferiores se preservan.

## Versionado de Caja — 2026-11-06

Por solicitud expresa de Daniel Lara («haz commit porfavor»), se versionan conjuntamente BE-D02-05 y BE-D02-06 con su documentación y pruebas. Las menciones a cambios «sin commit» de los informes inferiores conservan el corte previo al versionado. No se realiza push, despliegue ni aplicación de migraciones a la base habitual. Evidencia: BE05, 699 pruebas satisfactorias y 1 omisión previa; BE06, 625 satisfactorias y 1 omisión previa, sin sumar casos repetidos entre baterías.

## Resumen y movimientos de Caja — BE-D02-06, 2026-11-06

Implementación local sobre BE05 sin commit: resumen de total/cobros/devoluciones/neto/saldo/excedente y movimientos paginados, con `payments.view`, importes exactos y lectura coherente bajo bloqueo compartido de orden. Las lecturas no generan revisión ni movimientos. [Contrato, pruebas y límites](docs/sprint-2/BE-D02-06-resumen-movimientos.md). Validación: 626 casos / 4.078 aserciones, 625 satisfactorios y 1 omisión previa en PostgreSQL aislado. Sin migraciones nuevas ni despliegue. Crédito y escritores de anulación/devolución siguen en sus tareas.

## Cobros idempotentes — BE-D02-05, 2026-11-06

Implementación local sobre `941acf3`, sin commit: endpoint de cobro con `payments.create`, importes exactos, idempotencia persistida, auditoría atómica y restricciones PostgreSQL. Inicializa revisión financiera al terminar la creación de órdenes nuevas e integra cambios reales de total. Preserva órdenes anteriores sin inventar estado financiero. [Contrato aplicado, activación, pruebas y límites](docs/sprint-2/BE-D02-05-cobros-idempotentes.md). Resumen, anulaciones, devoluciones, crédito e integración de entrega continúan en sus tareas. Validación: 700 casos / 5.301 aserciones, 699 satisfactorios y 1 omisión previa, en PostgreSQL aislado. Sin despliegue.

## Política de pago conservada por orden — BE-D02-03, 2026-11-06

La creación guarda la política explícita del laboratorio y su procedencia en un snapshot inmutable, dentro de la transacción de cabecera, líneas y descuento. Creación, detalle y listado devuelven `delivery_policy`; las órdenes anteriores permanecen con null. Sin selección explícita se devuelve 409; una procedencia inconsistente devuelve 503, sin crear una orden parcial. [Migración, evidencia, pruebas y límites de integración financiera/entrega](docs/sprint-2/BE-D02-03-politica-conservada-orden.md). La migración se verifica únicamente en PostgreSQL aislado en esta entrega; su aplicación en otros entornos requiere preflight.

Backend SaaS para laboratorios clínicos Donqer Lab. Incluye Laravel, PostgreSQL, Laradock, autenticación SPA con Sanctum y módulos tenant-aware de pacientes y médicos. Spatie Laravel Permission está preparado para una etapa posterior.

## BE-D02-04 — Permisos de Caja (implementación local, 2026-11-05)

El catálogo registra `payments.view`, `payments.create`, `payments.void`, `payments.refund` y `payments.credit.authorize`. Ninguno se concede por defecto a los seis roles, incluido `owner`. No se reemplazan matrices existentes al reprovisionar. La respuesta `GET /api/v1/auth/authorization` conserva su estructura y expone los permisos efectivos; crédito se expone cuando existe el permiso efectivo, independientemente del nombre de rol.

Los futuros servicios de Caja deben ejecutar su lectura/escritura dentro de `CashAuthority::run($laboratory, $actor, $permission, $operation)`, con recursos consultados explícitamente por laboratorio. El autorizador abre una transacción, verifica laboratorio/usuario/membresía/suscripción activos, relee permisos sin caché y conserva bloqueos compartidos de las asignaciones durante la operación. Cada operación, incluido crédito, pide exclusivamente su permiso efectivo específico; no exige un nombre de rol. Los futuros endpoints deben conservar el pipeline SaaS/sesión y verificar pertenencia de orden y movimientos. El autorizador no sustituye los controles de sesión HTTP ni los bloqueos financieros definidos en EB-001.

La administración existente de matrices de roles permite asignar explícitamente consulta/cobro/anulación/devolución/crédito con revisión y auditoría; `owner` y `administrator` conservan sus matrices protegidas. Para asignaciones directas se añade un comando que exige actor con `roles.permissions.manage`, destinatario activo del laboratorio y motivo:

```sh
# Sustituir los tres ID por el laboratorio, actor autorizado y destinatario elegidos.
php artisan cash:permission LAB_ID ACTOR_ID USER_ID payments.view --reason='Responsabilidad de consulta'
php artisan cash:permission LAB_ID ACTOR_ID USER_ID payments.view --revoke --reason='Retiro de facultad'
```

El comando modifica un solo permiso directo y audita `cash.permission_granted`/`cash.permission_revoked` de forma atómica. Repetir el mismo estado no duplica auditorías. Revocar una asignación directa conserva cualquier permiso heredado de roles: esas concesiones se retiran desde su matriz. Crédito puede concederse a cualquier miembro activo por rol o permiso directo. Retirar una concesión de rol bloquea el crédito si no queda otra concesión efectiva; un permiso directo vigente continúa habilitándolo. No se ejecutó este comando sobre usuarios reales.

Para registrar el catálogo en una instalación se utiliza el aprovisionamiento existente (`RolePermissionSeeder`/`LaboratoryRoleProvisioner`), sin concesiones financieras automáticas. No se ejecutaron seeders ni migraciones sobre bases compartidas. Las rutas financieras de EB-001 todavía corresponden a BE-D02-05/06/07/11/12 y deberán integrar este autorizador; no se publica aquí una API de cobros ni se habilita entrega.

Validación anterior a la revisión de crédito por permiso efectivo: 71 pruebas / 792 aserciones satisfactorias de Caja, catálogo, administración, concurrencia, despliegue de matrices y autorización/OpenAPI; después, un caso adicional de concesión/revocación de crédito a administrador pasó con 1 prueba / 2 aserciones. PostgreSQL 17 temporal y PHP 8.4.25, proyecto Compose `donqer-be-d02-04`, sin bases compartidas. Pint y `git diff --check` satisfactorios. Se ajustaron las expectativas del owner al excluir Caja y un contador desactualizado de rutas protegidas (160 → 167, sin añadir rutas). Cambios locales sobre backend `423ae6b`, sin commit ni despliegue.

La revisión funcional de Daniel Lara del 2026-11-05 elimina la restricción de crédito por nombre de rol (D02 1.2 y contrato de Caja 1.1). Validación de esta revisión: 66 pruebas / 726 aserciones satisfactorias (Caja, revocación concurrente, administración de roles, catálogo y autorización/OpenAPI) en PostgreSQL 17 temporal y PHP 8.4.25. Contrato documental: 27 esquemas, 33 ejemplos, 10 operaciones y referencias internas verificados. Pint y diferencias sin errores.

## Estructura

```text
backend-lab/
├── laravel/   # Aplicación Laravel
└── laradock/  # Entorno local Docker
```

## Requisitos

- Git
- Docker
- Docker Compose v2.20 o posterior

PHP y Composer no son necesarios en el host: se ejecutan dentro del contenedor `workspace`.

## Configuración inicial

Desde la raíz del repositorio:

```bash
cp laradock/.env.example laradock/.env
cd laradock
docker compose build workspace php-fpm nginx postgres
docker compose up -d workspace nginx postgres
docker compose exec workspace composer install
docker compose exec workspace php artisan key:generate
docker compose exec workspace php artisan migrate
```

Los ejemplos ya incluyen la configuración local coordinada: Laravel usa el host Docker `postgres`, la base `donqer_lab` y las credenciales locales de Laradock. Los archivos `.env` contienen configuración local y no deben versionarse.

## Operación diaria

Levantar el entorno:

```bash
cd laradock
docker compose up -d workspace nginx postgres
```

Detenerlo:

```bash
cd laradock
docker compose down
```

Ejecutar Artisan, Composer, migraciones y tests:

```bash
cd laradock
docker compose exec workspace php artisan <comando>
docker compose exec workspace composer <comando>
docker compose exec workspace php artisan migrate
docker compose exec workspace php artisan db:seed
docker compose exec workspace php artisan test
```

## Motivos para excepciones del dataset de Laboratorio

El dataset general de Laboratorio (`DatabaseSeeder`) se puede preparar desde una base vacía con las migraciones aplicadas y `php artisan db:seed`, en entorno `local` o `testing`. Con `SAAS_DEMO_CLINICAL_REASON_ID` vacío, crea un motivo **local FICTICIO** de clase `terminal_candidate` en cada laboratorio sintético que necesita excepciones. Utiliza el servicio de motivos locales, mantiene versiones, auditoría y procedencia DEMO, y se puede repetir sin duplicar datos. No requiere catálogo global ni crea cuentas o facultades SaaS. Las solicitudes conservan referencia estructurada y justificación separada.

Para probar expresamente el origen global, se conserva la opción `SAAS_DEMO_CLINICAL_REASON_ID`: un operador SaaS autorizado debe preparar previamente el motivo global **FICTICIO**, activo, `terminal_candidate` y con impacto clasificado que permita su uso. Los escenarios lo seleccionan mediante el servicio de adopción. Un ID explícito inválido rechaza y revierte el dataset; no cambia silenciosamente al origen local. Tras reconstruir la base, dejar la variable vacía para el flujo local o configurar el nuevo ID efectivo para el global. Si había configuración cacheada, ejecutar `php artisan config:clear`.

Las solicitudes anteriores conservan su texto e historia, sin inferir motivos por nombre. Ver el [contrato global e histórico](docs/sprint-2/BE-EXC-02.3-I-integracion-clinica.md) y la [administración e integración local](docs/sprint-2/BE-EXC-02.4-motivos-locales.md), con versión original y revisión explícita.

Para integrar **FE-EXC-01 / CA-20**, está disponible un [procedimiento de catálogo DEMO aislado](docs/sprint-2/FE-EXC-01-datos-demo.md): crea 51 motivos y 56 versiones mediante dos identidades SaaS sintéticas autorizadas, en la base desechable `donqer_exc_demo`. Incluye un owner sintético para consumo frontend en `http://localhost:8086`, confirmación explícita, auditoría e idempotencia. Su Compose y el comando `demo:clinical-reasons` se ejecutan expresamente siguiendo esa guía.

## Entorno DEMO para frontend

Para preparar FE-EXC-01, los ocho perfiles de FE-SaaS-02 y el perfil adicional
de clasificación de impacto para Cristian, además de `demo-superadmin` para pruebas
funcionales de SaaS y laboratorio, mediante seeders:

```bash
./deploy/demo-exc/development.sh
```

Pide el secreto DEMO con entrada oculta y prepara la base desechable dedicada.
Incluye dos laboratorios, 51 motivos y 56 versiones; no se ejecuta sobre la BD
habitual de Laradock. Ver [cuentas, matriz de capacidades, ejecución y pruebas](docs/sprint-2/DEMO-development-seeders.md).

## Usuarios demo

Los seeders crean dos usuarios exclusivamente para desarrollo local:

| Nombre | Correo | Contraseña | Roles |
| --- | --- | --- | --- |
| Administrador Demo | `admin@donqerlab.test` | `password` | `administrator` |
| Recepción Demo | `reception@donqerlab.test` | `password` | `receptionist`, `cashier` |

La contraseña se almacena hasheada. Los roles y sus permisos se asignan dentro del laboratorio demo.

Ejecutar los seeders desde `laradock/`:

```bash
docker compose exec workspace php artisan db:seed
```

Dentro del contenedor `workspace`, el comando equivalente es:

```bash
php artisan db:seed
```

El seeder es idempotente y puede ejecutarse varias veces sin duplicar usuarios.

## Documentación de la API

La documentación utiliza `darkaonline/l5-swagger` 11.x y genera una especificación OpenAPI 3.1. Swagger UI está disponible en:

- http://localhost:8081/api/documentation

Para regenerar la especificación desde `laradock/`:

```bash
docker compose exec workspace php artisan l5-swagger:generate
```

Dentro del contenedor `workspace`:

```bash
php artisan l5-swagger:generate
```

Todo endpoint público o privado nuevo debe incluir su documentación OpenAPI mediante atributos PHP junto a su controlador. Debe describir el path, método, tags, resumen, parámetros o request body, validaciones, respuestas, códigos HTTP, schemas y autenticación cuando correspondan. Después de cualquier cambio se debe regenerar la especificación y verificar Swagger UI. No se deben documentar endpoints inexistentes ni mantener descripciones duplicadas.

La documentación declara autenticación mediante cookie de sesión de Sanctum para los endpoints protegidos. Donqer Lab no utiliza JWT ni entrega access tokens al frontend.

### Probar autenticación desde Swagger UI

Swagger UI reproduce el flujo de la SPA para facilitar las pruebas durante desarrollo:

1. Ejecutar `GET /sanctum/csrf-cookie`.
2. Ejecutar `POST /api/v1/auth/login` con las credenciales del usuario.
3. Swagger envía las cookies y agrega automáticamente `X-XSRF-TOKEN` desde la cookie `XSRF-TOKEN`.
4. Probar los endpoints autenticados, como `GET /api/v1/auth/me`.
5. Ejecutar `POST /api/v1/auth/logout` para cerrar la sesión.

No es necesario copiar manualmente el token CSRF. Este comportamiento pertenece únicamente al cliente Swagger UI; la SPA React utilizará el mecanismo equivalente de Axios con credentials habilitado.

## Autenticación SPA

La autenticación web utiliza Laravel Sanctum en modo stateful, el guard `web`, una sesión de Laravel y cookies HttpOnly. El flujo del cliente es:

```text
1. GET  /sanctum/csrf-cookie
2. POST /api/v1/auth/login
3. GET  /api/v1/auth/me
4. POST /api/v1/auth/logout
```

Todas las solicitudes deben enviar cookies/credentials (por ejemplo, `withCredentials: true` en Axios o `credentials: 'include'` con Fetch). Axios puede leer la cookie `XSRF-TOKEN` y enviar automáticamente el encabezado `X-XSRF-TOKEN`; no se debe almacenar ningún token en `localStorage`.

El login acepta `email` y `password` y está limitado a cinco solicitudes por minuto por combinación de correo normalizado e IP. Las credenciales incorrectas siempre producen el mismo mensaje para no revelar si un correo existe.

### Contexto de laboratorio

Después de autenticar al usuario, el frontend obtiene sus opciones mediante `GET /api/v1/auth/laboratories` y conserva localmente el laboratorio seleccionado. En cada endpoint tenant-aware debe enviar:

```http
X-Laboratory-ID: <id>
```

El laboratorio activo no se almacena en la sesión. El backend valida en cada request que el laboratorio esté activo y que el usuario tenga una membresía activa. `X-Laboratory-ID` no sustituye la autenticación y no se requiere en health, CSRF ni endpoints de autenticación.

El flujo conceptual del frontend es:

```text
GET /sanctum/csrf-cookie
        ↓
POST /api/v1/auth/login
        ↓
GET /api/v1/auth/me
        ↓
GET /api/v1/auth/laboratories
        ↓
El usuario selecciona un laboratorio
        ↓
El frontend envía X-Laboratory-ID en requests tenant-aware
```

### SaaS Request Pipeline

Las futuras funcionalidades SaaS tenant-aware deben declarar el grupo de middleware `saas`, que conserva este orden obligatorio:

```text
auth:sanctum
      ↓
laboratory.context
      ↓
subscription.active
      ↓
authorization
      ↓
feature
```

- `auth:sanctum` responde **¿quién es el usuario?** y detiene a clientes sin una sesión válida.
- `laboratory.context` responde **¿en qué laboratorio opera?** y **¿tiene una membresía activa en él?**
- `subscription.active` responde **¿ese laboratorio tiene derecho vigente a utilizar el SaaS?**
- `authorization` responderá **¿qué puede hacer el usuario dentro de ese laboratorio?** Esta capa se implementará posteriormente y no forma parte de BE-00.1.
- `feature` representa el controller o caso de uso que puede ejecutarse una vez superadas las capas anteriores.

Una vez que `laboratory.context` resuelve el tenant, controllers y services deben obtenerlo exclusivamente desde el servicio request-scoped `CurrentLaboratory`. No deben volver a leer `X-Laboratory-ID`, aceptar `laboratory_id` como fuente de contexto ni consultar `$_SERVER`: el header es input no confiable y `CurrentLaboratory` contiene el resultado ya validado.

Las rutas de health, CSRF y autenticación —incluyendo `/api/v1/auth/laboratories`— permanecen fuera del grupo `saas`; no requieren contexto de laboratorio ni suscripción.

#### Contrato para futuras entidades tenant-owned

Toda entidad de negocio propiedad de un laboratorio debe estar asociada explícitamente a `laboratory_id`, y todas sus operaciones deben respetar `CurrentLaboratory`. Esta regla aplicará, por ejemplo, a pacientes, médicos, órdenes, listas de precios y pagos cuando esas funcionalidades existan.

El cliente nunca decidirá el ownership enviando ciegamente un `laboratory_id`. En una creación, el backend deberá asignarlo desde `CurrentLaboratory->id()`. En lecturas, `Patient::find($id)` sería inseguro sin aislamiento previo: la consulta deberá restringir tanto el identificador del registro como el laboratorio actual. La misma regla se aplicará a `show`, `update`, `delete` y `restore`; un identificador de otro tenant nunca deberá permitir acceso cruzado ni revelar innecesariamente que el registro existe.

Los modelos foundation mantienen una estrategia explícita y consistente de atributos fillable. Esto incluye claves internas como `laboratory_id` y `user_id`, y estados como `status`, porque seeders y servicios internos los asignan mediante Eloquent. Esos campos no son una autorización para pasar payloads del cliente directamente a `create`, `update` o `fill`: las claves de ownership deben derivarse de `CurrentLaboratory` y los estados deben ser decididos por la capa de aplicación.

`Patient` es la primera entidad tenant-owned real. Los modelos de negocio de este tipo utilizan el concern reutilizable `BelongsToLaboratory`, que proporciona la relación `laboratory()` y un scope explícito: `Patient::forLaboratory($laboratory)`. El argumento es un modelo `Laboratory` ya validado, no un identificador procedente del cliente.

El aislamiento no utiliza Global Scope ni resuelve automáticamente `CurrentLaboratory` desde los modelos. Esto mantiene los modelos utilizables en HTTP, CLI, jobs, seeders y tests. En código tenant-aware nunca se debe iniciar una consulta de negocio sin aplicar el scope de laboratorio; la futura capa HTTP será responsable de obtener el `Laboratory` validado desde `CurrentLaboratory` y proporcionarlo explícitamente.

#### Vigencia y concurrencia de suscripciones

Las suscripciones utilizan solamente los estados `active` e `inactive`. `starts_at` determina cuándo comienza el acceso. En una suscripción normal, `ends_at` determina cuándo termina; un valor `null` representa acceso sin fecha final. En una suscripción demo, identificada por `trial_ends_at` no nulo, esa fecha determina el final aunque exista un `ends_at` posterior.

El instante exacto de `starts_at` ya permite acceso; el instante exacto de `ends_at` o `trial_ends_at` todavía lo permite. La expiración ocurre únicamente cuando la fecha de fin es anterior al instante actual. El middleware rechaza inmediatamente suscripciones futuras o vencidas, aunque el scheduler todavía no haya actualizado su estado. El comando `subscriptions:expire`, programado cada hora, cambia a `inactive` las suscripciones vencidas sin modificar suscripciones futuras ni registros históricos.

La base de datos permite como máximo una suscripción `active` por laboratorio mediante un índice único parcial. Este constraint es la última línea de defensa frente a condiciones de carrera; una futura capa administrativa deberá transformar una violación en un error de negocio apropiado, sin ocultarla silenciosamente. Las suscripciones anteriores permanecen como historial.

Las comparaciones se realizan en UTC, que es el timezone configurado para Laravel y los timestamps del backend. El timezone operativo del laboratorio no modifica la vigencia de la suscripción SaaS.

La configuración local permite el origen `FRONTEND_URL` y habilita credentials en CORS. Los hosts stateful se declaran en `SANCTUM_STATEFUL_DOMAINS`; `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY` y `SESSION_SAME_SITE` controlan la cookie. En producción se deben establecer los dominios reales mediante variables de entorno y habilitar `SESSION_SECURE_COOKIE=true` bajo HTTPS, sin hardcodearlos en el código.

## Módulo Patients

Patients pertenece directamente a un laboratorio. Sus cinco operaciones (`list`, `create`, `detail`, `update` y cambio de `status`) están protegidas por el pipeline `saas` y obtienen el tenant exclusivamente desde `CurrentLaboratory`. Un identificador inexistente o perteneciente a otro laboratorio responde `404`.

El lifecycle admite únicamente `active` e `inactive` mediante `PATCH /api/v1/patients/{patient}/status`. No existe endpoint `DELETE` ni soft deletion; el resto de datos se actualiza mediante `PATCH /api/v1/patients/{patient}`.

Rutas disponibles:

```text
GET    /api/v1/patients
POST   /api/v1/patients
GET    /api/v1/patients/{patient}
PATCH  /api/v1/patients/{patient}
PATCH  /api/v1/patients/{patient}/status
```

## Módulo Doctors

Doctors pertenece directamente a un laboratorio y expone cinco operaciones tenant-aware para listar, crear, consultar, actualizar y cambiar estado. Su lifecycle admite únicamente `active` e `inactive`; no existe endpoint `DELETE` ni soft deletion.

```text
GET    /api/v1/doctors
POST   /api/v1/doctors
GET    /api/v1/doctors/{doctor}
PATCH  /api/v1/doctors/{doctor}
PATCH  /api/v1/doctors/{doctor}/status
```

## Módulo Laboratory Orders

Las órdenes pertenecen al laboratorio activo y sus rutas usan el middleware `saas`. El backend crea los snapshots comerciales al crear la orden y snapshots de examen/precio al agregar cada línea. Las líneas repetidas se conservan como líneas independientes; cambios posteriores al catálogo no revalorizan snapshots existentes.

```text
POST   /api/v1/laboratory-orders
GET    /api/v1/laboratory-orders/{laboratoryOrder}
PATCH  /api/v1/laboratory-orders/{laboratoryOrder}/status
GET    /api/v1/laboratory-orders/{laboratoryOrder}/exams
POST   /api/v1/laboratory-orders/{laboratoryOrder}/exams
DELETE /api/v1/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}
PUT    /api/v1/laboratory-orders/{laboratoryOrder}/discount
DELETE /api/v1/laboratory-orders/{laboratoryOrder}/discount
```

Todas estas rutas requieren sesión Sanctum y `X-Laboratory-ID`. Las órdenes y líneas fuera del tenant responden con `404` neutral. La economía y la intención de descuento son administradas por el servidor; Store no acepta importes económicos.

### Economía de la orden

La economía se calcula desde los precios `unit_price` guardados en cada línea:

- `subtotal`: suma exacta de los snapshots de precio.
- `discount_type` y `discount_value`: intención opcional del descuento global (`percentage` o `amount`).
- `discount`: importe resultante; un monto fijo se limita al subtotal sin alterar la intención guardada.
- `taxes`: actualmente `0.00`.
- `total`: subtotal menos descuento más impuestos.

Los importes se persisten como `NUMERIC(12,2)` y el cálculo usa centavos enteros con redondeo HALF_UP. No se recalculan líneas históricas contra el catálogo. Crear o quitar una línea, o cambiar el descuento, recalcula subtotal y totales dentro de una transacción y bajo el lock de la orden.

ADD/DELETE de exámenes y PUT/DELETE de descuento sólo se permiten mientras la orden está `pending`. Después de avanzar de estado, la composición y el descuento quedan congelados; las lecturas de la orden y sus líneas siguen disponibles.

Para configurar o reemplazar el descuento global, el PUT acepta exactamente:

```json
{
  "type": "percentage",
  "value": "10.00"
}
```

`type` acepta `percentage` o `amount`; `value` debe ser mayor que cero y tener como máximo dos decimales. Los porcentajes no pueden superar 100. Para quitar la intención completa, utilizar DELETE. Ambos endpoints devuelven `200` con el recurso actualizado de la orden; los exámenes se consultan por separado mediante `/exams`.

Para ejecutar regresiones del módulo desde `laradock/`:

```bash
docker compose exec workspace php artisan test tests/Feature/Api/V1/LaboratoryOrders
docker compose exec workspace php artisan test tests/Unit/Services/LaboratoryOrders
docker compose exec workspace php artisan l5-swagger:generate
```

Las pruebas de concurrencia que validan `FOR UPDATE` deben ejecutarse contra PostgreSQL real; SQLite no emula locking de filas.

## Acceso local

- Backend: http://localhost:8081
- Health check: http://localhost:8081/api/v1/health

El health check responde HTTP 200 con:

```json
{
  "status": "ok"
}
```

Los endpoints disponibles para autenticación son `POST /api/v1/auth/login`, `GET /api/v1/auth/me` y `POST /api/v1/auth/logout`.

### Lecturas compartidas de motivos clínicos

BE-EXC-02.5 incorpora GET `/api/v1/laboratory/clinical-reasons` para globales adoptados y locales del contexto autorizado, con referencia lista para BE08, elegibilidad informativa, filtros y paginación. Se reutilizan los permisos existentes por origen; el backend revalida al escribir. [Contratos y guía de integración](docs/sprint-2/BE-EXC-02.5-contratos-lectura.md) detallan los campos aditivos de pendientes, elegibilidad global y capacidades propias SaaS. Sin frontend ni aplicación de migraciones a entornos reales.

## Validación integral del catálogo clínico

[BE-EXC-02.6 — auditoría y recomendación de cierre técnico](docs/sprint-2/BE-EXC-02.6-validacion-integral.md) registra la suite completa en PostgreSQL, aislamiento, autoridades, integridad, concurrencia, contratos y medición. El cierre técnico del catálogo se distingue del alcance pendiente de evidencia, viabilidad, incidentes e informes BE-EXC-03/04/05; la decisión administrativa corresponde al Product Owner.

## Garantías transversales de excepciones clínicas

[BE-EXC-06 — integridad transversal](docs/sprint-2/BE-EXC-06-integridad-transversal.md) documenta la protección SQL de auditoría, el orden de bloqueos de revisiones, la autorización interna compartida y las pruebas PostgreSQL del circuito BE-EXC-02/03/04/05 con BE-S2-08/09.1. Incluye resultados, límites y recomendaciones para aplicar la migración aditiva; no acredita despliegue ni modifica decisiones aprobadas.

Este seguimiento es posterior al corte BE-EXC-02.6: las referencias anteriores a BE-EXC-03/04/05 pendientes corresponden a aquel corte histórico.
