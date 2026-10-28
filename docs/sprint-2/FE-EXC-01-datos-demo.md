# FE-EXC-01 — Catálogo sintético autorizado

## Viabilidad de autorización

El flujo está permitido por `SaasAccessManager` y por el trigger PostgreSQL
`protect_saas_assignment`:

1. Se crean dos usuarios sintéticos activos, DEMO A y DEMO B, sin membresías tenant.
2. Con configuración explícita y temporal, `SaasAccessManager::bootstrap()` concede
   exclusivamente `saas.access.manage` a A. Es la implementación utilizada por
   `SaasAccessBootstrapSeeder`; sólo admite la primera inicialización y deja
   auditoría `system/SaasAccessBootstrapSeeder`.
3. A llama a `SaasAccessManager::grant()` para conceder a B las capacidades
   `saas.clinical_reasons.view`, `.create`, `.publish` y `.manage`.
   Cada concesión registra a A como actor y a B como destinatario.
4. B invoca `GlobalClinicalReasonManager::create()`, `publish()` y `activation()`.

A debe estar activo y autorizado; B debe ser distinto y estar activo; la capacidad
debe existir en `saas_capabilities`; no puede haber una concesión vigente duplicada.
El concedente no necesita poseer la capacidad clínica que administra. A no recibe
capacidades clínicas automáticamente. El bootstrap rechaza acceso SaaS ya
inicializado para otra identidad. Las restricciones se mantienen sin modificaciones.

## Archivos

- `laravel/app/Console/Commands/ProvisionDemoClinicalReasons.php`: comando explícito.
- `laravel/config/clinical_reason_demo.php`: contraseña del owner sintético.
- `deploy/demo-exc/compose.yaml`: API y PostgreSQL 17 exclusivos, sin volúmenes de BD.
- `laravel/tests/Feature/ClinicalReasons/DemoClinicalReasonProvisioningTest.php`.

No se cambia ningún servicio de negocio, controlador, contrato ni migración.
Se reutiliza `DemoDataset` para registrar procedencia y rechazar colisiones.

## Preparación y ejecución

Requisitos: Docker Compose v2, imagen local `donqer-lab-workspace` (PHP 8.3 o
superior con `pdo_pgsql`), dependencias de `laravel/vendor` instaladas y puerto
8086 disponible. El contenedor incluye PHP y Composer; no se requiere PHP anfitrión.
Puede seleccionarse otra imagen compatible mediante `DONQER_TEST_PHP_IMAGE`.

Desde la raíz de `backend-lab`, en la misma terminal:

```bash
read -rsp 'Contraseña nueva para owner DEMO (mínimo 12 caracteres): ' DONQER_EXC_DEMO_OWNER_PASSWORD
export DONQER_EXC_DEMO_OWNER_PASSWORD
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml up -d
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml exec api php artisan migrate --no-interaction
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml exec api php artisan demo:clinical-reasons --confirm-database=donqer_exc_demo
```

El último comando pide confirmación y rechaza la respuesta predeterminada negativa.
Para ejecución automatizada expresamente autorizada, añadir `--yes`. La opción
`--confirm-database` siempre es obligatoria. No usar `migrate:fresh`, `db:wipe` ni
el Compose habitual del laboratorio para este procedimiento.

La BD dedicada se llama **donqer_exc_demo**, su host interno es
**postgres-exc-demo** y su usuario es **donqer_exc_demo**. El comando comprueba
entorno, conexión PostgreSQL, host, puerto, nombre, usuario y la identidad efectiva
del servidor; rechaza URLs y réplicas. Sólo admite `local`, o `testing` sobre el
cluster aislado existente `postgres-testing/donqer_lab_testing`.
La primera ejecución exige ausencia de usuarios, laboratorios, motivos y
asignaciones SaaS. No importa usuarios ni permisos de la base habitual.

Toda la provisión ocurre en una transacción serializada. Una colisión, cambio de
historial, revocación previa o fallo de auditoría revierte la ejecución completa.
Las siguientes ejecuciones verifican procedencia e historial sin sobrescribir ni
reparar cambios administrativos. La contraseña del owner se fija al crearlo;
repetir el comando no la cambia.

## Datos y auditoría

| Elemento | Cantidad |
| --- | ---: |
| Motivos `demo_exc_001` a `demo_exc_051` | 51 |
| Versiones | 56 |
| Motivos activos actualmente | 45 |
| Motivos inactivos actualmente | 6 |
| `terminal_candidate` / `incidence_only` | 26 / 25 |
| Eventos SaaS iniciales | 61 |

`001` tiene cuatro versiones: creación, publicación, desactivación y reactivación.
`002` tiene dos versiones y termina inactivo. `003` tiene dos versiones activas y
sirve para adoptar v1 y actualizar a v2. Los otros 48 motivos tienen una versión;
`010`, `020`, `030`, `040` y `050` nacen inactivos.

La evidencia usa `schema_version: 1`, con listas vacías,
`["problem_description"]` o `["problem_description", "supporting_document"]`.
Son claves sintéticas válidas para el contrato del dominio. Ningún registro contiene
información de pacientes. Los nombres incluyen `DEMO FICTICIO` y las descripciones
advierten que no son aptos para uso diagnóstico real.

