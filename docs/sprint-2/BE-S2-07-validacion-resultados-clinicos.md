# BE-S2-07 — Revisión y validación de resultados clínicos

**Estado:** implementado y validado con PostgreSQL aislado (2026-10-11). Esta entrega no ejecuta migraciones ni seeders sobre desarrollo/producción, ni commit, push, despliegue, frontend o cierre de Trello. El commit previo `7de6604` corresponde a BE-S2-03/04/05/06 y fue autorizado en una solicitud anterior.

## 1. Auditoría y decisiones aprobadas

Se revisaron el documento rector, BE-S2-01/03/04/05/06, usuarios/membresías, Teams/RBAC, médicos solicitantes, pacientes, publicaciones, composición, procesamiento individual, captura, restricciones SQL, auditoría, API/OpenAPI y dataset. No existía una entidad profesional vinculada al usuario ni autorización para validar. `Doctor` identifica médicos solicitantes y no se adopta automáticamente como profesional validador. Se reutilizan User, Laboratory, laboratory_user, Spatie Teams, AuditWriter/AuditLog y la captura/versiones históricas BE-S2-06.

El usuario aprobó un `reference_context` explícito. Fecha obligatoria, edad calculada por servidor desde `patient.birth_date` cuando sea necesaria, sexo clínico y condiciones declarados por quien valida, sin conversión desde `gender`. Solo se necesita completar información relevante para decidir referencias; no se inventan diagnósticos, códigos demográficos, rangos ni equivalencias. El snapshot conserva fuentes, confirmaciones y ausencias relevantes.

Los parámetros publicados siguen siendo obligatorios para la completitud técnica. Validar no completa la orden, no inicia otra línea y no genera informe. Rectificar no modifica una publicación ni convierte líneas legacy/historical en clínicas.

## 2. Política y autorización

`laboratories.validation_policy` admite `professional_required` y `permission_based`, con default persistente `professional_required` para laboratorios existentes y nuevos. El cambio se bloquea y audita por laboratorio; no cambia evidencia anterior. No se crea un módulo genérico de configuración.

Toda validación requiere usuario activo, membresía activa, Teams del laboratorio, permiso `results.validate` y política vigente. En `professional_required` se exige además identidad profesional vinculada a ese usuario, autorizada en ese laboratorio. En `permission_based` se registra el usuario real y no se inventa identidad profesional. Rectificar exige `results.rectify`, la política clínica vigente y motivo obligatorio; no concede captura/edición ordinaria ni permiso para una nueva validación.

Cinco permisos nuevos: `results.validate`, `results.rectify`, `results.validation_history`, `results.validation_policy.manage` y `results.professionals.manage`. Catálogo: **60 permisos y seis roles**. Los dos permisos de ejecución clínica validate/rectify quedan excluidos de la matriz predeterminada de owner/administrator; no existe bypass por nombre del rol. Ambos roles mantienen sus 55 permisos anteriores y reciben únicamente los tres nuevos de administración/consulta al ejecutar explícitamente RolePermissionSeeder. Su matriz contiene 58 permisos. Los otros cuatro roles conservan su matriz anterior. Asignar validate/rectify corresponde al mecanismo RBAC del laboratorio.

`RolePermissionSeeder` sigue siendo independiente del dataset. Ninguna migración asigna permisos a usuarios ni ejecuta el seeder. Su ejecución explícita sincroniza las matrices existentes según su contrato previo: antes de desplegar se debe revisar la provisión efectiva de permisos; esta entrega no la ejecuta en entornos operativos.

`permission_based` representa delegación dentro del software. No acredita una habilitación profesional ni garantiza cumplimiento de obligaciones legales o sanitarias. La configuración y comprobación de requisitos aplicables corresponde al laboratorio; no se inventan requisitos regulatorios.

## 3. Profesionales

`validation_professionals` conserva laboratorio, usuario, nombre profesional, profesión, registro opcional, estado registered/authorized/revoked, actor/fecha de autorización, actor/fecha/motivo de revocación. Una identidad por laboratorio/usuario; FK a membresía local. La identidad es estable e inmutable, con restricciones SQL contra cambio de ownership, sustitución de usuario/datos de identidad y eliminación. No hay login nuevo ni autorización automática de médicos existentes.

