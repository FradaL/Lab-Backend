# Donqer Lab — BE-S2-02: validación aislada y controles transversales

Fecha de validación: 2026-10-10. Alcance: infraestructura de pruebas PostgreSQL y controles existentes. No cambia D01–D13, DT14–DT17 ni los contratos de BE-S2-01; no incorpora configuración clínica, muestras, resultados, pagos o reportes.

## Entorno y arquitectura

Se verificó el Laradock modular existente: desarrollo usa el proyecto `donqer-lab`, el servicio PostgreSQL y el workspace PHP. Sus archivos, servicios, redes y datos se conservan. El Compose independiente está en `deploy/testing/compose.yaml` y no incluye el Compose de desarrollo.

| Componente | Configuración de testing |
| --- | --- |
| PostgreSQL | `postgres:17-alpine`; versión ejecutada 17.11 |
| Servicio y host DNS | `postgres-testing` |
| Base y rol exclusivos | `donqer_lab_testing` |
| Contraseña | `testing-only-isolated-postgres`, exclusivamente para este cluster privado y efímero |
| Puerto | 5432 dentro de la red; ninguno publicado al host |
| Datos | `tmpfs` en `/var/lib/postgresql/data`; sin volumen ni directorio compartido con desarrollo |
| Red | `testing`, interna, prefijada por el proyecto Compose |
| Runner | imagen local `${DONQER_TEST_PHP_IMAGE:-donqer-lab-workspace}`; PHP verificado 8.4.25, extensiones `pdo_pgsql` y `pcntl` disponibles |
| Aplicación | `/var/www` de sólo lectura; `.env` de desarrollo enmascarado con el ejemplo de testing |
| Escrituras del runner | `storage`, `bootstrap/cache` en tmpfs; archivos de caché de configuración/rutas/proveedores en `/tmp` |

No hay nombres globales de contenedor ni puertos que puedan colisionar. Cada checkout o ejecución simultánea debe usar un nombre de proyecto Compose distinto. **No ejecutar dos suites simultáneamente contra el mismo proyecto/base**: ambas realizan limpieza de tablas. El nombre de proyecto separa las redes y contenedores, aunque el alias DNS y la identidad de la base se mantienen dentro de cada red.

`laravel/.env.testing.example` es versionable y seguro para este entorno. Incluye `APP_ENV=testing`, clave de aplicación pública de testing, `APP_DEBUG=false`, conexión `pgsql`, identidad anterior, `DB_URL` vacío, caché/sesiones en memoria, cola síncrona, correo array, logging null y bcrypt reducido. `SANCTUM_STATEFUL_DOMAINS` incluye `localhost:5173`, el origen usado por las pruebas SPA existentes. No contiene credenciales reales ni se copia sobre `laravel/.env`.

PHPUnit conserva las suites Unit y Feature y las opciones de testing. Se retira su selección implícita de SQLite: la integración toma la conexión del entorno aislado y falla si el destino no está autorizado. Los unitarios puros siguen sin necesitar base.

No se instalaron dependencias. El runner reutiliza la imagen de workspace verificada y `laravel/vendor` ya instalado. Si falta esa imagen, construir el servicio workspace del Laradock propio del checkout y definir `DONQER_TEST_PHP_IMAGE` con el nombre real de la imagen. Verificar PHP 8.4, `pdo_pgsql`, `pcntl` y las dependencias Composer antes de ejecutar la suite; no conectar el runner a redes de desarrollo para resolverlo.

## Comandos reproducibles

Ejecutar desde la raíz del repositorio. Sustituir `donqer-be-s2-02` en **todos** los comandos por un nombre exclusivo si otro proceso usa ese proyecto.

