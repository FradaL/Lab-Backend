# Donqer Lab Backend

Backend SaaS para laboratorios clínicos Donqer Lab. Incluye Laravel, PostgreSQL, Laradock, autenticación SPA con Sanctum y módulos tenant-aware de pacientes y médicos. Spatie Laravel Permission está preparado para una etapa posterior.

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

## Usuarios demo

Los seeders crean dos usuarios exclusivamente para desarrollo local:

| Nombre | Correo | Contraseña |
| --- | --- | --- |
| Administrador Demo | `admin@donqerlab.test` | `password` |
| Recepción Demo | `reception@donqerlab.test` | `password` |

La contraseña se almacena hasheada. Estos usuarios no tienen roles ni permisos asignados; esa configuración pertenece a BE-17.

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