El administrador con `results.professionals.manage` registra primero la identidad y autoriza explícitamente después. La autoautorización está prohibida, incluida reautorización propia tras revocación. Revocar impide nuevas validaciones o rectificaciones profesionales; cambiar roles no supera esa revocación. Otro administrador puede reautorizar explícitamente la misma identidad, conservando los eventos anteriores. La vigencia depende del estado autorizado hasta revocación; no se inventa un calendario de caducidad profesional.

Las transiciones registran eventos inmutables de auditoría. Las validaciones conservan un snapshot de la identidad y de su autorización, independiente de revocaciones posteriores. No se permite un PATCH que sustituya la identidad histórica; posibles correcciones de identidad requieren un procedimiento posterior explícito.

## 4. Persistencia e integridad PostgreSQL

Migración aditiva: `2026_10_11_000002_add_clinical_result_validation.php`.

| Estructura | Garantía |
| --- | --- |
| laboratories | Política limitada a las dos modalidades. |
| validation_professionals | Identidad estable por laboratorio/usuario y estados de autorización comprobados. |
| laboratory_result_validations | Cinco identidades de pertenencia, revisión aprobada, revisión de transición, resultados completos, contexto, referencias, política, actor, profesional/snapshot cuando corresponde, fecha y comentario. |
| laboratory_result_rectifications | Validación de origen, revisiones anterior/nueva, actor, motivo, fecha y política/identidad profesional vigente al rectificar. |
| laboratory_order_result_captures | Estado preliminary/validated, revisión y `last_validation_id` compuesto con laboratorio/línea. |
| audit_logs | Eventos clínicos y administrativos transaccionales, protegidos contra UPDATE/DELETE SQL. |

FK compuestas preservan laboratorio/orden/línea/examen/versión y profesionales locales. Los actores de validación, rectificación, autorización y revocación también tienen FK a la membresía de su laboratorio; desactivarla no elimina evidencia histórica. La validación es única por línea/revisión aprobada; la rectificación es única por línea/revisión de origen. Repetir un examen mantiene capturas independientes. No se agregan validaciones ni profesionales retrospectivos a datos existentes.

Los triggers preservan checks de tipos y snapshots BE-S2-06, impiden editar/borrar valores validados, requieren revisión avanzada para cambios preliminares y exigen evidencia para cambiar de estado. El snapshot aprobado debe coincidir exactamente con todos los parámetros/valores históricos, incluidas unidades, etiquetas y actor/fecha de captura. Las referencias seleccionadas deben pertenecer al parámetro y coincidir con su documento publicado. La evidencia de validación/rectificación es inmutable. Triggers diferidos requieren completar también la transición de captura dentro de la transacción: insertar evidencia aislada no basta.

SQL comprueba la política actual y la identidad profesional autorizada cuando corresponde. Los permisos del actor, declaraciones clínicas y auditoría completa pertenecen al servicio; SQL administrativo arbitrario no sustituye esa autorización ni la resolución clínica de la aplicación. Las funciones usan CREATE OR REPLACE para migrate:fresh aislado. No se afirma equivalencia con SQLite.

El down restaura funciones/checks BE-S2-06 y elimina las estructuras/columna/puntero nuevos. Rechaza retroceso si existen validaciones: requiere una estrategia explícita de respaldo/restauración. Las carreras usan migrate:fresh al preparar una base desechable, sin degradar validaciones a preliminary. Las guardas BE-S2-02 aseguran que las suites no usan bases operativas. No se debilitan triggers para cargar el dataset.

## 5. Revisión, validación y rectificación

Solo una orden in_process, una línea explícitamente iniciada y su versión publicada histórica habilitan validación. La captura debe existir, estar preliminary y completa. No se valida una captura parcial, una línea ready sin inicio, otra línea por estar iniciada la cabecera, ni legacy/historical. Una orden cancelada impide nuevas operaciones y conserva la evidencia.

