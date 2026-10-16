# BE-S2-10 — Administración de usuarios y membresías del laboratorio

## Resultado

El módulo permite listar colaboradores, consultar su detalle local, invitarlos sin apropiarse de identidades globales, aceptar la invitación con prueba de control de la identidad, cambiar el rol del laboratorio, activar o desactivar la membresía y consultar los roles que el actor puede asignar.

La identidad sigue siendo global en `users`. El acceso a cada laboratorio continúa en `laboratory_user` y los roles continúan aislados por `laboratory_id` mediante Spatie Permission Teams. No se agregó `laboratory_id` a `users` ni se modificaron identidades clínicas históricas.

## Diagnóstico de la arquitectura

| Capacidad | Estado previo | Resultado BE-S2-10 |
| --- | --- | --- |
| Identidad global | `users`, correo único y autenticación Sanctum SPA | Reutilizada sin duplicarla por laboratorio |
| Membresía | `laboratory_user`, única por laboratorio/usuario, `is_active` e `is_default` | Administración local y transaccional |
| Contexto tenant | `CurrentLaboratory` resuelto por `X-Laboratory-ID` | Reutilizado en todas las APIs administrativas |
| Pipeline SaaS | autenticación, usuario activo, membresía, equipo RBAC y suscripción | Reutilizado sin rutas alternativas |
| RBAC | seis roles y permisos por equipo | Cinco permisos administrativos nuevos; seeder idempotente |
| Auditoría | `AuditWriter` append-only | Invitación, identidad, membresía, rol y estado auditados |
| Profesionales | identidad estable `validation_professionals` ligada a usuario y laboratorio | El listado expone elegibilidad/estado sin crear profesionales automáticamente |
| Activación | No existía invitación ni establecimiento seguro de credenciales | Invitación de un uso, expirable y con token almacenado como hash |

## Endpoints definitivos

Todos los endpoints administrativos usan el grupo `saas`, requieren `X-Laboratory-ID` y evalúan permisos en el equipo del laboratorio activo.

| Método | Ruta | Permiso | Contrato |
| --- | --- | --- | --- |
| `GET` | `/api/v1/laboratory/users` | `users.view` | Búsqueda, estado, rol, orden y paginación |
| `POST` | `/api/v1/laboratory/users` | `users.create` y `users.assign_role` | Emite una invitación; responde `202` |
| `GET` | `/api/v1/laboratory/users/{user}` | `users.view` | Solo encuentra miembros del laboratorio activo |
| `PATCH` | `/api/v1/laboratory/users/{user}/role` | `users.assign_role` | Reemplaza los roles locales por un rol autorizado |
| `PATCH` | `/api/v1/laboratory/users/{user}/status` | `users.change_status` | Cambia solo `laboratory_user.is_active` |
| `GET` | `/api/v1/laboratory/roles` | `roles.view` | Devuelve solamente roles asignables por el actor |
| `POST` | `/api/v1/laboratory-user-invitations/{token}/accept` | Token; sesión obligatoria solo si la identidad ya existe | Crea identidad nueva o vincula al titular autenticado |

No se implementó `PATCH /api/v1/laboratory/users/{user}`. El pivote no posee atributos administrativos locales adicionales: nombre, correo, contraseña y estado global pertenecen a la identidad compartida, mientras que rol y estado local ya tienen operaciones explícitas. Exponer un PATCH genérico sería redundante o permitiría afectar otros laboratorios. Por la misma razón no se agregó `users.update` al catálogo actual.

## Contratos JSON

### Listado y detalle