Los 61 eventos son: un bootstrap, cuatro concesiones, 51 creaciones, dos
publicaciones y tres cambios de activación. Cada versión tiene su evento, actor,
justificación y `operation_id`. El comando muestra JSON con IDs del laboratorio,
actores, cantidades creadas/encontradas y total de eventos. La segunda ejecución
debe informar 0 motivos/0 versiones creados y 51 motivos/56 versiones encontrados,
sin nuevos eventos. Las adopciones posteriores generan auditoría tenant adicional.

Las cuentas A/B usan contraseñas aleatorias no publicadas y no tienen membresía de
laboratorio. Existe una tercera cuenta exclusiva para consumo frontend:
`exc-demo-owner@donqerlab.test`, owner del laboratorio `DEMO FICTICIO EXC`, con la
contraseña introducida. Sólo recibe permisos tenant de consulta, adopción,
actualización e historial. No recibe ninguna capacidad SaaS.

## Consumo por Sara

1. Apuntar el frontend a `http://localhost:8086` (API `http://localhost:8086/api/v1`).
   El Compose autoriza el origen `http://localhost:5173`; usar ese origen durante
   esta integración y cookies con credenciales.
2. Obtener CSRF mediante `/sanctum/csrf-cookie` e iniciar sesión mediante
   `POST /api/v1/auth/login` con el owner sintético y su contraseña.
3. Consultar `GET /api/v1/auth/laboratories` o utilizar el `laboratory_id` del JSON
   del comando. Enviar `X-Laboratory-ID` en las consultas del laboratorio.
4. Consultar `GET /api/v1/laboratory/clinical-reasons/global` y `?page=2`:
   deben devolver 50 y 1 motivos respectivamente.
5. Usar `/{reason_id}` para detalle y `/{reason_id}/versions` para historial.
   Resolver los IDs desde las respuestas; no asumir IDs numéricos fijos.
6. Para `demo_exc_003`, elegir v1 desde su historial y enviar:

```http
POST /api/v1/laboratory/clinical-reason-adoptions
Content-Type: application/json
X-Laboratory-ID: <laboratory_id>

{"reason_id": <reason_id>, "version_id": <v1_id>, "justification": "DEMO: adopción explícita de integración"}
```

7. Tomar `data.adoption.id` y actualizar explícitamente:

```http
POST /api/v1/laboratory/clinical-reason-adoptions/<adoption_id>/versions
Content-Type: application/json
X-Laboratory-ID: <laboratory_id>

{"version_id": <v2_id>, "expected_revision": 1, "justification": "DEMO: actualización explícita"}
```

8. Consultar `GET /api/v1/laboratory/clinical-reason-adoptions/<adoption_id>/versions`
   para comprobar ambas revisiones. El catálogo BE-EXC-02.5
   `GET /api/v1/laboratory/clinical-reasons?source=global` refleja la versión
   adoptada; comienza vacío porque las adopciones no son automáticas.

El conjunto permite comprobar BE-EXC-02.3 y las lecturas BE-EXC-02.5. Las versiones
no declaran impacto clínico: el contrato debe informar `impact_unclassified` y
bloquear selección para solicitudes clínicas cuando corresponda. No se concede
`classify_impact` ni se simula elegibilidad diagnóstica. CA-20 requiere además que
Sara ejecute y registre la prueba desde FE; los tests de backend no sustituyen
esa aceptación.

## Retirada segura

Para retirar exclusivamente esta instalación desechable:

```bash
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml down
unset DONQER_EXC_DEMO_OWNER_PASSWORD
```

La BD vive en `tmpfs`: detener/recrear PostgreSQL pierde los datos; ejecutar de
nuevo la preparación crea otro conjunto. El comando no ofrece borrado por prefijo
ni desactiva triggers. No borrar filas de versiones, concesiones o auditoría.
No ejecutar esta retirada sobre proyectos Compose compartidos. Las facultades de
A/B permanecen sólo durante la vida de esta base desechable.

## Validación reproducible

```bash
docker compose -p donqer-exc-demo-validation -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/ClinicalReasons/DemoClinicalReasonProvisioningTest.php
```

Se usa el cluster PostgreSQL 17 en memoria del Compose de pruebas y su
`TestDatabaseGuard`, sin acceso a la BD habitual. La batería valida autorización,
auditoría por versión, idempotencia, lecturas HTTP, paginación, adopción y
actualización explícitas, aislamiento SaaS/tenant/laboratorio, confirmación,
rechazo de producción y conexiones ajenas, preservación de datos preexistentes y
cambios administrativos, rollback por fallo de auditoría y no restauración de
facultades revocadas.

Resultados ejecutados el **2026-10-27**:

- Regresión `tests/Feature/ClinicalReasons` y `tests/Feature/SaasAccess`:
  **220 pruebas, 8.642 aserciones, sin fallos**, incluidas siete pruebas nuevas.
- Provisión real en `donqer_exc_demo`: 51 motivos y 56 versiones creados,
  61 eventos SaaS. Segunda ejecución: 0 creados, 51 motivos y 56 versiones
  encontrados; permanecen los mismos 61 eventos.
- Verificación HTTP real en `localhost:8086`: CSRF y login Sanctum del owner,
  consulta de laboratorios y páginas 1/2 del catálogo correctas (50/1 registros).
- Formato PHP aplicado con Laravel Pint a los archivos nuevos.

Comando de la regresión ejecutada:

```bash
docker compose -p donqer-exc-demo-validation -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/ClinicalReasons tests/Feature/SaasAccess
```