```bash
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml config --quiet
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml ps

# Migración inicial: sólo el destino de testing pasa la guarda.
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml run --rm tests php tests/artisan.php migrate --force

# Limpieza y recreación reproducible de la base de testing.
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml run --rm tests php tests/artisan.php migrate:fresh --force

# Suite completa: incluye integración y concurrencia real en PostgreSQL.
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml run --rm tests

# Selecciones útiles.
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --testsuite Unit
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --filter 'InactiveSessionTest|TransversalTenantIsolationTest|TestDatabaseGuardTest'
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --filter 'ConcurrencyTest|test_audit_failures_roll_back_all_mutations_and_economics|test_failed_economic_update_rolls_back_add_and_delete_with_previous_economics'

# Ocho comprobaciones negativas en otro proyecto/base, separado de cualquier suite.
docker compose -p donqer-be-s2-02-guards -f deploy/testing/compose.yaml run --rm tests sh tests/check-database-guards.sh
docker compose -p donqer-be-s2-02-guards -f deploy/testing/compose.yaml down --remove-orphans

# Detener y eliminar exclusivamente servicios/red del proyecto de testing.
docker compose -p donqer-be-s2-02 -f deploy/testing/compose.yaml down --remove-orphans
```

Los datos tmpfs desaparecen al detener PostgreSQL. Para reiniciar desde cero basta `down` y `up`; no se requieren `down -v` ni limpiezas de volúmenes Laradock. Las factories crean los datos necesarios; la suite no depende del seeder demo ni de IDs manuales.

## Protección contra destinos incorrectos

`Tests\Support\TestDatabaseGuard` valida antes de abrir una conexión:

1. Entorno Laravel efectivo y `config('app.env')` ambos `testing`, incluyendo configuración cacheada. El override `--env=testing` no puede autorizar un cache generado para otro entorno.
2. Conexión por defecto exactamente `pgsql` y driver PostgreSQL.
3. Host escalar `postgres-testing`, puerto 5432, base, usuario y contraseña exclusivos autorizados.
4. Ausencia de URL y de overrides read/write que puedan redirigir la conexión.
5. La misma lista sobre la configuración efectiva de la conexión resuelta por Laravel.

Sólo entonces ejecuta una consulta de lectura para confirmar `current_database()`, `current_user` y PostgreSQL 17. Un fallo aborta sin migraciones ni limpieza. Los errores señalan el campo rechazado, sin imprimir contraseñas ni URLs.

El provider se registra cuando el entorno efectivo es testing o `APP_ENV` solicita testing; esto permite rechazar también un cache local aunque sus valores sustituyan el entorno solicitado. Su boot verifica el destino antes de las operaciones de tests o Artisan; `Tests\TestCase::createApplication()` verifica además después del bootstrap para rechazar una aplicación que haya cargado configuración de otro entorno antes de `RefreshDatabase`/`DatabaseMigrations`. Las órdenes `migrate*` y `db:wipe` vuelven a validar configuración y rechazan `--database` alternativo. Laravel 13 omite por defecto los eventos de consola cuando el entorno es testing; el provider habilita explícitamente su enrutamiento para que esa protección opere también en Artisan.

`tests/artisan.php` verifica el entorno aun cuando sea incorrecto y devuelve código 1 al rechazarlo. Usarlo para todas las operaciones de CLI de testing. Los comandos normales de desarrollo mantienen su comportamiento: esta guarda no autoriza ejecutar operaciones de testing desde el workspace de desarrollo.

La lista autorizada es deliberadamente fija y no se deriva de variables que un comando pueda cambiar junto con el destino. Si se cambia la arquitectura de testing, deben revisarse conjuntamente Compose, ejemplo, guarda y sus pruebas. La red privada separada y el enmascaramiento del `.env` complementan la verificación; Docker sigue siendo una dependencia de confianza.

## Autenticación y permisos

Se reutilizan `AuthenticationTest`, `CurrentAuthorizationTest`, `ProtectedDomainAuthorizationTest`, `LaboratoryOrderAuthorizationTest` y las pruebas de infraestructura Spatie Teams. Cubren invitados, membresías ausentes/inactivas, permisos insuficientes, acceso permitido, roles combinados, permisos directos, aislamiento por team y cambios de contexto sin permisos retenidos.

**Vulnerabilidad confirmada:** login comprobaba `users.is_active`, pero una sesión ya abierta seguía accediendo a `/api/v1/auth/me` después de desactivar al usuario. Las dos pruebas nuevas reprodujeron respuestas 200 donde debían negar acceso, antes del arreglo.

