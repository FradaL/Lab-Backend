# BE-S2-04 — Composición atómica de órdenes con versiones clínicas y requisitos

**Estado:** implementado y validado con PostgreSQL aislado, incluido el cierre de seeders demostrativos con procedencia explícita. El usuario aprobó el corte legacy de exámenes existentes sin inscripción clínica al migrar. No se ejecutaron migraciones sobre desarrollo o producción ni se hicieron commits o push.

## 1. Alcance y compatibilidad aprobada

Se revisaron `docs/DONQER_LAB_CICLO_DE_VIDA_ORDENES.md`, BE-S2-01, BE-S2-03 y las acciones, contratos y pruebas de órdenes. Se reutilizan `CreateLaboratoryOrder`, `AddExamToLaboratoryOrder`, `RemoveExamFromLaboratoryOrder`, `TransitionLaboratoryOrderStatus`, el recálculo económico y la auditoría transaccional existentes. La integración clínica diferida en BE-S2-03 queda implementada para las **líneas nuevas**.

El usuario aprobó expresamente el corte legacy: **exámenes existentes sin inscripción clínica al aplicar esta migración**. `laboratory_order_legacy_exams` conserva esa lista cerrada por laboratorio. Los inscritos antes del corte, aunque solo tengan un borrador, y todas las altas posteriores necesitan publicación. No basta con usar el POST antiguo de catálogo ni con enviar un ID clínico null.

Un examen incluido en la lista puede agregarse sin versión mientras nunca haya tenido una publicación. Si se incorpora clínicamente y publica, todas sus líneas nuevas toman la publicación vigente; las líneas anteriores permanecen intactas. Una inscripción posterior con borrador no elimina por sí sola su elegibilidad original. Ninguna línea legacy permite procesamiento clínico.

Las líneas existentes al migrar conservan sus atributos anteriores y reciben únicamente columnas clínicas null. No se generan versiones, requisitos, muestras, rangos ni contenido clínico para ellas. La respuesta identifica esas líneas como `historical`; las nuevas excepciones llevan `legacy_compatibility`, y las nuevas líneas clínicas `published_version`.

## 2. Persistencia y garantías PostgreSQL

Migración aditiva: `laravel/database/migrations/2026_10_10_000002_add_order_clinical_composition.php`.

| Estructura | Cambio y garantía |
| --- | --- |
| `laboratory_order_exams` | `clinical_version_id`, `clinical_configuration_source` y `clinical_activity_started_at`, inicialmente nullable. FK compuesta laboratorio/examen/versión. |
| `laboratory_order_legacy_exams` | Laboratorio, examen y fecha del corte; PK compuesta y FK al examen local. Snapshot sin inscripción clínica, protegido contra INSERT/UPDATE/DELETE posteriores. |
| `laboratory_order_sample_requirements` | Identidad propia, laboratorio, orden, línea, examen, versión, clave, posición, tipo local, documento snapshot, estado y marcador de actividad. |

La migración bloquea los catálogos de exámenes e inscripciones durante el corte. No actualiza atributos comerciales, estados, órdenes ni líneas anteriores. La FK de cinco columnas de cada requisito exige pertenencia a la misma línea, laboratorio, orden, examen y versión. Otra FK impide usar un tipo de muestra de otro laboratorio. La clave de requisito es única dentro de su línea; repetir un examen crea otras identidades y requisitos independientes.

Los triggers PostgreSQL rechazan una nueva línea sin publicación salvo la excepción explícita del corte, una versión borrador y cualquier cambio posterior de ownership, versión u origen clínico. No se puede convertir una línea histórica en clínica por UPDATE. El origen y documento de cada requisito deben coincidir exactamente con la posición, clave y tipo de muestra del documento publicado; después son inmutables. El marcador de primera actividad, una vez escrito, no se puede cambiar o borrar y protege contra eliminación SQL. Las FK siguen protegiendo la pertenencia aun fuera de la aplicación.