Validar revisión R inserta evidencia de `approved_revision: R` y cambia la captura a validated/revisión R+1. Los valores no cambian. La respuesta distingue revisión vigente de revisión aprobada. Un reintento con R obsoleta devuelve conflicto; no se crea una segunda validación ni se acepta un no-op que evada la revisión.

El PUT BE-S2-06 conserva la captura por fusión, decimales exactos, guardado parcial, limpieza explícita, permisos de capture/edit y protección de valores ocultos. En validated devuelve `result_not_editable`; no inicia una rectificación por sí mismo.

Rectificar requiere estado validated, revisión vigente R, motivo no vacío y autorización clínica actual. Inserta evidencia derivada de la última validación y abre preliminary/R+1, conservando los valores vigentes como punto de partida. Los cambios posteriores usan el PUT existente y sus permisos; la nueva revisión exige validación explícita. La validación anterior conserva todos sus valores y referencias. No hay doble aprobación, reapertura/completado de cabecera, anulación de procesamiento ni reemisión de informes.

## 6. Resolución de referencias y contexto

Entrada mínima, incluso cuando no hay referencias:

```json
{"revision":1,"reference_context":{"reference_date":"2026-10-11"}}
```

Solo cuando sea necesario:

```json
{
  "revision":1,
  "reference_context":{
    "reference_date":"2026-10-11",
    "sex":"CODIGO_LITERAL_CONFIGURADO",
    "conditions":{"condicion_publicada":"valor_exacto"},
    "confirm_birth_date":true
  },
  "comment":"Revisión explícita"
}
```

El cliente no envía edad, fecha de nacimiento sustitutiva, actor, profesional, política ni versión. `confirm_birth_date` identifica confirmación de la fecha existente; no la reemplaza. El backend lee y bloquea el paciente local al validar, calcula días cumplidos, archiva birth_date/edad cuando son relevantes y conserva la fecha explícita, fuentes y actor de las declaraciones. No obliga a transcribir datos confiables ya almacenados. `gender` nunca se convierte en sexo clínico. El POST validate conserva los literales de contexto sin TrimStrings/ConvertEmptyStringsToNull, igual que el PUT de captura preserva sus valores. Las condiciones son literales nombrados; ausencia/null es desconocido, no negativo ni comodín del paciente.

La aplicabilidad usa tres resultados por regla: conocida coincidente, conocida excluida o desconocida. Edad inclusiva, sexo literal y condiciones AND. Una condición conocida falsa excluye la regla aunque otros filtros sean desconocidos. Entre coincidencias conocidas vence la mayor prioridad; una candidata desconocida de igual/mayor prioridad impide seleccionar la regla general. Candidatas desconocidas de menor prioridad no desplazan a un ganador conocido.

Estados por parámetro: `not_configured`, `not_applicable`, `insufficient_context`, `conflicting_context`, `ambiguous`, `resolved`. Sin referencias configuradas se permite validar. Cuando el contexto suficiente excluye todas, se registra explícitamente not_applicable, sin inventar una referencia. Falta de datos relevantes, fecha de referencia anterior al nacimiento relevante o empate bloquean con `result_reference_unresolved` y detalles por parámetro/campos/candidatas. La publicación ya rechaza superposiciones de igual prioridad; el resolutor también conserva defensa ante ambigüedad.

La evidencia almacena el documento completo de cada referencia seleccionada, identidad versión/código/clave, contexto, fuentes, confirmaciones y ausencias relevantes. Cambiar paciente o publicar otra versión no modifica nada histórico. No se compara el valor con límites para emitir normal/anormal, criticidad, interpretación o diagnóstico. No hay un override arbitrario de referencias: se deben completar/corregir los datos confiables y repetir la operación explícitamente.

## 7. API e integración

Todos los endpoints mantienen `/api/v1`, Sanctum SPA, usuario activo, contexto X-Laboratory-ID, membresía, suscripción y Teams. Recursos ajenos/inexistentes responden 404. Registro/lista/autorizar/revocar requieren professionals.manage; consulta/cambio de política requieren validation_policy.manage.