**Corrección mínima:** middleware `EnsureActiveUser`, alias `user.active`, después de autenticar y antes del contexto/permiso del laboratorio. Consulta el flag persistido en cada solicitud para cubrir también guards que retengan un User antiguo. Protege las rutas SaaS y `/auth/me`, `/auth/laboratories`; responde 403 con `code=USER_INACTIVE`. `/auth/logout` queda disponible para cerrar la sesión desactivada. No cambia Sanctum ni Spatie, ni revoca/rediseña sesiones.

`InactiveSessionTest` prueba login SPA real, acceso activo, desactivación desde base, denegación de perfil/laboratorios/autorización/pacientes, escritura rechazada sin modificar el paciente, logout e invitado posterior; también comprueba un guard con usuario obsoleto.

## Aislamiento multi-tenant

| Superficie | Cobertura |
| --- | --- |
| Contexto | `ResolveLaboratoryContextTest`: header ausente/inválido, laboratorio inexistente/inactivo, membresía ajena/inactiva, múltiples laboratorios, cambios y limpieza de scope |
| Suscripción | `EnsureActiveSubscriptionTest`: ausente, vencida, trial vencido, futura y activa |
| Pacientes y doctores | suites de listado/detalle/actualización/estado y permisos; IDs externos rechazados |
| Exámenes | suites de catálogo y precios; relaciones con área/tipo/precio limitadas al tenant |
| Órdenes | creación sólo de cabecera, listado, detalle, filtros, estados/descuentos; FKs de sucursal/paciente/doctor/cliente/lista ajenos rechazados |
| Líneas | listado/adición/borrado; orden y línea ajenas, línea de otra orden, examen ajeno; sin mutación ni auditoría en solicitudes rechazadas |
| Listas de precios | detalle/actualización/estado/default, precios y asignaciones comerciales; asociación con recursos ajenos rechazada |

La nueva `TransversalTenantIsolationTest` añade una matriz B → A → B con usuario miembro y autorizado en ambos laboratorios. Verifica cinco recursos mediante GET y PATCH de estado reales: 404 en contexto ajeno, ninguna modificación ni auditoría, y acceso válido al volver al laboratorio propietario. Para pacientes el 404 existente incluye el ID suministrado por el cliente; la comparación con registro inexistente normaliza únicamente ese ID. Ninguna respuesta devuelve `data` del recurso externo. La prueba de líneas comprueba DELETE con línea/orden ajenos y POST con examen ajeno (422), conservando el registro y los contadores.

## Transacciones, rollback y concurrencia

Las acciones existentes de órdenes usan `DB::transaction`; adición, eliminación, descuentos y cambios de estado bloquean la cabecera con `lockForUpdate`. El recálculo y la escritura de auditoría ocurren dentro de la transacción. No se introduce una estrategia clínica futura.

Se reutilizan pruebas significativas existentes: fallo de auditoría revierte creación, línea, descuento y estado (`Audit/LaboratoryOrderAuditTest`); fallo forzado de actualización económica revierte add/delete y conserva totales (`LaboratoryOrderEconomicRecalculationTest`); overflow, borrado fallido y descuentos fallidos conservan estado anterior. No se duplican escenarios ya cubiertos.

Se adapta además `EffectivePriceListResolverTest` a la integridad real de PostgreSQL: una segunda asignación activa solapada falla con SQLSTATE `23P01` y constraint `ccpl_no_active_period_overlap`, dentro de una transacción con savepoint. Se verifica rollback de esa inserción, conservación de la asignación original y resolución posterior válida. No se deshabilita el constraint para fabricar datos imposibles.

Cinco clases de concurrencia ejecutan 17 escenarios mediante `pcntl_fork` y conexiones PostgreSQL independientes: transición contra add/delete, adiciones duplicadas, borrados repetidos, composición/totales, cambios de descuento simultáneos y transiciones terminales. No son simulaciones SQLite. Se exige `pcntl`; las omisiones deben registrarse si falta esa extensión.