El único estado operativo de requisito implementado es `pending`. El CHECK lo exige; BE-S2-05 deberá ampliar deliberadamente ese contrato cuando implemente muestras. No existen muestras físicas, recepción, contenedores, alícuotas, resultados ni asociaciones físicas en esta entrega. Los triggers no sustituyen las autorizaciones y validaciones comerciales del servicio; no se promete protección equivalente en SQLite.

El down elimina las estructuras y columnas nuevas, incluido su historial. Solo procede antes de usar el módulo o con una estrategia explícita de respaldo/restauración. No revierte estados ni reconstruye información clínica.

## 3. Creación atómica, adición y bloqueos

`POST /api/v1/laboratory-orders` admite `exams` omitido, `[]` o una lista de hasta 50 objetos `{"laboratory_exam_id": 12}`. Las repeticiones cuentan para el límite y no se deduplican. Los IDs son enteros JSON positivos; null, objetos en lugar de listas, claves desconocidas y estructuras incompletas producen 422 sin escrituras.

Antes de escribir se comprueba `orders.create` mediante la ruta y, para una lista no vacía, `orders.add_exam` mediante Request y action. No se crean nuevos permisos. La adición independiente conserva su permiso existente.

La action de creación resuelve y bloquea las referencias activas de cabecera dentro de la misma transacción, crea la cabecera y llama a `AddExamToLaboratoryOrder` para cada selección. Así utiliza exactamente las reglas existentes de lista de precios, moneda, precio activo, estado pending, snapshots, recálculo y auditoría. Las transacciones anidadas no confirman datos antes de la transacción exterior. Una validación posterior, fallo clínico o fallo de auditoría revierte cabecera, todas las líneas, requisitos, totales y eventos.

La adición bloquea cabecera de orden, lista de precios, examen y precio, en ese orden. La cabecera y el examen usan bloqueo exclusivo; lista y precio compartido. Para la creación múltiple se bloquean previamente todos los exámenes seleccionados por ID ascendente después de la lista, evitando inversiones entre listas con varios exámenes. El precio se lee bajo bloqueo, nunca desde un valor enviado por el cliente.

Para una línea clínica se invoca `ClinicalExamService::orderConfiguration()` dentro de la transacción de adición. El bloqueo del examen permanece hasta confirmar la transacción exterior y serializa publicación y desactivación. Se guarda el ID publicado seleccionado; ninguna lectura posterior sustituye ese ID por el vigente. La publicación de otra versión y cambios posteriores de precio, nombre o tipo de muestra no cambian los snapshots de una línea existente.

Cada requisito físico publicado produce una fila `pending` con snapshot completo, incluido `sample_type_name`, volumen/unidad, recipiente e instrucciones si estaban configurados. No se agrupan requisitos de igual tipo. `sample_mode: none` no genera requisitos físicos. La selección simple `laboratory_exams.sample_type_id` no sustituye al documento clínico.

## 4. Endpoints, respuestas y errores

Se conservan las rutas, Sanctum SPA, usuario activo, contexto `X-Laboratory-ID`, membresía, Teams, suscripción y permisos. No hay endpoints nuevos: siguen nueve operaciones de órdenes, 88 rutas Laravel, 80 operaciones OpenAPI y 45 permisos del conjunto BE-S2-03/04.

| Endpoint existente | Cambio |
| --- | --- |
| POST `/api/v1/laboratory-orders` | Lista `exams` opcional y máximo 50; permisos combinados cuando no está vacía; respuesta 201 con cabecera, totales y `exams`. |
| POST `/api/v1/laboratory-orders/{order}/exams` | Fija versión o excepción legacy explícita y crea requisitos en la misma transacción. |
| GET `/api/v1/laboratory-orders/{order}/exams` | Conserva paginación y snapshots; añade identidad/origen clínico y requisitos de cada línea. |
| DELETE `/api/v1/laboratory-orders/{order}/exams/{line}` | Exige pending y ausencia de actividad; elimina solo los requisitos pendientes propios y recalcula/audita. Conserva 204. |
| PATCH `/api/v1/laboratory-orders/{order}/status` | Impide iniciar una orden vacía o sin ninguna línea clínicamente procesable. |