| Método y ruta relativa | Permiso |
| --- | --- |
| GET `/laboratory/validation-policy` | results.validation_policy.manage |
| PUT `/laboratory/validation-policy` | results.validation_policy.manage |
| GET `/laboratory/validation-professionals` | results.professionals.manage |
| POST `/laboratory/validation-professionals` | results.professionals.manage |
| POST `/laboratory/validation-professionals/{professional}/authorize` | results.professionals.manage |
| POST `/laboratory/validation-professionals/{professional}/revoke` | results.professionals.manage |
| POST `/laboratory-orders/{order}/exams/{line}/results/validate` | results.validate |
| POST `/laboratory-orders/{order}/exams/{line}/results/rectify` | results.rectify |
| GET `/laboratory-orders/{order}/exams/{line}/results/validations` | results.validation_history |

Alta de profesional: user_id, professional_name, profession y registration_number opcional; devuelve 201 registered. Autorizar recibe objeto vacío; revocar recibe reason. Rectificar recibe revision/reason. Políticas y operaciones clínicas devuelven 200. Historial/listado paginan 50 registros por página; validaciones en ID descendente con sus rectificaciones. Los campos desconocidos o identidades aportadas para sustituir decisiones del servidor producen 422.

GET de resultados añade clinical_status, validated_revision, metadata de última validación con is_current, requisitos de contexto, referencias históricas aplicadas, capacidades can_validate/can_rectify/can_view_validations y bloqueantes administrativos/técnicos. Las capacidades son orientativas; la resolución del contexto se vuelve a comprobar al validar. Una última validación histórica no presenta los valores preliminares rectificados como validados. El PUT sin results.view omite parámetros y referencias aplicadas. results.validation_history permite leer la evidencia completa independientemente de results.view, según su permiso explícito.

La consulta operativa BE-S2-05 expone el estado clínico a través de result_capture.clinical_status; mantiene conteos/revisión y no carga/divulga valores clínicos, comentarios, contexto ni referencias aplicadas. Tener una validación no cambia processing_started ni operational_status ni la cabecera.

OpenAPI incluye las nueve operaciones, requests y schemas de política, profesionales, contexto, evidencia, rectificación, respuesta e historial; amplía estado/capacidades de BE-S2-06. Inventario: **112 rutas Laravel, 104 operaciones OpenAPI y 97 rutas con permiso**.

Conflictos 409: result_revision_conflict, result_validation_unavailable, result_validation_incomplete, result_already_validated, result_reference_unresolved, result_rectification_unavailable, professional_already_registered y result_not_editable existente. Mantienen message/code/errors. Autorización 403, pertenencia 404 y formato 422; los middleware mantienen sus respuestas previas.

## 8. Transacciones y concurrencia

Validación/rectificación bloquean primero cabecera y línea, compatibles con BE-S2-05/06. Después toman bloqueo compartido del laboratorio/política, usuario, membresía, grants efectivos RBAC y profesional cuando corresponde. La validación bloquea también el paciente al tomar contexto. Se releen permisos/membresía y política después de las esperas. Política y administración profesional toman bloqueo exclusivo del laboratorio; no adquieren cabeceras, evitando invertir ese orden.

Los cambios de usuario, membresía, permisos, política o autorización profesional se serializan con los registros leídos. Cancelación, captura y rectificación comparten cabecera. Una validación aprueba exactamente lo confirmado, o devuelve conflicto/403 si la condición dejó de cumplirse. Publicar otra versión no altera el ID fijado ni necesita reemplazar snapshots.

Auditoría: order_results.validated, order_results.rectified y result_validation.policy_changed/professional_registered/professional_authorized/professional_revoked. Los eventos usan campos seleccionados, IDs/revisiones/estado/motivos y política, sin copiar documentos arbitrarios al AuditEvent. Las tablas inmutables guardan la evidencia completa. Un fallo de auditoría revierte evidencia, revisión y estado juntos.

## 9. Dataset demostrativo