Riesgos pendientes: el bloqueo actual serializa mutaciones de cabecera, pero no define idempotencia de reintentos (dos adiciones iguales crean dos líneas por contrato actual); el ciclo clínico requiere decidir atomicidad, estrategia de estados, permisos, auditoría y coordinación con resultados/muestras. No se interpreta el bloqueo de órdenes como garantía sobre entidades clínicas futuras ni sobre cambios administrativos concurrentes de catálogos.

## Resultados reales y límites

La ejecución completa final terminó con código 0, PHP 8.4.25 y PostgreSQL 17.11, en 3 minutos 46 segundos:

| Suite | Casos | Exitosos | Fallidos | Errores | Omitidos | Aserciones |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Unit | 46 | 46 | 0 | 0 | 0 | 70 |
| Feature sobre PostgreSQL | 2.894 | 2.891 | 0 | 0 | 3 | 18.265 |
| **Total** | **2.940** | **2.937** | **0** | **0** | **3** | **18.335** |

Los **17 escenarios de concurrencia** pasaron, sin omisiones. El reporte JUnit final se extrajo a `/tmp/be-s2-02-complete.xml` y el log a `/tmp/be-s2-02-complete.log`; son evidencia local temporal, no dependencias de ejecución. Pint pasó sobre los 22 archivos PHP del cambio; `git diff --check` y la validación de sintaxis del script shell pasaron. Los proyectos de testing, comparación y guardas fueron detenidos/eliminados al finalizar; los comandos anteriores permiten recrearlos.

La línea base completa se ejecutó en una copia temporal con bootstrap/rutas originales, sin las tres clases nuevas, conservando únicamente la configuración/guardas de aislamiento. Usó otro proyecto (`donqer-be-s2-02-baseline`) y otro cluster privado, sin compartir datos con la ejecución del cambio. Resultado: **2.917 casos; 2.838 exitosos, 75 fallidos, 1 error y 3 omitidos; 18.212 aserciones**.

| Hallazgo de la línea base | Casos | Ajuste exclusivamente de tests |
| --- | ---: | --- |
| Snapshots comerciales comparaban fechas del modelo recién guardado con columnas DATE leídas de PostgreSQL | 35 fallidos | Leer la entidad persistida antes del snapshot; conserva comprobación de todos los campos |
| Snapshots de pacientes comparaban `birth_date` en memoria con su representación DATE persistida | 38 fallidos | Refrescar fixture antes del request; las comprobaciones de ausencia de modificación siguen completas |
| Expectativa de descuento para usuario demo ignoraba su rol adicional cashier | 1 fallido | Esperar 422 de validación, conforme al seeder y catálogo reales; cambio de estado continúa 403 |
| Idempotencia del seeder asumía orden físico de filas | 1 fallido | Orden explícito por código antes de comparar mapas ID/código |
| Fixture comercial intentaba insertar periodos solapados prohibidos en PostgreSQL | 1 error | Comprobar constraint y rollback real, en vez de requerir datos inválidos |

La primera ejecución con la corrección de usuario detectó además tres presupuestos de consultas que necesitaban incorporar la lectura de `users.is_active`. Se ajustan para exigir una sola lectura de activación más una de eager loading, manteniendo constante el total para páginas de 1, 10 y 50 órdenes. Una comparación nueva de 404 se ajustó al mensaje real de pacientes, que incluye el ID solicitado. Estos ajustes quedaron verificados junto con los anteriores: **221 casos, 1.573 aserciones, sin fallos ni omisiones**.

Las tres omisiones originales son exclusivamente SQLite: `test_invalid_in_memory_representable_intent_rolls_back_on_sqlite`, `test_sqlite_value_triggers_survive_rollback_and_forward_migration` y `test_sqlite_does_not_add_a_trigger_to_emulate_the_postgresql_discount_check`. Sus equivalentes de constraints PostgreSQL se ejecutan en la suite. No se cuentan las ejecuciones interrumpidas al corregir configuración SPA/debug como resultados completos.