La creación conserva todos los campos anteriores y añade `exams`, incluso cuando es `[]`. Las otras respuestas de cabecera mantienen su selección actual; las líneas se consultan por su endpoint existente. Los clientes que comparen claves exactas deberán admitir las propiedades nuevas. OpenAPI contiene `LaboratoryOrderCreationResponse`, la ampliación de `LaboratoryOrderExam`, los snapshots de requisito y conflictos clínicos.

Ejemplo de composición, sobre referencias y precios previamente existentes:

```json
{
  "branch_id": 1,
  "patient_id": 2,
  "doctor_id": null,
  "commercial_client_id": null,
  "price_list_id": 3,
  "ordered_at": "2026-10-10 14:00:00",
  "notes": null,
  "exams": [{"laboratory_exam_id": 12}, {"laboratory_exam_id": 12}]
}
```

Fragmento ilustrativo de una línea en `data.exams` (IDs y configuración no son seeds clínicos):

```json
{
  "id": 50,
  "exam": {"id": 12, "code": "LOCAL-01", "name": "Examen local"},
  "price_list": {"id": 3, "name": "Lista local"},
  "unit_price": "25.00",
  "clinical_version_id": 8,
  "clinical_configuration_source": "published_version",
  "sample_requirements": [{
    "id": 70,
    "laboratory_order_exam_id": 50,
    "clinical_version_id": 8,
    "requirement_key": "main",
    "position": 0,
    "status": "pending",
    "configuration": {"key": "main", "sample_type_id": 4, "sample_type_name": "Tipo local"}
  }]
}
```

422 identifica entradas y condiciones comerciales inválidas: referencias ajenas/inexistentes, lista/precio inactivos, moneda incompatible, límite, estado de orden y orden vacía. Los errores comerciales durante composición se ubican bajo `exams.<índice>.<campo>`. 409 con `message`, `code` y `errors` identifica conflictos clínicos: `clinical_exam_unavailable` para examen inactivo, falta de publicación/elegibilidad o falta de condiciones para iniciar; `order_exam_has_clinical_activity` para eliminación impedida por actividad. Referencias de orden/línea ajenas mantienen 404, y referencias de examen inválidas no revelan otro laboratorio.

## 5. Eliminación, actividad y ciclo de vida

La eliminación bloquea la orden, exige pending, verifica pertenencia y bloquea los requisitos. Rechaza actividad marcada en línea o requisito y cualquier requisito no pendiente. Elimina requisitos y línea en la misma transacción; después recalcula y registra auditoría. Si falla cualquier paso, todo vuelve al estado anterior.

El marcador `clinical_activity_started_at` prepara la protección para los módulos siguientes: deberán fijarlo al comenzar la primera operación clínica, bajo el mismo bloqueo de cabecera, y conservarlo aunque después cancelen la operación. Esta tarea no escribe actividad clínica ni interpreta resultados. Las futuras FK de muestras/resultados deberán reforzar esas asociaciones; el marcador no representa por sí mismo una muestra física.

No se amplía la matriz de estados. Para un cambio efectivo a `in_process` se exige al menos una línea publicada `sample_mode: none`. Una orden vacía devuelve 422; solo líneas legacy/históricas o solo requisitos físicos pendientes devuelven 409. Una orden mixta puede iniciar por su línea sin muestra; ello no convierte en procesables sus líneas legacy ni sus requisitos pendientes. BE-S2-05 deberá incorporar disponibilidad física por línea; el futuro procesamiento deberá comprobar siempre la versión fijada y los requisitos propios. Los no-op y las órdenes históricas ya avanzadas conservan su comportamiento. El cierre, cancelación, resultados y reglas financieras más completas permanecen en sus tareas posteriores.