DatabaseSeeder añade LaboratoryValidationSeeder después de las capturas BE-S2-06. Utiliza la transacción exterior, registro de procedencia DemoDataset, bloqueo de producción y creación mediante servicios reales. No modifica las órdenes anteriores ni los roles demo existentes. Añade dos laboratorios nuevos registrados `DEMO-BE07-1/2`, con identidades/usuarios/catálogos/precios/pacientes propios; uno por política. La delegación permission_based se configura por el servicio y se audita. Los permisos clínicos demo se asignan explícitamente a nuevos usuarios registrados, nunca por nombre del rol.

Escenarios: profesional registrado sin autorizar, profesional autorizado, profesional revocado, usuario con permiso sin registro profesional, captura completa preliminar, validación profesional, validación por permiso, rectificación pendiente y rectificación revalidada. Son dos nuevas órdenes con cuatro/una líneas, precio propio 25,00, totales 100,00/25,00, sin muestra física. Dos nuevas publicaciones con cuatro parámetros ficticios y una referencia de demostración, claramente descritas **FICTICIO — DEMOSTRACIÓN. NO APTO PARA USO DIAGNÓSTICO REAL.**

La segunda ejecución carga las identidades registradas y no modifica políticas, autorizaciones, capturas, validaciones o correcciones manuales. No restablece contraseñas, adopta registros ajenos, cambia precios/defaults/roles de otros laboratorios ni abre la cohorte legacy. Conflictos de procedencia abortan toda la transacción. El seeder RolePermissionSeeder no se invoca dentro del dataset.

Conteos comprobados por CLI con igualdad de snapshots de todas las tablas en dos ejecuciones: 16 publicaciones, 22 órdenes, 56 líneas, 47 requisitos, 4 muestras, 7 capturas, 26 resultados vigentes, 5 validaciones, 2 rectificaciones, 3 profesionales y cero excepciones legacy. La CLI utiliza únicamente migrate:fresh en la base aislada; no cambia datos operativos.

## 10. Verificación y entrega

Exclusivamente proyecto Compose `donqer-be-s2-07` con deploy/testing/compose.yaml, PostgreSQL 17 en tmpfs, red interna sin puertos publicados, PHP 8.4 y código/configuración de testing en lectura. Guardas BE-S2-02, sin suites simultáneas en esa base. Los reportes finales se conservan en /tmp/be07-results; el proyecto temporal se retira al terminar la verificación.

```bash
docker compose -p donqer-be-s2-07 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-07 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalValidation
docker compose -p donqer-be-s2-07 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never
docker compose -p donqer-be-s2-07 -f deploy/testing/compose.yaml down --remove-orphans
```

**Resultado completo final (2026-10-11):** PHPUnit 12.5.35, PHP 8.4.25 y PostgreSQL 17.11. **3.160 casos, 19.941 aserciones, cero errores, cero fallos y tres omisiones SQLite existentes.** Duración: **6 min 55,781 s**; memoria del runner: 157 MB.

| Suite / subconjunto | Casos | Aserciones | Errores / fallos | Omitidos |
| --- | ---: | ---: | ---: | ---: |
| Unit | 46 | 70 | 0 / 0 | 0 |
| Feature | 3.114 | 19.871 | 0 / 0 | 3 |
| Total | 3.160 | 19.941 | 0 / 0 | 3 |
| ClinicalValidation, incluido en Feature | 55 | 276 | 0 / 0 | 0 |
| Concurrencia BE-S2-07, incluida en ClinicalValidation | 14 | 112 | 0 / 0 | 0 |
| ClinicalResults BE-S2-06, incluido en Feature | 36 | 230 | 0 / 0 | 0 |
| Dataset, incluido en Feature | 10 | 699 | 0 / 0 | 0 |

La cobertura BE-S2-07 verifica ambas políticas, roles sin autorización implícita, permiso explícito en otro rol, estado profesional registrado/autorizado/revocado, autoautorización, datos y recursos ajenos, revisiones, capturas incompletas, líneas sin inicio, cancelación, legacy/historical, precisión/snapshots, edición/limpieza SQL y API, rectificación/revalidación, auditoría/rollback, contexto literal, edad, condiciones, prioridades, ausencias, ambigüedad y cambio posterior de paciente/publicación. Los exámenes repetidos y el dataset conservan identidades y evidencia independientes.