```json
{
  "data": [
    {
      "id": 12,
      "name": "Ana López",
      "email": "ana@example.com",
      "membership": {
        "id": 8,
        "status": "active",
        "is_default": false,
        "created_at": "2026-10-16T14:00:00.000000Z",
        "updated_at": "2026-10-16T14:00:00.000000Z"
      },
      "roles": ["laboratory_technician"],
      "validation_professional": {
        "id": 3,
        "status": "authorized"
      },
      "can_register_validation_professional": false
    }
  ],
  "links": {},
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

Filtros: `search`, `status=active|inactive`, `role`, `sort=name|email|created_at`, `direction=asc|desc`, `per_page` entre 1 y 100 y `page`. El segundo criterio de orden siempre es `users.id`.

### Emitir invitación

```json
{
  "name": "Ana López",
  "email": "ana@example.com",
  "role": "laboratory_technician"
}
```

```json
{
  "message": "Si el correo puede recibir invitaciones, se enviaron las instrucciones de acceso.",
  "data": {
    "invitation_id": 25,
    "status": "pending",
    "expires_at": "2026-10-19T14:00:00.000000Z"
  }
}
```

La respuesta no indica si el correo ya corresponde a una identidad de otro laboratorio. Un miembro que ya pertenece al laboratorio produce el conflicto genérico `laboratory_user_invitation_unavailable`.

### Aceptar como identidad nueva

```json
{
  "name": "Ana López",
  "password": "una-clave-segura",
  "password_confirmation": "una-clave-segura"
}
```

El correo no se recibe en el body: procede de la invitación y queda verificado porque el token fue entregado a ese buzón. La contraseña exige al menos 12 caracteres. La respuesta `201` no inicia una sesión automática.

Si la identidad global ya existe, el titular inicia sesión mediante Sanctum y acepta con `{}`. Una sesión ausente, de otra persona o globalmente inactiva recibe `403` con `laboratory_user_invitation_identity_required`.

### Rol y estado

```json
{ "role": "receptionist" }
```

```json
{ "status": "inactive" }
```

Ambas operaciones devuelven el recurso de usuario de laboratorio. Los identificadores de otro tenant responden `404`.

## Seguridad y concurrencia

- La invitación usa un token aleatorio de 64 caracteres; solo se persiste su SHA-256. Es de un uso, se revoca al reemplazarla y vence en 72 horas por defecto.
- La configuración `LABORATORY_USER_INVITATION_URL` define la ruta frontend incluida en el correo y `LABORATORY_USER_INVITATION_TTL_HOURS` su vigencia.
- Crear la invitación no crea usuario ni membresía. El correo se envía mediante Laravel Notifications; no se simula una entrega.
- La aceptación serializa por correo con un advisory transaction lock de PostgreSQL. La unicidad global de `users.email` mantiene la defensa final ante carreras.
- Los cambios de rol/estado bloquean el laboratorio, la identidad, la membresía y los grants relevantes antes de reevaluar permisos.
- `owner` puede asignar los seis roles; `administrator` solo `receptionist`, `cashier`, `laboratory_technician` y `viewer`.
- Solo `owner` puede modificar el rol o estado de miembros administrativos. Nadie puede autoescalar y el laboratorio conserva al menos un `owner` o `administrator` activo cuya identidad global también esté activa.
- Una identidad global inactiva no puede reactivarse desde esta API; eso pertenece a otra autoridad.
- Desactivar una membresía hace que `ResolveLaboratoryContext` rechace inmediatamente nuevas solicitudes para ese laboratorio sin tocar otras membresías.

## Auditoría

| Evento | Sujeto |
| --- | --- |
| `laboratory_user.invitation_issued` | invitación |
| `laboratory_user.identity_created` | usuario global creado por aceptación |
| `laboratory_user.membership_accepted` | membresía |
| `laboratory_user.role_changed` | membresía |
| `laboratory_user.status_changed` | membresía |

Los payloads contienen estados, roles, `target_user_id` e identificadores internos pertinentes. No contienen correo, contraseña ni token. La escritura de auditoría participa en la misma transacción; un fallo revierte el cambio de dominio.

## Integración con BE-S2-07

`ValidationProfessional` no se crea al invitar ni al aceptar. El listado carga, solamente para el laboratorio actual, `validation_professional.id`, su `status` y `can_register_validation_professional`. Esto permite alimentar el selector de FE-S2-10 sin confundir usuario, médico solicitante y profesional validador.

Al desactivar la membresía no se altera la identidad profesional ni sus snapshots. BE-S2-07 ya comprueba una membresía activa al registrar/autorizar y antes de validar; por ello el acceso futuro queda bloqueado mientras la evidencia histórica permanece intacta.

## Migración

`2026_10_16_000001_create_laboratory_user_invitations_table.php` agrega exclusivamente infraestructura de invitaciones: laboratorio, correo normalizado, nombre sugerido, rol, hash del token, actor, vencimiento, aceptación y revocación. Sus claves foráneas son restrictivas para conservar trazabilidad. No modifica datos operativos ni se ejecutó contra desarrollo o producción.

## Pruebas

La suite dirigida usa el proyecto Compose aislado `donqer-be-s2-10`, PostgreSQL 17 en tmpfs y las guardas existentes:

```bash
docker compose -p donqer-be-s2-10 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-10 -f deploy/testing/compose.yaml run --rm tests \
  php artisan test \
  --filter='LaboratoryUserAdministrationTest|LaboratoryUserInvitationConcurrencyTest|RolePermissionSeederTest' \
  --do-not-cache-result
```

Resultado dirigido: **15 pruebas, 162 aserciones**. Cubre listado/filtros/paginación, aislamiento y `404` cruzado, alta nueva, vinculación autenticada, rechazo de apropiación, reemisión y revocación de tokens, carrera de aceptación con conexiones PostgreSQL reales, roles independientes por laboratorio, jerarquía, último administrador, cambio de estado, bloqueo efectivo, permisos faltantes, contratos cerrados, inventarios runtime/OpenAPI e integridad transaccional ante fallo de auditoría.

OpenAPI se regeneró correctamente con `php artisan l5-swagger:generate`. La suite completa ejecutó **3325 pruebas exitosas, 3 omitidas, 1 fallida y 21094 aserciones** en 994.90 s. El único fallo fue una prueba preexistente que compara todos los encabezados de dos respuestas consecutivas: sus valores `Date` quedaron separados por un segundo. Al repetir inmediatamente el archivo afectado pasó completo: **18 pruebas, 231 aserciones** en 2.73 s. No se modificó código ajeno para ocultar esa intermitencia temporal.

## Riesgos y operación pendiente

- Producción debe configurar un mailer real y una URL frontend HTTPS para aceptar invitaciones. El backend no puede garantizar entrega del proveedor de correo; un error síncrono produce error HTTP y la invitación puede reemplazarse con un nuevo intento.
- No se incluyó administración del estado global de `users`, recuperación de contraseña ni edición de identidad compartida.
- No hay endpoint administrativo de revocación/listado de invitaciones. Emitir otra invitación para el mismo laboratorio/correo revoca la pendiente anterior.
- La aceptación usa una primitiva específica de PostgreSQL, coherente con la plataforma y con la infraestructura de pruebas obligatoria.