## 6. Auditoría y modelos

Se conservan los eventos `order.created`, `order_exam.added`, `order_exam.removed` y `order.status_changed` definidos por `OrderAuditEvents`, sin inventar una simulación de procesamiento. La adición amplía su snapshot con versión, origen y hash de la configuración publicada. La eliminación conserva versión, origen e IDs de requisitos, además de todos los snapshots comerciales anteriores. La versión inmutable permite recuperar el documento de origen después de una eliminación autorizada. Los eventos pertenecen a la misma transacción que la escritura.

`LaboratoryOrderExam` incorpora la relación `clinicalVersion`, `sampleRequirements` y el cast del marcador. El modelo nuevo `LaboratoryOrderSampleRequirement` incorpora tenant, relación con línea y casts del documento/actividad. `LaboratoryOrderSampleRequirementResource` expone explícitamente los siete campos del contrato. No hay tablas de resultados o muestras ni relaciones clínicas compartidas entre laboratorios.

Los factories de pruebas conservan sus publicaciones explícitas para verificar contratos comerciales. El cierre de BE-S2-04 incorpora además un dataset demostrativo separado, con procedencia persistente y publicaciones mediante los servicios del dominio. `DatabaseSeeder` y `LaboratoryOrderSeeder` son compatibles con el esquema nuevo; su ejecución y repetición se verifican en PostgreSQL aislado. Las reglas completas y el resultado final de ese cierre se documentan en el apartado 9. `RolePermissionSeeder` conserva su contrato independiente y no forma parte del flujo demo, que utiliza `DemoPermissionSeeder` limitado a su laboratorio.

## 7. Verificación aislada

Se usa únicamente `donqer-be-s2-04` y `deploy/testing/compose.yaml`: PostgreSQL 17 en tmpfs, red interna y runner PHP 8.4 con código/configuración de testing en lectura y cachés temporales. Se conservan las guardas de BE-S2-02. No se ejecutan suites simultáneas sobre la misma base ni migraciones/seeders sobre desarrollo.

```bash
docker compose -p donqer-be-s2-04 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-04 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/OrderComposition
docker compose -p donqer-be-s2-04 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never
docker compose -p donqer-be-s2-04 -f deploy/testing/compose.yaml down --remove-orphans
```

Cobertura: creación vacía/múltiple, 50/51, repeticiones, formato JSON, permisos combinados, versiones publicadas, corte y adopción legacy, ausencia de backfill histórico, requisitos independientes, eliminación, actividad e inmutabilidad SQL, publicación posterior, rollback comercial/auditoría, tenants, recursos y OpenAPI. Las pruebas anteriores mantienen recálculo, descuentos, snapshots, autorización y estados con fixtures clínicos explícitos.

Siete carreras nuevas usan procesos y conexiones PostgreSQL independientes: publicación, cambio de precio, desactivación de examen/precio/lista y rollback de precio/publicación. Comprueban en `pg_stat_activity` que la adición espera el bloqueo y selecciona solamente el estado confirmado. Se conservan las carreras anteriores de composición/estado/eliminación/economía. No se afirman carreras a partir de conexiones compartidas o pruebas SQLite.

Resultado dirigido: **46 casos, 324 aserciones, cero fallos/errores/omisiones**, composición y auditoría. El grupo de persistencia y seeders pasó con **41 casos y 546 aserciones**; las pruebas de catálogos ejecutan únicamente sus seeders necesarios, conservando sus aserciones de idempotencia.

**Resultado de composición antes de actualizar el dataset (2026-10-10):** PHPUnit 12.5.35, PHP 8.4.25 y PostgreSQL 17 del proyecto aislado. **3.015 casos, 18.735 aserciones, cero errores, cero fallos y tres omisiones específicas de SQLite.** Duración del runner: **6 min 03,568 s**.