Las catorce carreras ejecutan procesos y conexiones PostgreSQL independientes y verifican espera real en pg_stat_activity: captura contra validación, dos validaciones, validación contra captura, rectificación contra validación/captura/otra rectificación, revocación profesional, cambio de política, cancelación, desactivación de usuario, revocación de membresía y permiso, más rollback de validación y de política. No se simulan con una conexión compartida ni se omiten. Las regresiones BE-S2-03/04/05/06 y anteriores están incluidas.

Una batería dirigida previa pasó con 119 casos y 1.390 aserciones; las últimas variantes y los ajustes definitivos están incluidos en la suite completa final. Las tres omisiones son intención numérica específica de SQLite y dos comprobaciones de sus triggers de descuentos; no corresponden a validación ni concurrencia PostgreSQL.

La comprobación CLI final ejecutó migrate:fresh solo en PostgreSQL aislado y DatabaseSeeder dos veces con el código final. Confirmó los conteos del apartado 9 y snapshots idénticos de todas las tablas: SHA-256 `51d5352f0e016d46103f617d7132b67554944592d588b10e52aa5dfd69fe0a4a`. Las pruebas de reseeding también preservan cambios manuales de política y resultados. Laravel Pint (`--dirty --test --format agent`) pasó con el repositorio en lectura, sin red ni bases; git diff --check pasó.

Reportes: `/tmp/be07-results/full.xml` y `/tmp/be07-full.log` (finales); `/tmp/be07-results/directed.xml` y `/tmp/be07-directed.log` (batería dirigida); `/tmp/be07-demo-check.php` y `/tmp/be07-demo-check.log` (CLI final); `/tmp/be07-pint-final.log` (Pint). Los diagnósticos anteriores permanecen separados en `/tmp/be07-results/full-initial.xml`, `/tmp/be07-full-initial.log`, `/tmp/be07-directed-initial.log` y `/tmp/be07-directed-second.log`. No se presentan como ejecuciones finales.

La primera regresión detectó una selección accidental de validation_policy en el pivot de membresías; se corrigió para persistir y leer la política exclusivamente en laboratories. La suite completa final incluye la relación original de usuarios/laboratorios y pasa. El proyecto `donqer-be-s2-07` se retiró con down --remove-orphans después de conservar los reportes. No se ejecutaron migraciones ni seeders en desarrollo/producción ni commit/push.

Nuevos: migración BE-S2-07, modelos ValidationProfessional/LaboratoryResultValidation/LaboratoryResultRectification, servicios ClinicalValidation/ValidationAuthority/ValidationAdministrationService/LaboratoryValidationService/ReferenceResolver, ResultValidationController, ClinicalValidationDocumentation, LaboratoryValidationSeeder, pruebas ClinicalValidation y este documento. Modificados: Laboratory, captura y servicio/controlador/documentación BE-S2-06, RBAC, rutas, DatabaseSeeder, fixtures, inventarios, pruebas de seeders y retroceso de migraciones dependientes.

BE-S2-08/09 siguen separados: finalización clínica/financiera, resolución final individual excepcional, informes PDF/emisión/entrega, correcciones posteriores a orden completada, firma digital certificada y cumplimiento regulatorio especializado. No se implementan diagnósticos ni conversiones de unidades. El despliegue futuro requiere migración autorizada, revisión explícita de permisos y coordinación del estado validated, revisiones y reference_context con consumidores; no se ejecuta en esta sesión.

## 11. Inventario de archivos de esta entrega

### Creados

- `docs/sprint-2/BE-S2-07-validacion-resultados-clinicos.md`
- `laravel/app/ClinicalValidation/LaboratoryValidationService.php`
- `laravel/app/ClinicalValidation/ReferenceResolver.php`
- `laravel/app/ClinicalValidation/ValidationAdministrationService.php`
- `laravel/app/ClinicalValidation/ValidationAuthority.php`
- `laravel/app/Http/Controllers/Api/V1/ResultValidationController.php`
- `laravel/app/Models/LaboratoryResultRectification.php`
- `laravel/app/Models/LaboratoryResultValidation.php`
- `laravel/app/Models/ValidationProfessional.php`
- `laravel/app/OpenApi/ClinicalValidationDocumentation.php`
- `laravel/database/migrations/2026_10_11_000002_add_clinical_result_validation.php`
- `laravel/database/seeders/LaboratoryValidationSeeder.php`
- `laravel/tests/Feature/ClinicalValidation/ClinicalValidationTest.php`
- `laravel/tests/Feature/ClinicalValidation/ReferenceResolverTest.php`
- `laravel/tests/Feature/ClinicalValidation/ValidationConcurrencyTest.php`
- `laravel/tests/Feature/ClinicalValidation/ValidationPersistenceTest.php`
- `laravel/tests/Feature/ClinicalValidation/ValidationSecurityTest.php`