La prueba de reproducción de la vulnerabilidad tuvo **2 casos fallidos antes del arreglo**, ambos con 200 en vez de 403. La selección de autenticación y guardas tras corregirla pasó **29 casos y 75 aserciones**. La selección final de las **23 pruebas nuevas** pasa con **86 aserciones**.

Las **8 comprobaciones negativas CLI** pasan y exigen rechazo específico de seguridad y código de salida no cero: host, base, conexión, URL, entorno del wrapper, conexión explícita y dos casos de cache local solicitado mediante `APP_ENV=testing`/`--env=testing`. El script construye el cache deliberadamente incorrecto sólo en `/tmp/config.php` del runner efímero. Durante el desarrollo de la guarda, el segundo caso descubrió que Laravel sobreescribe el entorno efectivo desde argv aunque `app.env` cacheado siga local; una ejecución provisional quedó invalidada porque limpió exclusivamente el cluster efímero de la suite. Se detuvo, se añadió validación de ambos valores y una prueba unitaria de regresión, se comprobaron los negativos en otro proyecto y se repitió la suite desde cero. Esa ejecución invalidada no se declara exitosa.

La migración/recreación explícita protegida, red privada, ausencia de puertos y PostgreSQL 17.11 fueron comprobados realmente.

Verificación de desarrollo: hash SHA-256 del dump completo, sin owner/privilegios y con clave de restrict fija, idéntico antes y después de las pruebas: `1caeb50dca630db66810b088a17980e03ea6acadfbb16ce2036d1a7f706d8a0e`. Sólo se leyó y se imprimió el hash, nunca el dump. PostgreSQL, workspace, PHP-FPM y nginx de `donqer-lab` permanecen activos con su inicio original `2026-10-10T02:54:27Z`. Los hashes de `laravel/.env`, `laradock/.env`, los dos archivos Laradock previamente modificados por el usuario y los dos documentos funcionales son idénticos a la referencia inicial.

## Archivos del cambio

Nuevos: `deploy/testing/compose.yaml`; `laravel/.env.testing.example`; `laravel/app/Http/Middleware/EnsureActiveUser.php`; `laravel/tests/Support/TestDatabaseGuard.php`; `laravel/tests/Support/TestingDatabaseServiceProvider.php`; `laravel/tests/artisan.php`; `laravel/tests/check-database-guards.sh`; `laravel/tests/Unit/TestDatabaseGuardTest.php`; `laravel/tests/Feature/Api/V1/Auth/InactiveSessionTest.php`; `laravel/tests/Feature/Authorization/TransversalTenantIsolationTest.php`; este documento.

Modificados: `.gitignore`, `laravel/phpunit.xml`, `laravel/tests/TestCase.php`, `laravel/app/Providers/AppServiceProvider.php`, `laravel/bootstrap/app.php`, `laravel/routes/api.php`. Ajustes de tests existentes: `CommercialClientPriceListStatusTest`, `CommercialClientPriceListStoreTest`, `CommercialClientPriceListUpdateTest`, `PatientStatusUpdateTest`, `PatientUpdateTest`, `LaboratoryOrderIndexIntegrationTest`, `LaboratoryOrderIndexTest`, `LaboratoryOrderShowTest`, `EffectivePriceListResolverTest`, `LaboratoryOrderAuthorizationTest`, `LaboratoryOrderSeederTest`.

Los cambios previos del usuario en `laradock/.env.example` y `laradock/docker-compose.yml` no forman parte de esta implementación. Los dos documentos funcionales y los `.env` de desarrollo se conservan. No se crean commits ni se hace push.

## Continuación del Sprint 2

BE-S2-03 puede preparar la configuración clínica según BE-S2-01, conservando esta suite verde, los comandos y las guardas como base de regresión. Los fallos de testing encontrados quedaron corregidos; antes de ampliar el ciclo deben aprobarse las decisiones técnicas pendientes de BE-S2-01. DT14–DT17 siguen siendo requisitos funcionales aprobados pendientes de implementación; P01–P09 siguen siendo propuestas técnicas, sin aprobación implícita por esta tarea. No se ejecuta BE-S2-03 en este cambio.