| Suite / subconjunto | Casos | Aserciones | Errores / fallos | Omitidos |
| --- | ---: | ---: | ---: | ---: |
| Unit | 46 | 70 | 0 / 0 | 0 |
| Feature | 2.969 | 18.665 | 0 / 0 | 3 |
| Total | 3.015 | 18.735 | 0 / 0 | 3 |
| OrderComposition, incluido en Feature | 33 | 202 | 0 / 0 | 0 |
| Concurrencia nueva, incluida en OrderComposition | 7 | 57 | 0 / 0 | 0 |

Las omisiones existentes son intención numérica inválida en SQLite y dos comprobaciones de sus triggers de descuentos. No se omitieron pruebas clínicas o carreras PostgreSQL. La suite completa final incluye los ajustes de contratos, fixtures clínicos y pruebas de idempotencia de los seeders necesarios. Pint (`--dirty --test --format agent`) pasó y `git diff --check` no informó errores.

El reporte JUnit final permanece en `/tmp/be04-results/full.xml` y el log en `/tmp/be04-full.log`. El proyecto Compose `donqer-be-s2-04` se retiró con `down --remove-orphans` después de conservar los reportes. No se ejecutaron migraciones ni seeders en desarrollo o producción.

## 8. Archivos y pendientes

Nuevos: la migración BE-S2-04, modelo/recurso de requisitos, `tests/Feature/OrderComposition/AtomicOrderCompositionTest.php`, `OrderCompositionConcurrencyTest.php` y este documento. Modificados: las cuatro actions de órdenes, `StoreLaboratoryOrderRequest`, `LaboratoryOrderController`, recursos de cabecera/línea, modelo de línea, OpenAPI de órdenes, factories y pruebas relacionadas. El apartado 9 enumera los seeders y el registro añadidos durante el cierre. Los archivos previos BE-S2-03 se mantienen como trabajo existente.

Pendientes separados: gestión física y estados de requisitos en BE-S2-05, captura/contexto y snapshots de resultados, resolución auditada de referencias, validación, cierre clínico y finanzas, informes/entrega y provisión revisada del catálogo global. No se implementan esos flujos ni frontend; no se agregan dependencias. El despliegue requiere aplicar la migración habitual en un entorno autorizado y coordinar los campos nuevos y errores 409 con los consumidores. Esta sesión deja los cambios para revisión, sin commit ni push.

## 9. Cierre: dataset demostrativo separado y compatible

La propuesta de separación fue aprobada expresamente por el usuario. El dataset `donqer-be-s2-04-demo-v1` crea sus propias identidades y registra su procedencia. No adopta registros por tener nombres, NIT, códigos o correos parecidos a los de demostración. No se modifica ninguna excepción legacy ni se asignan versiones a líneas anteriores.

### Registro, límites y atomicidad

Migración nueva: `2026_10_10_000003_create_demo_dataset_registry.php`. Crea únicamente `demo_datasets` y `demo_dataset_records`, sin backfill. El segundo conserva tipo de entidad, clave estable e ID; la combinación dataset/tipo/clave es única. Los escenarios de órdenes usan `order-01` a `order-18` y apuntan a los IDs devueltos por la action; los códigos públicos siguen siendo ULIDs normales. No hay campos o rutas nuevos en la API.

`DemoDataset` centraliza transacciones, registro, verificación de dependencias y bloqueo del dataset. `DatabaseSeeder` engloba todos los seeders en una transacción; las ejecuciones individuales también tienen transacción y guarda. Solo admite `APP_ENV=local` o `testing`; producción y otros entornos se rechazan antes de escribir, incluso si Artisan recibe `--force`. Esta sesión solo ejecuta pruebas en el entorno aislado.