### Modificados

- `docs/sprint-2/BE-S2-03-configuracion-clinica-versionada.md`
- `docs/sprint-2/BE-S2-04-composicion-atomica-ordenes.md`
- `docs/sprint-2/BE-S2-05-gestion-muestras-fisicas.md`
- `docs/sprint-2/BE-S2-06-captura-resultados-clinicos.md`
- `laravel/app/Authorization/RbacCatalog.php`
- `laravel/app/ClinicalResults/LaboratoryResultService.php`
- `laravel/app/Http/Controllers/Api/V1/LaboratoryResultController.php`
- `laravel/app/Models/Laboratory.php`
- `laravel/app/Models/LaboratoryOrderResultCapture.php`
- `laravel/app/OpenApi/ClinicalResultDocumentation.php`
- `laravel/bootstrap/app.php`
- `laravel/database/seeders/DatabaseSeeder.php`
- `laravel/routes/api.php`
- `laravel/tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientActiveTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientPriceListStoreTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientStatusTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientUpdateTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderDiscountTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamDestroyTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamIndexTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamStoreTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderShowTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStatusTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStoreTest.php`
- `laravel/tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php`
- `laravel/tests/Feature/Authorization/ProtectedDomainAuthorizationTest.php`
- `laravel/tests/Feature/Authorization/RolePermissionSeederTest.php`
- `laravel/tests/Feature/ClinicalExams/ClinicalExamTest.php`
- `laravel/tests/Feature/ClinicalResults/ClinicalResultScenario.php`
- `laravel/tests/Feature/ClinicalResults/ClinicalResultTest.php`
- `laravel/tests/Feature/LaboratoryOrderSeederTest.php`
- `laravel/tests/Feature/OrderComposition/AtomicOrderCompositionTest.php`
- `laravel/tests/Feature/PhysicalSamples/PhysicalSampleTest.php`


## Actualización posterior — BE-S2-08 (2026-10-11)

BE-S2-08 incorpora resolución individual explícita, excepciones solicitadas y decididas por usuarios independientes y cierre clínico con evidencia inmutable. Una validación vigente no finaliza automáticamente una línea. Las líneas resueltas bloquean la rectificación ordinaria; el PATCH de estado no completa órdenes. Se aprobó el cierre explícito desde pending cuando absolutamente todas las líneas tienen excepciones aprobadas, conservando su origen y sin inventar procesamiento. La composición, publicaciones, resultados y excepciones legacy históricas permanecen intactos.

Se conserva la independencia financiera del cierre y se añade un escenario demo propio e idempotente. El catálogo vigente contiene 66 permisos y seis roles; las escrituras clínicas nuevas requieren concesión explícita. Las cifras anteriores corresponden a esta entrega histórica; el contrato y la verificación ampliados se documentan en [BE-S2-08 — Finalización clínica](BE-S2-08-finalizacion-clinica-ordenes.md). No se ejecutan estos cambios en entornos operativos ni commit/push.

## Actualización posterior — BE-S2-09.1

La emisión consume exclusivamente la validación inmutable fijada por la resolución BE08, con sus valores, parámetros, unidades, referencias/contexto y snapshot profesional. No valida, interpreta ni rectifica resultados al emitir. La identidad profesional ausente no se reconstruye desde usuarios actuales. Consultar [BE-S2-09.1 — Informes clínicos](BE-S2-09.1-generacion-emision-informes.md); las cifras BE07 anteriores son históricas.
