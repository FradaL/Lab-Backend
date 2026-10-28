# BE-EXC-02.3-I — Integración clínica de motivos estructurados

Fecha: 2026-10-25. Fuente: solicitud homónima, adjunto `122b95a8-36bf-480d-8f1b-43cc46dd41f2`. Fuente funcional: [DF-019 APROBADO 1.4](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md#fuente-de-ampliación-v14) y [DF-016 APROBADO 1.0](../../../donqer-lab-product/03-decisiones/DF-016-finalizacion-clinica.md) de donqer-lab-product. Este documento conserva la auditoría previa y registra contratos, implementación, compatibilidad y resultados ejecutados.

## Resultado y clasificación de componentes

**Integración global BE-EXC-02.3-I IMPLEMENTADA Y PROBADA** en solicitudes/aprobaciones HTTP y servicios reales BE08. Las nuevas solicitudes requieren referencia estructurada adoptada; no hay vía textual para crearlas ni bandera de exclusión histórica. Las solicitudes anteriores se preservan mediante un conjunto finito sellado, sin vincularlas por texto. La política y la escritura clínica comparten transacción y orden de bloqueos. No se declara terminado todo BE-EXC-02.3 ni todo DF-019: el alcance integral mantiene sus dependencias de catálogo local, evidencia/viabilidad y presentación clínica.

| Componente | Clasificación | Alcance |
| --- | --- | --- |
| Referencia global adoptada, versión y selección originales | IMPLEMENTADO Y PROBADO | Tabla separada e inmutable, FK tenant/clínicas, justificación y definición histórica verificables. |
| Solicitud y aprobación BE08, HTTP y servicios | IMPLEMENTADO Y PROBADO | Política única revalidada dentro de transacción; no reemplazo de referencia al aprobar. |
| Rechazo, consultas e historia | IMPLEMENTADO Y PROBADO | Sin bloqueo de catálogo para rechazar; historia original y referencia separada en lectura. |
| Autorización, auditoría y concurrencia | IMPLEMENTADO Y PROBADO | Permisos existentes, rollback atómico, nueve carreras nuevas con PostgreSQL real. |
| Compatibilidad histórica y cierre DF-016 | IMPLEMENTADO Y PROBADO | Conjunto anterior sellado, snapshots/filas/PDF intactos; nuevas solicitudes sobre líneas históricas también requieren referencia. No bloqueo retroactivo de resoluciones autorizadas. |
| Consumidores demo | IMPLEMENTADO Y PROBADO | Configuración/catálogo explícitos, sin bootstrap de facultades SaaS; fallo atómico sin configuración. |
| Administración/uso clínico local completo | FUERA DE ALCANCE | BE-EXC-02.4 pendiente; source local no habilitado. Discriminador preparado, sin tablas/API locales anticipadas. |
| Lecturas definitivas y verificación transversal | FUERA DE ALCANCE | 02.5/02.6 pendientes; pruebas de esta integración no cierran esos entregables. |
| Evidencia mínima/viabilidad integral, No realizado y frontend | FUERA DE ALCANCE | BE-EXC-03/04/05 y frontend conservan sus brechas; terminal_candidate no demuestra suficiencia clínica. |
| Migración/despliegue de entornos reales | FUERA DE ALCANCE | No ejecutados; requiere coordinación operativa autorizada. |

No se identificaron decisiones clínicas nuevas pendientes dentro del alcance integrado. No queda un componente global declarado IMPLEMENTADO, PENDIENTE DE INTEGRACIÓN que aún permita solicitudes nuevas sin política; los límites anteriores se registran expresamente, sin dar por terminado el alcance integral de excepciones.

## Auditoría previa a implementación

Prefijo `/api/v1/laboratory-orders/{order}`. Todas las rutas usan ClinicalFinalizationController, auth/Sanctum, contexto tenant, membresía/suscripción activa y permiso exacto en routes/api.php. ClinicalResolutionService revalida usuario/membresía/Teams/permisos en servicio mediante ValidationAuthority. No existe endpoint PATCH/PUT ni servicio para editar una solicitud pendiente: sólo transición inmutable mediante decisión. ReportDeliveryService::requestException es una excepción comercial de entrega distinta; no crea solicitudes clínicas ni resoluciones y queda fuera de esta política.

| Operación/ruta | Servicio / transacción | Permiso / validación previa | Campos y efectos |
| --- | --- | --- | --- |
| POST exams/{line}/exceptions | requestException; DB::transaction, orden y línea exclusivas | orders.clinical_exceptions.request; orden abierta, revisiones vigentes, sin resolución/pendiente/validación histórica | clinical_revision, result_revision, reason libre; inserta solicitud pending y auditoría, aumenta revisión clínica, no resuelve. |
| GET exams/{line}/exceptions | exceptions; consulta tenant | orders.clinical_exceptions.view y pertenencia orden/línea | Historia paginada, texto original y decisión. |
| POST exams/{line}/exceptions/{exception}/approve | decideException(true); DB::transaction, orden/línea/solicitud exclusivas | orders.clinical_exceptions.approve, otro usuario, pending, request_revision, captura/inicio sin cambios, sin validación histórica | clinical_revision, request_revision, reason opcional; decisión approved y resolución authorized_exception atómicas, auditoría y revisión clínica. |
| POST exams/{line}/exceptions/{exception}/reject | decideException(false); misma transacción | orders.clinical_exceptions.approve, revisión vigente | reason obligatorio; decisión rejected, sin resolución, historial preservado. |
| POST exams/{line}/finalize | finalizeLine; orden/línea exclusivas | orders.exams.clinical_finalize, in_process, inicio, validación exacta vigente y sin excepción pendiente | Fija validated_result; no aprueba una excepción. |
| POST finalize | finalizeOrder; orden/líneas exclusivas | orders.clinical_finalize, todas resueltas, sin pendientes; desde pending todas authorized_exception | Cierre explícito y snapshot inmutable; sin pago/emisión/entrega. |
| GET clinical-finalization | show; lectura compartida | orders.clinical_finalization.view | Progreso e indicadores orientativos; no escribe evidencia. |
| Servicios internos y demo | Mismos métodos públicos | Mismas comprobaciones | Dos consumidores demo: LaboratoryFinalizationSeeder y LaboratoryReportEvidenceSeeder; no vía clínica alternativa. |

`reason` se utiliza en constraints y triggers de solicitudes/decisiones, evidencia de resolución, auditoría, historial y renderer. report_line_snapshot serializa filas completas mediante to_jsonb(q/d/r) en 2026_10_11_000004_create_clinical_report_issuances.php. No añadir columnas a esas filas ni cambiar esas funciones: una tabla complementaria puede guardar referencias y justificación manteniendo el texto de solicitud como representación derivada de la versión. No inferir identidad por texto.

## Estrategia de transición

Nuevas solicitudes: referencia global adoptada obligatoria, versión y selección exactas, justificación separada. Sin catálogo válido se rechaza explícitamente la solicitud; no se crea un bypass textual. Origen local reconocido pero no disponible hasta 02.4. Solicitudes anteriores: se identifican explícitamente al aplicar la migración, sin modificar sus filas/texto/snapshots y sin asociarlas por nombre; consultas, rechazo y decisión histórica conservan el circuito BE08. La distinción debe ser finita e imposible de elegir por payload: no una modalidad legacy para solicitudes nuevas. No hay migración comercial ni conversión de datos clínicos.

Finalización: no introducir bloqueo retroactivo por catálogo sobre resoluciones ya aprobadas. Una solicitud pendiente bloquea finalización bajo las reglas existentes; finalizeLine no aprueba excepciones y finalizeOrder no crea resoluciones. Nuevas solicitudes/aprobaciones son los puntos donde integrar la política.

## Diseño de integración

Reutilizar ClinicalReasonEligibilityPolicy::assertAllowed dentro de la transacción clínica. Adquirir advisory lock SaaS (182045,1) antes de filas orden/línea y autorización, conservándolo hasta confirmar solicitud/decisión/auditoría. Aprobación recupera exclusivamente la referencia persistida, aunque el owner haya adoptado otra versión; actualizar adopción no borra obligaciones. No conceder review_impact ni aprobación por selección o rol owner. Mantener las comprobaciones BE08 y no presentar elegibilidad del catálogo como evidencia mínima/viabilidad completas de BE-EXC-03/04.

Estado inicial: implementación y nuevas pruebas pendientes. No se aplicaron migraciones ni seeders a desarrollo/producción, no se modificó Trello, sin commits ni push.

## Contrato implementado para nuevas solicitudes

Se conserva POST `/api/v1/laboratory-orders/{order}/exams/{line}/exceptions`, permiso `orders.clinical_exceptions.request`; no se crea endpoint alternativo. Ejemplo sintético (IDs ilustrativos, deben consultarse en la adopción real):

```json
{
  "clinical_revision": 1,
  "result_revision": 0,
  "reason_reference": {
    "source": "global",
    "reason_id": 10,
    "version_id": 20,
    "adoption_id": 30,
    "adoption_version_id": 40
  },
  "justification": "FICTICIO: justificación clínica independiente"
}
```

Las revisiones y todos los IDs globales son enteros JSON positivos (revisiones >=0). Se rechazan claves desconocidas, referencias manipuladas y justificación blanca/mayor de 2000 caracteres. reason_reference identifica identidad, versión y selección adoptada exacta del laboratorio. Debe coincidir con su selección actual al crear; una publicación global no sustituye la selección. El payload textual anterior con `reason` no crea nuevas solicitudes: 422, sin escritura. Origen local se reconoce pero devuelve 409 `clinical_reason_source_not_available`; no se implementa administración local.

El servicio deriva `clinical_exception_requests.reason` del nombre histórico inmutable de la versión elegida; nunca confía en un nombre aportado por el cliente. La justificación se guarda separadamente en la referencia. La definición completa se reconstruye por FK a la versión inmutable, sin usar el catálogo actual ni duplicar definiciones mutables. response.data.reason_reference contiene sus IDs, source, justification y definition (nombre, descripción, clase, requisitos y vigencia históricos). Las filas clínicas originales conservan sus columnas y forma de serialización; la referencia se expone separadamente, también en la historia paginada. Para solicitudes anteriores es null.

Aprobación/rechazo mantienen clinical_revision, request_revision y reason opcional/obligatorio respectivamente. No admiten reason_reference ni adoption_id de sustitución. Aprobar recupera la referencia de esa solicitud en el laboratorio/orden/línea correctos y reevalúa la misma identidad, aunque haya otra adopción vigente; nunca reescribe la versión original. Rechazar no consume elegibilidad de catálogo ni resuelve el examen. No se agrega edición de solicitudes pendientes.

Errores: 401/403 conservan autenticación y permisos; 404 recurso/identidad/adopción ausente o ajena; 422 contrato inválido o campos desconocidos; 409 `clinical_reason_selection_conflict` para selección/versión no actualmente adoptada al solicitar, `clinical_reason_use_blocked` para elegibilidad, `clinical_reason_not_terminal` para incidence_only y `clinical_reason_source_not_available` para origen local. Las demás revisiones/conflictos BE08 permanecen. OpenAPI actualiza entradas, referencia y respuestas de las operaciones existentes; no agrega rutas ni permisos.

## Persistencia y compatibilidad histórica

Nueva migración [2026_10_25_200359_create_clinical_exception_reason_references.php](../../laravel/database/migrations/2026_10_25_200359_create_clinical_exception_reason_references.php), exclusivamente PostgreSQL:

- `clinical_exception_reason_references`: referencia inmutable única por solicitud, laboratorio/orden/línea, source, identidad/versión global, adopción/selección original, justificación, actor y fecha servidor. FK compuestas aseguran pertenencia clínica y tenant, selección/adopción y versión/identidad. Un trigger verifica actor original, nombre derivado y selección exacta; UPDATE/DELETE se rechazan. La forma actual sólo habilita global; 02.4 requerirá ampliar explícitamente las referencias y FK para local.
- `clinical_exception_legacy_requests`: conjunto finito de IDs existentes al aplicar la migración, bajo bloqueo de la tabla y transacción. Después queda sellado contra INSERT/UPDATE/DELETE. No copia motivo, estado, decisión, informe o snapshot, ni crea una relación con catálogo. El cliente no puede declarar una solicitud nueva como histórica. La antigüedad de la línea o su clinical_configuration_source no otorgan esa excepción: una solicitud nueva sobre una línea histórica también exige referencia estructurada.
- Constraint trigger diferido en nuevas solicitudes: no confirma INSERT sin referencia estructurada. Solicitud y dependencia deben existir juntas al confirmar; no hay nueva ruta textual ni una modalidad seleccionable para eludirlo. No se implementa una segunda política clínica en SQL: las comprobaciones de impacto/revisión pertenecen a la política existente en el servicio autorizado.
- `down()` rechaza referencias clínicas existentes. Sin referencias retira sólo esta infraestructura; no borra solicitudes ni altera evidencia. La reaplicación reconstruye el conjunto previo desde filas existentes. Los tests de rollback de entregas anteriores respetan las nuevas FK y retiran primero esta migración vacía.

La migración no añade columnas a clinical_exception_requests, clinical_exception_decisions o clinical_line_resolutions; no cambia report_line_snapshot, clinical_closure_snapshot, formato `donqer-clinical-1`, renderer ni PDFs. La prueba de actualización crea solicitud/decisión/resolución/informe y PDF antes de aplicar la migración, y compara filas, función SQL de snapshot y bytes del PDF después. Otra prueba decide una solicitud pendiente preexistente sin fabricar identidad estructurada.

Los solicitantes sin adopción válida deben preparar el catálogo y seleccionar/adoptar explícitamente antes de presentar una solicitud nueva. El soporte local depende de 02.4: no convertir un personalizado en global por nombre, ni habilitar texto libre temporal para nuevas solicitudes. Se preservan las operaciones históricas del conjunto sellado bajo BE08, sin afirmar que tengan dependencias globales demostrables.

## Política única y alcance clínico

[ClinicalExceptionReasonGuard](../../laravel/app/ClinicalFinalization/ClinicalExceptionReasonGuard.php) resuelve la identidad y llama a `ClinicalReasonEligibilityPolicy::assertAllowed(request/approve)`; no reemplaza ni duplica la política. Se conservan sus reglas: todas las versiones publicadas y declaraciones vigentes, falta de clasificación como bloqueo, obligaciones restrictivas/desactivación pendientes, sólo favorable resuelve su obligación, no favorable mantiene pendiente, corrección de impacto/adopción posterior no borra obligaciones y motivo inactivo impide solicitudes nuevas. Revisar favorablemente no aprueba una excepción ni elimina otros bloqueantes.

Además se respeta EXC-08: incidence_only no puede entrar a la aprobación BE08 que siempre genera resolución terminal; terminal_candidate sólo permite continuar los controles existentes, no acredita suficiencia clínica ni viabilidad. BE-EXC-03/04 siguen pendientes para mínimos M01–M07, evaluaciones de reposición/alternativas y resolución completa No realizado. No se inventa una evaluación favorable ni se presenta el catálogo como implementación de esas garantías.

La finalización conserva DF-016: una solicitud pendiente impide resolver/finalizar, y una orden exige todas las resoluciones definitivas sin pendientes. finalizeLine sólo fija validación exacta de una línea iniciada, no aprueba excepciones. Una resolución ya autorizada no se invalida por un cambio posterior del catálogo: no se crea bloqueo retroactivo de cierre ni se reescribe historia. Los indicadores de lectura de BE08 siguen siendo orientativos y no evalúan un motivo todavía no seleccionado; la escritura exige referencia y política.

## Transacciones, permisos y auditoría

Orden para solicitud, aprobación y rechazo: transacción → advisory lock SaaS `(182045,1)` → orden/línea → comprobaciones BE08 y autorización efectiva → solicitud/dependencia → política y escritura → auditoría → commit. Los consumidores internos deben entrar sin locks previos incompatibles sobre órdenes/selecciones. No invertir orden con publicación, impactos, adopción y evaluaciones 02.3. El lock se mantiene hasta terminar la transacción exterior, incluidas llamadas anidadas; PostgreSQL obligatorio.

Si una restricción confirma primero, la siguiente solicitud/aprobación dependiente falla. Si la operación clínica confirma primero, conserva su evidencia y la restricción posterior abre una obligación para usos posteriores; no revoca decisiones ya confirmadas. Cambiar adopción no cambia la referencia original ni permite eludir revisiones. La consulta GET de elegibilidad no reserva autorización. Otras identidades/laboratorios no reciben un bloqueo funcional por esa revisión, aunque las operaciones que usan este lock administrativo se serializan transitoriamente.

Se mantienen permisos request/approve y autorización BE08 en servicio (usuario/membresía/Teams y permisos revalidados después de bloquear, filas de concesión compartidas). La política revalida también su autoridad tenant y suscripción; no requiere conceder permisos administrativos de catálogo al clínico. review_impact no otorga approve. No hay nuevas facultades, roles globales, permisos implícitos del owner o cambios al catálogo RBAC.

Los eventos clínicos existentes añaden referencias allowlist a contexto, motivo/versión y decisión de elegibilidad: IDs de declaraciones vigentes y evaluaciones favorables observadas, adopción y resultado allowed. Actor, fecha y solicitud se conservan por sus filas/eventos. La evaluación/justificación clínica completa queda en su registro específico, sin volcar payloads/modelos/snapshots completos al log. Un fallo de auditoría revierte solicitud, referencia, revisiones o decisión/resolución de la misma operación. Los eventos históricos no se sobrescriben. Rechazos por autorización/política no generan éxitos ficticios ni una nueva bitácora de todos los intentos fallidos.

## Consumidores de demostración

LaboratoryFinalizationSeeder y LaboratoryReportEvidenceSeeder envían ahora referencia estructurada y justificación, y pasan por el mismo servicio/política. [DemoClinicalReason](../../laravel/database/seeders/DemoClinicalReason.php) requiere `SAAS_DEMO_CLINICAL_REASON_ID` explícito: motivo global FICTICIO/terminal_candidate previamente creado y clasificado por un operador SaaS autorizado. No ejecuta bootstrap SaaS, no crea cuentas globales ni concede facultades del proveedor. Los actores demo ya tienen sus permisos directos clínicos; su selección individual se realiza mediante el manager de adopción y rol owner sólo en su laboratorio sintético registrado por DemoDataset.

Sin configuración/catálogo válido la generación demo falla con diagnóstico explícito y rollback del dataset; no crea solicitudes libres ni omite silenciosamente escenarios clínicos. Para una instalación limpia: aprovisionar accesos y motivo ficticio explícitamente en entorno autorizado, clasificarlo, configurar su ID efectivo y luego ejecutar el dataset demo. No se ejecutó este procedimiento en desarrollo/producción. Las pruebas preparan el catálogo sintético dentro de su propia base temporal y verifican el dataset/idempotencia con referencias incluidas.

## Verificación ejecutada

Entorno: Compose `donqer-be-exc-023i`, PostgreSQL 17 temporal en tmpfs, PHP 8.4.25, Laravel 13.32.0 y PHPUnit 12.5.35. TestDatabaseGuard valida la configuración y la identidad real del servidor. Código y configuración testing montados en lectura; red interna sin puertos. Sin pruebas sobre desarrollo/producción.

| Batería final | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| Regresión dirigida | 456 | 3.655 | Sin fallos, errores u omisiones |
| Contratos, rutas y OpenAPI | 192 | 1.883 | Sin fallos, errores u omisiones |
| Muestras y persistencia de validación | 56 | 435 | Sin fallos, errores u omisiones |

La batería dirigida incluye 40 casos nuevos: 39 de integración estructurada (30 HTTP/servicios/SQL/contrato e historia y nueve carreras) y uno de demo sin catálogo. Comprueba identidad/adopción/versiones, aislamiento, manipulación, clasificación ausente/explícita, revisión favorable/no favorable, cambio de adopción y corrección de impacto, permisos revocados, independencia de motivos, rechazo, cierre, auditoría atómica e inmutabilidad. Las carreras usan procesos/conexiones independientes y verifican espera real con pg_stat_activity; cubren ambos órdenes de solicitud/restricción y aprobación/restricción, adopción/aprobación, evaluación favorable/no favorable frente a aprobación, revocación y otro laboratorio.

Regresión: finalización clínica, identidades/versiones, SaaS/accesos, impacto/adopción/revisión, autenticación, tenancy/Teams/RBAC, informes y entregas, dataset/idempotencia, muestras físicas y persistencia de validación. OpenAPI mantiene 165 operaciones, 173 rutas del bootstrap de tests y 140 rutas tenant con can:; no se agregan rutas o permisos. Los tests y los contratos prueban el consumidor real, no sólo assertAllowed aislado.

Las baterías pueden solaparse (incluido el nuevo caso OpenAPI); no se suman como casos únicos. **No se ejecutó la suite completa del repositorio.** Los resultados de la tabla son las ejecuciones finales satisfactorias. Las ejecuciones iniciales detectaron correcciones de fixtures: contexto Teams después de HTTP, evidencia JSON objeto, frontera transaccional del emisor, conteos de usuarios externos al dataset y orden de rollback/reaplicación con las nuevas FK. Se corrigieron conservando las comprobaciones originales; la regresión dirigida, contratos y adicional se volvieron a ejecutar en su alcance pertinente.

Pint aplicado a los archivos PHP de esta entrega y sintaxis PHP comprobada; git diff --check sin incidencias. Se conservan la política existente, rutas, catálogo RBAC, migraciones clínicas previas, funciones de snapshots, renderer, instrucciones y dependencias. Documentación de producto sincronizada respetando DF-019 APROBADO 1.4, sus dieciocho decisiones y el expediente literal 1.3, y DF-016 R1–R5; AUD-002 conserva todos los cortes anteriores.

Logs locales de ejecución: `/tmp/be-exc-023i-final-regression.log`, `/tmp/be-exc-023i-contracts.log`, `/tmp/be-exc-023i-supplement-final.log`. Las seis verificaciones focalizadas de fixtures pasaron con 33 aserciones antes de la regresión final; no se agregan al conteo de casos únicos.

## Riesgos y dependencias para BE-EXC-02.4

- Implementar administración local con referencia propia y FK tenant/versión, autorización owner y auditoría; ampliar source sin tratar locales como adopciones/clones globales. Hasta entonces local devuelve conflicto explícito.
- No presentar control de catálogo como evidencia mínima/viabilidad integral: BE-EXC-03/04, informe No realizado/BE-EXC-05, lecturas definitivas 02.5 y verificación transversal 02.6 conservan su alcance pendiente.
- Despliegue requiere aplicar migración y código coordinadamente sin escritores antiguos en ejecución; el nuevo trigger impide confirmar nuevas solicitudes textuales. No se aplicaron migraciones ni concesiones en entornos reales.
- El lock global reutilizado limita concurrencia administrativa; mantener el orden en consumidores futuros. No se añade cola de revisiones ni caché de autorización que deje una ventana clínica.
- El conjunto previo conserva texto y sus operaciones originales; no permite acreditar restricciones globales de solicitudes sin una identidad histórica demostrable. No inferir vínculos ni editar snapshots para llenar ese vacío.

## Reproducción de las comprobaciones

Desde la raíz backend-lab, únicamente contra PostgreSQL temporal. TestDatabaseGuard verifica entorno testing, configuración whitelist e identidad real PostgreSQL 17 antes de operar. Compose monta aplicación y configuración testing en lectura, storage/cache temporales, red interna sin puertos y PostgreSQL en tmpfs; no utiliza la base ni el archivo .env de desarrollo.

```bash
docker compose -p donqer-be-exc-023i -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-exc-023i -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalFinalization tests/Feature/ClinicalReasons tests/Feature/SaasAccess tests/Feature/Api/V1/Auth tests/Feature/Authorization tests/Feature/Saas tests/Feature/ClinicalReports tests/Feature/ReportDelivery tests/Feature/LaboratoryOrderSeederTest.php
docker compose -p donqer-be-exc-023i -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature --filter '(contract|openapi|route)'
docker compose -p donqer-be-exc-023i -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalValidation/ValidationPersistenceTest.php tests/Feature/PhysicalSamples
docker compose -p donqer-be-exc-023i -f deploy/testing/compose.yaml down --remove-orphans
```

Las baterías se ejecutan secuencialmente, nunca en paralelo sobre esa base. El registro final distingue ejecuciones satisfactorias de intentos iniciales corregidos, sin sumar casos potencialmente solapados ni atribuir una suite completa no ejecutada.

## Archivos de esta entrega

Comparación contra el árbol de trabajo al iniciar esta solicitud, que ya contenía cambios de 02.3 y de sincronización documental sin commit. Se preservan esos cambios; un archivo previamente no versionado no se atribuye como nuevo de esta entrega por su estado Git. Sin cambios a dependencias, AGENTS/CLAUDE o configuración de agentes. El ejemplo de entorno añade únicamente la variable demo explícita; el archivo .env real no se modifica.

### Creados en backend (8)

- [docs/sprint-2/BE-EXC-02.3-I-integracion-clinica.md](BE-EXC-02.3-I-integracion-clinica.md)
- [laravel/app/ClinicalFinalization/ClinicalExceptionReasonGuard.php](../../laravel/app/ClinicalFinalization/ClinicalExceptionReasonGuard.php)
- [laravel/app/Models/ClinicalExceptionReasonReference.php](../../laravel/app/Models/ClinicalExceptionReasonReference.php)
- [laravel/database/migrations/2026_10_25_200359_create_clinical_exception_reason_references.php](../../laravel/database/migrations/2026_10_25_200359_create_clinical_exception_reason_references.php)
- [laravel/database/seeders/DemoClinicalReason.php](../../laravel/database/seeders/DemoClinicalReason.php)
- [laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionConcurrencyTest.php](../../laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionConcurrencyTest.php)
- [laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionTest.php](../../laravel/tests/Feature/ClinicalFinalization/StructuredClinicalExceptionTest.php)
- [laravel/tests/Support/StructuredClinicalReasonFixture.php](../../laravel/tests/Support/StructuredClinicalReasonFixture.php)

### Modificados en backend (23)

- [README.md](../../README.md)
- [docs/sprint-2/BE-EXC-02.3-implementacion.md](BE-EXC-02.3-implementacion.md)
- [docs/sprint-2/BE-EXC-02.3-seleccion-adopcion-diagnostico.md](BE-EXC-02.3-seleccion-adopcion-diagnostico.md)
- [docs/sprint-2/BE-S2-08-finalizacion-clinica-ordenes.md](BE-S2-08-finalizacion-clinica-ordenes.md)
- [laravel/.env.example](../../laravel/.env.example)
- [laravel/app/ClinicalFinalization/ClinicalResolutionService.php](../../laravel/app/ClinicalFinalization/ClinicalResolutionService.php)
- [laravel/app/OpenApi/ClinicalFinalizationDocumentation.php](../../laravel/app/OpenApi/ClinicalFinalizationDocumentation.php)
- [laravel/config/saas.php](../../laravel/config/saas.php)
- [laravel/database/seeders/LaboratoryFinalizationSeeder.php](../../laravel/database/seeders/LaboratoryFinalizationSeeder.php)
- [laravel/database/seeders/LaboratoryReportEvidenceSeeder.php](../../laravel/database/seeders/LaboratoryReportEvidenceSeeder.php)
- [laravel/tests/Feature/ClinicalFinalization/ClinicalFinalizationApiTest.php](../../laravel/tests/Feature/ClinicalFinalization/ClinicalFinalizationApiTest.php)
- [laravel/tests/Feature/ClinicalFinalization/ClinicalFinalizationConcurrencyTest.php](../../laravel/tests/Feature/ClinicalFinalization/ClinicalFinalizationConcurrencyTest.php)
- [laravel/tests/Feature/ClinicalFinalization/ClinicalFinalizationIntegrityTest.php](../../laravel/tests/Feature/ClinicalFinalization/ClinicalFinalizationIntegrityTest.php)
- [laravel/tests/Feature/ClinicalFinalization/ClinicalHistoricalResolutionTest.php](../../laravel/tests/Feature/ClinicalFinalization/ClinicalHistoricalResolutionTest.php)
- [laravel/tests/Feature/ClinicalFinalization/ClinicalResolutionTest.php](../../laravel/tests/Feature/ClinicalFinalization/ClinicalResolutionTest.php)
- [laravel/tests/Feature/ClinicalReasons/ClinicalReasonPersistenceTest.php](../../laravel/tests/Feature/ClinicalReasons/ClinicalReasonPersistenceTest.php)
- [laravel/tests/Feature/ClinicalReasons/GlobalClinicalReasonAdministrationTest.php](../../laravel/tests/Feature/ClinicalReasons/GlobalClinicalReasonAdministrationTest.php)
- [laravel/tests/Feature/ClinicalReports/ClinicalReportTest.php](../../laravel/tests/Feature/ClinicalReports/ClinicalReportTest.php)
- [laravel/tests/Feature/ClinicalResults/ClinicalResultScenario.php](../../laravel/tests/Feature/ClinicalResults/ClinicalResultScenario.php)
- [laravel/tests/Feature/ClinicalValidation/ValidationPersistenceTest.php](../../laravel/tests/Feature/ClinicalValidation/ValidationPersistenceTest.php)
- [laravel/tests/Feature/LaboratoryOrderSeederTest.php](../../laravel/tests/Feature/LaboratoryOrderSeederTest.php)
- [laravel/tests/Feature/PhysicalSamples/PhysicalSampleTest.php](../../laravel/tests/Feature/PhysicalSamples/PhysicalSampleTest.php)
- [laravel/tests/Feature/ReportDelivery/ReportDeliverySeederTest.php](../../laravel/tests/Feature/ReportDelivery/ReportDeliverySeederTest.php)

### Documentación funcional actualizada (13)

- [01-contexto/inventario-funcional.md](../../../donqer-lab-product/01-contexto/inventario-funcional.md)
- [02-reglas-negocio/muestras.md](../../../donqer-lab-product/02-reglas-negocio/muestras.md)
- [02-reglas-negocio/resultados.md](../../../donqer-lab-product/02-reglas-negocio/resultados.md)
- [03-decisiones/DF-016-finalizacion-clinica.md](../../../donqer-lab-product/03-decisiones/DF-016-finalizacion-clinica.md)
- [03-decisiones/DF-019-excepciones-clinicas-v1.md](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md)
- [03-decisiones/README.md](../../../donqer-lab-product/03-decisiones/README.md)
- [03-decisiones/registro-decisiones.md](../../../donqer-lab-product/03-decisiones/registro-decisiones.md)
- [04-flujos/README.md](../../../donqer-lab-product/04-flujos/README.md)
- [06-especificaciones/README.md](../../../donqer-lab-product/06-especificaciones/README.md)
- [06-especificaciones/plan-excepciones-clinicas-v1.md](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md)
- [07-auditorias/AUD-002-excepciones-clinicas-v1.md](../../../donqer-lab-product/07-auditorias/AUD-002-excepciones-clinicas-v1.md)
- [07-auditorias/README.md](../../../donqer-lab-product/07-auditorias/README.md)
- [README.md](../../../donqer-lab-product/README.md)

Sin migraciones/seeders en desarrollo o producción, cambios Trello, commits ni push. El PostgreSQL y la red temporales fueron retirados después de verificar los resultados.