Cada identidad existente debe estar registrada y coincidir con su ID y claves originales. Una colisión no registrada o una dependencia registrada ausente/cambiada aborta; no hay adopción ni reparación automática. Repetir conserva los registros, incluidos sus timestamps, contraseñas, modificaciones operativas, estados y snapshots de órdenes. No se borran o recrean líneas ni requisitos. No se fuerzan precios, disponibilidad, membresías o roles de vuelta al estado inicial. Una nueva definición futura necesita una actualización explícita del dataset; repetir no migra contenido publicado ni historias.

El registro es metadato interno, sin endpoints públicos. Sus referencias heterogéneas se verifican al cargar las entidades, no mediante FK polimórficas. No se permite convertir nombres similares en prueba de ownership. Los respaldos deben incluir registro y datos; su down elimina la procedencia, no el dataset. Después de perder el registro, repetir rechazará los registros preexistentes. La separación o adopción de datasets antiguos requiere revisión explícita; no se implementa ni se ejecuta aquí.

### Dependencias y seeders

| Orden | Seeder | Responsabilidad |
| --- | --- | --- |
| 1 | `UserSeeder` | Crear usuarios nuevos registrados; nunca restablecer contraseñas existentes. |
| 2 | `SaasFoundationSeeder` | Plan, laboratorio demo, sucursales, suscripción y membresías con procedencia. |
| 3 | `DemoPermissionSeeder` | Catálogo compartido de permisos, roles y asignaciones exclusivamente del laboratorio demo, creados una vez. Restaura el contexto Teams anterior. |
| 4–6 | `LaboratoryAreaSeeder`, `SampleTypeSeeder`, `LaboratoryExamSeeder` | Catálogos propios y referencias registradas del mismo laboratorio. |
| 7 | `ClinicalExamSeeder` | Configuraciones ficticias publicadas mediante `ClinicalExamService`. |
| 8–9 | `PatientSeeder`, `DoctorSeeder` | Personas ficticias con referencias estables registradas. |
| 10 | `PriceListSeeder` | Tres listas y 33 precios inicialmente activos; no desmarca listas existentes ni modifica precios en repeticiones. |
| 11 | `CommercialClientSeeder` | Tres clientes demo y asignaciones efectivas registradas. |
| 12 | `LaboratoryOrderSeeder` | Crear escenarios mediante `CreateLaboratoryOrder`/`AddExamToLaboratoryOrder` y aplicar descuentos mediante `SetLaboratoryOrderDiscount`. |

`DatabaseSeeder` ya no suprime eventos de modelos: la auditoría real del dominio se conserva y un fallo de auditoría revierte todo el dataset. `RolePermissionSeeder` no fue modificado en este cierre y sigue siendo una operación independiente que sincroniza el catálogo de roles según su contrato previo. El flujo demo no lo invoca, para no cambiar roles de otros laboratorios. `DemoPermissionSeeder` reutiliza `RbacCatalog` y crea permisos globales faltantes, sin cambiar permisos o asignaciones de roles ajenos.

### Configuraciones y escenarios

Se publican once configuraciones iniciales. Los diez exámenes anteriores del catálogo demo tienen requisitos físicos locales: Sangre, Orina, Heces o Hisopado nasofaríngeo. Sus parámetros son observaciones textuales de interfaz; no se inventan valores clínicos, rangos, interpretación, volúmenes ni recipientes diagnósticos. Se agrega `DEMO-NONE`, una observación ficticia sin muestra física, con precio `25.00` en las tres listas.

Todos los documentos, instrucciones de requisitos y notas de órdenes se identifican con **“FICTICIO — DEMOSTRACIÓN. NO APTO PARA USO DIAGNÓSTICO REAL.”** Los borradores, edición y publicación pasan por el servicio y su validador; no se insertan publicaciones directamente. Las versiones son inmutables bajo las protecciones existentes. Cada línea nueva fija la publicación y sus requisitos propios.

El dataset crea **18 órdenes pending, 44 líneas y 43 requisitos pending**: examen único, combinaciones, repetición de glucosa, distintos tipos de muestra, clientes particulares/comerciales y descuentos. El escenario 18 usa `DEMO-NONE`. Se conservan seis escenarios con porcentaje y cinco con monto, además de siete sin descuento. Ejemplos comprobados: orden 01 `130.00`; orden 02 con 10 % `207.00`; orden 05 con monto limitado al subtotal `0.00`; orden 18 con 20 % `20.00`.

Ningún escenario se marca completed, se valida o simula resultados, informes o muestras recibidas. La habilitación física sigue pendiente de BE-S2-05. Las fechas de cabecera conservan el contrato local `YYYY-MM-DD HH:mm:ss`; no se aplica una conversión UTC implícita.

### Validación del cierre

Proyecto aislado: `donqer-be-s2-04-seeders`, con el mismo `deploy/testing/compose.yaml`, PHP 8.4 y PostgreSQL 17 temporal. Las pruebas realizan la primera ejecución completa en base limpia y la segunda ejecución, verifican publicaciones/requisitos/totales y comparan las filas persistidas. Incluyen datos históricos realmente creados antes de BE-S2-04, otro laboratorio con su lista predeterminada y roles, contraseñas y modificaciones operativas, colisiones sin procedencia, dependencia perdida, producción con `--force` y fallo tardío de auditoría.

Batería inicial del flujo y seeders anteriores: **24 casos, 656 aserciones, cero errores/fallos/omisiones**. Batería ampliada del dataset: **9 casos, 578 aserciones, cero errores/fallos/omisiones**. **Suite completa final del cierre (2026-10-10): PHPUnit 12.5.35, PHP 8.4.25 y PostgreSQL 17.11. 3.021 casos, 18.894 aserciones, cero errores, cero fallos y tres omisiones específicas de SQLite. Duración: 4 min 25,935 s.**

| Suite / subconjunto | Casos | Aserciones | Errores / fallos | Omitidos |
| --- | ---: | ---: | ---: | ---: |
| Unit | 46 | 70 | 0 / 0 | 0 |
| Feature | 2.975 | 18.824 | 0 / 0 | 3 |
| Total | 3.021 | 18.894 | 0 / 0 | 3 |
| Dataset, incluido en Feature | 9 | 578 | 0 / 0 | 0 |

Las omisiones son las tres verificaciones específicas de SQLite enumeradas en el apartado 7. No se omiten pruebas de seeders, clínica o concurrencia PostgreSQL. Pint comprobó explícitamente los seeders, migración y prueba nuevos, además de `--dirty --test`; `git diff --check` pasó.

```bash
docker compose -p donqer-be-s2-04-seeders -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-04-seeders -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/LaboratoryOrderSeederTest.php
docker compose -p donqer-be-s2-04-seeders -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never
docker compose -p donqer-be-s2-04-seeders -f deploy/testing/compose.yaml down --remove-orphans
```

El proyecto temporal se retiró después de obtener el reporte. Los reportes del cierre se conservan en `/tmp/be04-seeders-results/full.xml` y `/tmp/be04-seeders-full.log`. No se ejecutan migraciones ni seeders en desarrollo/producción ni se hacen commits o push.

Archivos del cierre: nuevos `DemoDataset.php`, `DemoPermissionSeeder.php`, `ClinicalExamSeeder.php` y la migración del registro; modificados `DatabaseSeeder`, `UserSeeder`, `SaasFoundationSeeder`, `LaboratoryAreaSeeder`, `SampleTypeSeeder`, `LaboratoryExamSeeder`, `PatientSeeder`, `DoctorSeeder`, `PriceListSeeder`, `CommercialClientSeeder`, `LaboratoryOrderSeeder`, su prueba y este documento. No se modifican contratos públicos ni protecciones clínicas para hacer funcionar el dataset.


## 10. Integración posterior de BE-S2-05

La entrega posterior [BE-S2-05 — Gestión de muestras físicas](BE-S2-05-gestion-muestras-fisicas.md) amplía los requisitos y el dataset demostrativo mediante una migración nueva. Los apartados anteriores describen el alcance y las verificaciones históricas de BE-S2-04; sus resultados no se presentan como resultados de BE-S2-05.

BE-S2-05 incorpora registro físico, asociaciones, satisfacción calculada, preparación e inicio explícito por línea. No reescribe versiones publicadas, snapshots, órdenes anteriores ni la lista legacy. La columna anterior de estado de requisito se conserva como dato de compatibilidad; las lecturas HTTP exponen satisfacción operativa derivada de asociaciones aceptadas y compatibilidad actual. Avanzar a in_process ahora requiere que al menos una línea haya iniciado explícitamente mediante el endpoint nuevo. La finalización de órdenes con procesamiento nuevo espera resultados/validación posteriores.

`DatabaseSeeder` añade `LaboratorySampleSeeder` después de `LaboratoryOrderSeeder`, conservando procedencia, protección contra producción y atomicidad exterior. Añade únicamente dos exámenes publicados ficticios nuevos y un escenario registrado de cuatro líneas con cuatro muestras. Las 18 órdenes de esta entrega permanecen intactas. El dataset limpio ampliado contiene 13 publicaciones, 19 órdenes, 48 líneas y 47 requisitos; sus repeticiones preservan modificaciones manuales físicas y comerciales. No se sobrescriben roles registrados antiguos: la incorporación del nuevo escenario exige provisionar explícitamente sus permisos cuando falten. `RolePermissionSeeder` continúa independiente.

El inventario posterior comprende 51 permisos, seis roles, 100 rutas Laravel y 92 operaciones OpenAPI. Los nueve endpoints originales de órdenes siguen presentes. La verificación completa de esta integración, sus límites y reportes se registran en el documento BE-S2-05.

Verificación posterior BE-S2-05 (2026-10-11): suite completa de **3.068 casos y 19.366 aserciones**, cero errores/fallos y las tres omisiones SQLite existentes; nueve carreras físicas PostgreSQL sin omisiones. La ejecución CLI del dataset limpio y su repetición conservaron snapshots idénticos de todas las tablas. Pint pasó sobre 115 archivos PHP y `git diff --check` pasó. Los reportes finales se conservan en `/tmp/be05-results/full.xml`, `/tmp/be05-full.log` y `/tmp/be05-demo-check.log`.

## 11. Integración posterior de BE-S2-06

[BE-S2-06 — Captura de resultados clínicos](BE-S2-06-captura-resultados-clinicos.md) añade resultados preliminares por parámetro de la versión fijada y exige el inicio individual de BE-S2-05. No transforma líneas históricas/legacy ni modifica las excepciones, publicaciones o snapshots de esta entrega. Captura completa no significa validación ni finalización.

`DatabaseSeeder` añade `LaboratoryResultSeeder` después del escenario físico: un examen ficticio nuevo publicado por el dominio y una orden registrada de tres líneas repetidas, con capturas vacía/parcial/completa. Dataset limpio posterior: 14 publicaciones, 20 órdenes, 51 líneas, 47 requisitos, 4 muestras, 2 capturas y 6 resultados preliminares. Se conserva el registro de procedencia, la protección contra producción, la atomicidad exterior y las correcciones manuales. Roles demo existentes no se actualizan automáticamente; la incorporación exige sus permisos provisionados explícitamente. `RolePermissionSeeder` permanece independiente.

El inventario posterior comprende 55 permisos, seis roles, 103 rutas Laravel y 95 operaciones OpenAPI. Las verificaciones propias de BE-S2-06 y su comprobación de segunda ejecución se registran en su documento; los resultados históricos de los apartados anteriores se mantienen como antecedentes de sus entregas.
