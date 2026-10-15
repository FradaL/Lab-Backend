# BE-S2-03 — Configuración clínica versionada de exámenes

**Estado:** implementado y validado con PostgreSQL aislado. Las decisiones de alcance fueron confirmadas por el usuario: preparar el contrato y diferir su integración con órdenes; admitir `sample_type_id: null` en las nuevas altas clínicas y documentar `sample_type: null` en las lecturas antiguas. No se ejecutaron migraciones sobre desarrollo ni se hicieron commits o push.

## 1. Auditoría y compatibilidad aprobada

El backend existente usa Laravel 13, PHP 8.4, PostgreSQL 17, Sanctum SPA y Spatie Teams. `LaboratoryExam` conserva identidad, área, tipo de muestra, disponibilidad y relaciones comerciales. Su índice `laboratory_exams_laboratory_code_unique` reserva códigos por laboratorio, también cuando están inactivos. Las FK compuestas de área y muestra ya impiden asociaciones entre laboratorios.

No existían versiones, parámetros, referencias ni plantillas globales. `LaboratoryOrderExam` guarda examen, lista de precios, precio y snapshots de código/nombres. `AddExamToLaboratoryOrder` bloquea la cabecera, comprueba estado pending y catálogos/precios activos, crea la línea, recalcula y audita. Ninguna línea anterior tiene versión clínica.

La integración inmediata de DT-22/DT-23 habría cambiado el contrato de adición de todos los exámenes anteriores. Conforme al apartado E y a la decisión expresa del usuario, ese flujo permanece vigente. El servicio y endpoint de contrato sí exigen publicación y disponibilidad; el POST de líneas todavía no los invoca.

Se consultó también la ausencia de muestra física: la columna antigua era NOT NULL y las lecturas desreferenciaban siempre el catálogo. La ampliación aprobada admite null; los registros antiguos conservan exactamente sus datos. Las lecturas de precios y exámenes disponibles también devuelven sample_type null cuando corresponde, con sus schemas OpenAPI actualizados. El POST antiguo sigue exigiendo un tipo local activo. Su PATCH sigue validando las selecciones de muestra existentes; para un examen sin muestra, omitir el campo conserva null.

Referencias previas: `docs/DONQER_LAB_CICLO_DE_VIDA_ORDENES.md`, `BE-S2-01-contratos-ordenes.md` y `BE-S2-02-validacion-aislada.md`. No se implementan otras propuestas pendientes de esos documentos.

## 2. Arquitectura y almacenamiento

| Estructura | Función y garantías |
| --- | --- |
| `laboratory_exams` existente | Identidad local, código único y active/inactive. `sample_type_id` ahora nullable. |
| `laboratory_exam_versions` | Laboratorio/examen, número único por examen, revisión de escritura, estado draft/published, base de publicación, documento JSONB, SHA-256, fecha y usuario de publicación. |
| `laboratory_exam_clinical_states` | Inscripción clínica del examen, puntero vigente y procedencia global. Una fila por examen; única importación de plantilla por laboratorio. |
| `global_exam_templates` | Artefactos globales independientes, clave única, nombre y documento JSONB inmutable. |
| `audit_logs` existente | Eventos transaccionales de borradores, importación, publicación y disponibilidad. |

Los parámetros, opciones, referencias y requisitos de muestra son estructuras tipadas dentro de **un documento de versión**, no tablas editables independientemente. Se eligió JSONB porque la unidad de publicación y consumo es la configuración completa: una sola actualización publica todos sus componentes y el mismo trigger protege todos sus descendientes. No hay sincronización de componentes ni entidades clínicas compartidas entre laboratorios. Las claves de parámetro y referencia son identificadores estables dentro del documento; su identidad histórica completa incluye el ID de versión.

Las FK compuestas aseguran que examen, base y versión vigente pertenezcan al mismo laboratorio y examen. Otra FK exige inscripción clínica para cualquier versión. El estado clínico usa FK a la plantilla global; su procedencia y ownership no se pueden cambiar. Las referencias a tipos locales dentro del JSONB se validan por laboratorio en el servicio; son snapshots clínicos, no FK individuales dentro del documento. No se promete integridad clínica si un administrador escribe documentos arbitrarios por SQL y elude la aplicación.

Modelos: `LaboratoryExamVersion`, `GlobalExamTemplate` y `LaboratoryExam` existente. `ClinicalExamService` concentra las transacciones y consultas explícitamente acotadas por laboratorio; `ClinicalConfigurationValidator` valida configuración, sin interpretar resultados. `ClinicalExamRequest` controla campos y revisiones. `ClinicalExamVersionResource` selecciona explícitamente la respuesta. Los once endpoints nuevos y sus entradas se documentan también en OpenAPI; una prueba compara su inventario con las rutas reales.

## 3. Documento clínico, tipos y referencias

El contrato de documento es `schema_version: 1`. La identificación contiene código, nombre y descripción opcional. En creación, el servidor fija código y nombre a los del alta. El PUT reemplaza la configuración completa y puede dejar un borrador incompleto; si omite schema_version, el servidor incorpora 1. Para publicar, el código clínico debe coincidir con el código actual del catálogo. Si el código antiguo cambia, no cambia ningún snapshot publicado; los próximos borradores deben actualizar su identificación antes de publicar.

`parameters` es una lista ordenada, con entre 1 y 100 elementos al publicar. Cada parámetro requiere código único, nombre y tipo:

| Tipo | Configuración |
| --- | --- |
| `numeric` | `unit_required` explícito; `unit` obligatorio si es true. No requiere referencias numéricas por defecto. |
| `text` | Puede configurar `text_max_length`; las referencias contienen texto. |
| `qualitative` | Lista explícita y no vacía de opciones código/etiqueta; referencias por códigos de opción. |
| `predefined_options` | Lista explícita y no vacía de opciones código/etiqueta; referencias por códigos de opción. |

Los códigos de opción son únicos **dentro de cada parámetro**. Opciones en parámetros numéricos/texto, unidades en tipos no numéricos y límites de texto en otros tipos se rechazan. No se crean opciones ni unidades predeterminadas. Los valores numéricos de configuración deben ser finitos.

Cada referencia requiere `key` única dentro del parámetro y `priority` entera no negativa. Una prioridad mayor tiene precedencia. Los filtros opcionales son:

- `age_min_days` / `age_max_days`: edad expresada en días cumplidos, límites inclusivos; null/ausencia significa sin límite. No hay conversión implícita desde años o meses.
- `sex`: código literal configurable; ausencia/null es comodín. No se inventa una clasificación clínica ni se normalizan códigos del paciente.
- `conditions`: mapa de claves nombradas a valores de texto exactos. Las condiciones se combinan por AND. Ausencia de una condición en una regla es comodín, no equivale a afirmar que la condición no existe en el paciente.

Contenido según tipo: referencias numéricas con al menos un límite `min`/`max`, texto con `text`, categóricas con `allowed_options` pertenecientes a ese parámetro. Los límites invertidos se rechazan. No se exige cobertura universal, porque la falta de referencia debe conducir a resolución autorizada posterior, no a un rango inventado.

Dos reglas se superponen si sus edades intersectan, sus sexos no son excluyentes y ninguna condición compartida exige valores distintos. **Toda superposición con igual prioridad impide publicar**, incluso si ambos contenidos coinciden: se evita seleccionar arbitrariamente una identidad de referencia histórica. Prioridades distintas permiten una regla general y otra específica. El validador compara prioridades numéricamente, también cuando llegan como cadenas admitidas por la validación integer.

No se ejecuta selección de referencia sobre pacientes. El futuro motor deberá exigir contexto suficiente para decidir el conjunto de coincidencias; seleccionar la mayor prioridad solo entre coincidencias conocidas y derivar faltantes, ausencia de coincidencia o empate a resolución autorizada y auditada. No debe usar una regla general ignorando contexto desconocido que podría activar otra de prioridad superior.

## 4. Muestras

`sample_mode` debe ser explícitamente `none` o `physical` al publicar:

- `none`: no admite requisitos físicos.
- `physical`: exige al menos un requisito, identificado por `key`, con `sample_type_id` local activo.

Puede configurarse volumen positivo con unidad explícita, recipiente e instrucciones. No se inventan volúmenes ni recipientes. Al publicar, el servicio bloquea en modo compartido los tipos seleccionados y guarda `sample_type_name` como snapshot controlado por servidor. Los clientes no pueden escribir ese nombre. Un cambio posterior de nombre/estado del catálogo no modifica el documento publicado.

El `sample_type_id` del catálogo antiguo es una selección simple para compatibilidad; **no representa todos los requisitos físicos** de una versión. Un examen clínico puede tener varios requisitos aunque ese campo sea null. Para conocer sus necesidades clínicas se consume la versión. No se crean muestras físicas, contenedores operativos, alícuotas ni procesos de recepción.

## 5. Borradores, publicación, disponibilidad y concurrencia

Un alta clínica crea un examen **inactive** y su primer borrador; no publica ni activa automáticamente. Para incorporar explícitamente un examen antiguo se crea un borrador mediante POST de versiones, sin inferir parámetros ni muestras y sin cambiar su estado anterior. Mientras no tenga publicación, el contrato estricto de órdenes devuelve conflicto.

El PUT exige `revision` igual a la almacenada; incrementa la revisión y audita. La publicación vuelve a validar bajo transacción, bloqueando primero el examen y después el borrador. Comprueba estado editable, revisión, identificación, configuración y base de publicación. Cambia a published, fija fecha/actor/hash, incrementa revisión y actualiza el puntero vigente junto con la auditoría. Un fallo revierte todo.

La base de un borrador es la versión vigente al prepararlo. Si otra publicación cambia el puntero, el borrador restante no puede sustituirla silenciosamente: devuelve `publication_conflict`. Debe prepararse otra versión y trasladar los cambios explícitamente. Se permite copiar una versión publicada anterior; el evento conserva el origen y el nuevo borrador toma como base el vigente de ese momento. Cada nuevo borrador recibe un número único asignado bajo bloqueo del examen.

PostgreSQL rechaza UPDATE y DELETE de cualquier versión publicada, incluido JSONB, metadatos, options/references y requisitos. Rechaza insertar directamente una versión publicada, cambiar ownership/linaje de un borrador, utilizar un borrador como vigente, borrar la inscripción clínica y cambiar procedencia. Las plantillas globales tampoco pueden actualizarse o borrarse por SQL ordinario. El puntero vigente permanece fuera del documento inmutable.

Se mantiene un único endpoint de disponibilidad: PATCH de status. Adquiere el mismo bloqueo del examen. En exámenes inscritos clínicamente, activar/reactivar requiere una publicación vigente. En exámenes antiguos todavía no inscritos, conserva su comportamiento previo. Desactivar no borra versiones ni líneas históricas. Publicar una nueva versión no cambia el estado del examen.

`validate-publication` es una consulta orientativa: informa errores estructurales, de identificación, estado o base obsoleta. No reserva la publicación; POST de publish vuelve a comprobar todo dentro de la transacción.

## 6. Endpoints y permisos

Todos usan `/api/v1`, grupo `saas`, Sanctum, usuario activo, `X-Laboratory-ID`, membresía activa, Teams y suscripción. Examen/versión ajenos o inexistentes responden 404 sin revelar el otro laboratorio.

| Método y ruta relativa | Permiso | Resultado |
| --- | --- | --- |
| POST `/laboratory-exams/drafts` | `laboratory_exams.create_draft` | 201: examen inactivo y primer borrador. |
| GET `/global-exam-templates` | `laboratory_exams.view` | Plantillas globales, paginadas de 50 en 50. |
| POST `/laboratory-exams/import-template` | `laboratory_exams.import_template` | 201: copia local independiente. |
| GET `/laboratory-exams/{exam}/versions` | `laboratory_exams.view` | Historial paginado de 50, número descendente. |
| POST `/laboratory-exams/{exam}/versions` | `laboratory_exams.create_draft` | 201: incorporación sin configuración inferida, o copia publicada. |
| GET `/laboratory-exams/{exam}/versions/current` | `laboratory_exams.view` | Publicación vigente; `data: null` si no existe. |
| GET `/laboratory-exams/{exam}/versions/{version}` | `laboratory_exams.view` | Documento y metadatos de la versión. |
| PUT `/laboratory-exams/{exam}/versions/{version}` | `laboratory_exams.edit_draft` | Reemplazo íntegro con revisión esperada. |
| POST `/laboratory-exams/{exam}/versions/{version}/validate-publication` | `laboratory_exams.publish` | `data.publishable` y errores por campo. |
| POST `/laboratory-exams/{exam}/versions/{version}/publish` | `laboratory_exams.publish` | Publicación atómica. |
| GET `/laboratory-exams/{exam}/order-configuration` | `laboratory_exams.view` | Contrato estricto para futura asociación a una línea. |
| PATCH `/laboratory-exams/{exam}/status` existente | `laboratory_exams.change_status` | Disponibilidad; publicación requerida al activar los inscritos. |

Se agregan cuatro permisos al catálogo: create_draft, edit_draft, publish e import_template. Total: **45 permisos**, seis roles. Owner y administrator reciben los nuevos permisos mediante `RolePermissionSeeder`; los demás conservan sus permisos anteriores. Lectura y disponibilidad usan sus permisos existentes e independientes. Importar concede específicamente la creación por importación; no requiere además create_draft. Crear por el POST antiguo no concede edición o publicación clínica.

### Ejemplo de alta y publicación

Los nombres de este ejemplo son demostrativos; no representan criterios clínicos ni se cargan como seed.

```json
POST /api/v1/laboratory-exams/drafts
{
  "code": "LOCAL-01",
  "name": "Examen local",
  "laboratory_area_id": 4,
  "sample_type_id": null,
  "configuration": {
    "sample_mode": "none",
    "parameters": [
      {
        "code": "OBS",
        "name": "Observación",
        "type": "text",
        "text_max_length": 2000
      }
    ]
  }
}
```

Respuesta 201, abreviada:

```json
{
  "data": {
    "id": 12,
    "laboratory_exam_id": 31,
    "number": 1,
    "revision": 1,
    "status": "draft",
    "base_version_id": null,
    "configuration": {
      "schema_version": 1,
      "code": "LOCAL-01",
      "name": "Examen local",
      "sample_mode": "none",
      "parameters": [{"code": "OBS", "name": "Observación", "type": "text", "text_max_length": 2000}]
    },
    "configuration_hash": null,
    "published_at": null,
    "published_by": null
  }
}
```

Publicar con `POST /laboratory-exams/31/versions/12/publish`, body `{"revision":1}`. Devuelve published y revisión 2. Activar con el PATCH existente y `{"status":"active"}`. Para la siguiente versión: POST de versions con `{"source_version_id":12}`. Editar el borrador mediante PUT con `revision` y `configuration` completa; no usar PATCH de catálogo para editar clínica.

### Errores

Validación del request/borrador: 422 con `message` y `errors` conforme a Laravel. Operaciones clínicas de conflicto incluyen `message`, `code` y `errors`:

| Código | HTTP | Motivo |
| --- | --- | --- |
| `exam_code_conflict` | 409 | Código reservado en el laboratorio, incluso inactivo. |
| `template_already_imported` | 409 | Misma plantilla importada antes. |
| `version_not_editable` | 409 | Escritura/publicación sobre una versión publicada. |
| `revision_conflict` | 409 | Revisión obsoleta. |
| `publication_conflict` | 409 | Cambió la publicación base. |
| `source_not_published` | 409 | Se intentó copiar un borrador. |
| `source_version_required` | 409 | Se omitió origen cuando existe publicación vigente. |
| `publication_invalid` | 422 | Configuración no publicable; errores concretos por campo. |
| `clinical_exam_unavailable` | 409 | Activación sin publicación o contrato solicitado sin examen activo/publicado. |

Autenticación, contexto, membresía, suscripción y permisos mantienen sus respuestas existentes. El POST antiguo conserva su validación 422 para códigos duplicados: no se cambia silenciosamente al nuevo 409.

## 7. Plantillas y auditoría

No se cargan plantillas clínicas ficticias. Los artefactos globales deben ser aportados y revisados por el proceso autorizado de catálogo global; esta tarea no agrega un endpoint de administración global ni una autorización global basada en roles locales. Cada artefacto tiene una clave única inmutable. No se implementa un ciclo de revisiones de plantillas globales; los artefactos nuevos son identidades independientes y nunca reemplazan copias existentes.

La importación recibe template_id, código/nombre locales, área, muestra simple opcional y `sample_type_map` opcional. El mapa usa las claves de los requisitos de la plantilla, por ejemplo `{"main":8}`. No transporta IDs de catálogos globales a un laboratorio. Si falta una correspondencia, el requisito queda incompleto en el borrador y no podrá publicarse. Claves desconocidas y IDs ajenos/inactivos se rechazan.

La transacción bloquea la plantilla y el índice único protege duplicados aun ante concurrencia. Cambios locales nunca actualizan la plantilla. El estado clínico conserva su ID de procedencia; el historial/auditoría identifica la importación.

Eventos: `laboratory_exam.draft_created`, `.draft_updated`, `.template_imported`, `.published`, además de los eventos de catálogo y `.status_changed` existentes. Los eventos clínicos guardan ID de examen/versión, número, revisión, estado, digest del documento y origen/revisión/base anteriores cuando corresponde. No copian el request completo ni condiciones arbitrarias dentro de `AuditEvent`. Las versiones persistentes conservan la configuración completa. La auditoría de borradores permite comprobar el digest anterior y posterior mediante la secuencia de eventos; no conserva cada revisión intermedia completa del borrador.

## 8. Contratos pendientes de órdenes y resultados

`ClinicalExamService::orderConfiguration(laboratory, examId)` devuelve versión publicada y hash junto con documento y contrato de contexto. Bloquea el examen dentro de una transacción; cuando se invoque desde la futura transacción de adición, el bloqueo permanece hasta que confirme la transacción exterior.

Ejemplo de respuesta abreviada del endpoint:

```json
{
  "data": {
    "schema_version": 1,
    "laboratory_exam_id": 31,
    "clinical_version_id": 12,
    "clinical_version_number": 1,
    "configuration_hash": "<sha256 de 64 caracteres>",
    "configuration": {"schema_version": 1, "code": "LOCAL-01", "name": "Examen local", "sample_mode": "none", "parameters": [{"code": "OBS", "name": "Observación", "type": "text"}]},
    "result_context_contract": {
      "capture_at": "interpretation_or_validation",
      "required_snapshot_fields": ["reference_date", "age_days", "sex", "conditions", "clinical_version_id", "parameter_code", "reference_key"],
      "priority_rule": "highest_matching_priority",
      "missing_context_rule": "authorized_audited_resolution",
      "no_match_or_tie_rule": "authorized_audited_resolution"
    }
  }
}
```

El ejemplo abreviado muestra un parámetro de texto sin referencias obligatorias. El GET permite consultar el contrato, **no reserva** una versión para una escritura posterior. La futura adición debe invocar el servicio dentro de su propia transacción, no confiar en un ID obtenido antes por el cliente.

Dependencia exacta para DT-22/DT-23 sobre líneas: agregar una FK clínica compuesta por laboratorio/examen/versión a `laboratory_order_exams`, permitir null exclusivamente para datos históricos durante la transición, decidir el corte de compatibilidad de exámenes no configurados y llamar el servicio dentro de `AddExamToLaboratoryOrder` después del bloqueo de cabecera. Persistir el ID seleccionado; nunca sustituirlo al consultar el vigente ni migrar líneas existentes al publicar otra versión. **Esa migración y modificación del action no forman parte de esta entrega**, por decisión del usuario.

DT-27 exige luego persistir fecha de referencia, contexto usado (incluidas ausencias y fuentes), identidad y snapshot completo de la referencia aplicada, actor y motivo de resolución cuando corresponda. El contrato identifica versión + código de parámetro + clave de referencia; no hay aún tabla o escritura de resultados, referencias aplicadas ni evaluación clínica. La fuente y la conversión de códigos de sexo/condiciones del paciente deberán definirse en esa integración, sin inferirlas aquí.

## 9. Migración y despliegue

Migración aditiva: `2026_10_10_000001_create_clinical_exam_versions.php`. Crea las tres estructuras y sus restricciones/triggers; amplía la nulabilidad de la muestra simple. No actualiza exámenes anteriores, órdenes, precios, snapshots ni estados. No inserta versiones ni plantillas. La prueba de migración aplica down/up con un examen y una línea existentes y verifica preservación exacta de sus atributos persistidos.

El despliegue deberá ejecutar las migraciones habituales y actualizar permisos con `RolePermissionSeeder` en el entorno autorizado. Esta sesión solo lo prueba en aislamiento. El down elimina las estructuras nuevas y su historial; solo es adecuado antes de usar el módulo o con una estrategia explícita de respaldo/restauración. Intencionalmente no restaura NOT NULL sobre sample_type_id: hacerlo podría invalidar altas legítimas sin muestra.

Las funciones PostgreSQL usan CREATE OR REPLACE para soportar migrate:fresh en testing: borrar tablas no elimina automáticamente funciones independientes. Las pruebas de integridad/concurrencia son PostgreSQL; no se afirma protección equivalente en SQLite.

## 10. Pruebas y resultados

Se utiliza exclusivamente el proyecto Compose `donqer-be-s2-03` con `deploy/testing/compose.yaml`, PostgreSQL 17 en tmpfs, PHP 8.4 y credenciales de pruebas. El runner monta el código y la configuración de testing en lectura y usa rutas temporales de caché. Se mantienen las guardas de BE-S2-02 antes de migraciones.

```bash
docker compose -p donqer-be-s2-03 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-s2-03 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalExams
docker compose -p donqer-be-s2-03 -f deploy/testing/compose.yaml run --rm tests \
  php vendor/bin/phpunit --do-not-cache-result --colors=never
docker compose -p donqer-be-s2-03 -f deploy/testing/compose.yaml down --remove-orphans
```

Cobertura nueva: borradores incompletos y reemplazo, cuatro tipos, publicación válida/inválida, inmutabilidad SQL/API de todo el documento, prioridades/edad/sexo/condiciones, muestras y snapshots, activación/reactivación, catálogo antiguo, importación independiente, duplicados, acceso entre laboratorios, permisos por operación, Teams, membresía/suscripción, campos desconocidos, migración progresiva, historia de líneas y rollback por fallo de auditoría. Cuatro carreras reales con procesos y conexiones independientes verifican creación duplicada por código, importación simultánea, publicaciones competidoras y pérdida de revisión al editar. También se actualizan los inventarios: 73 rutas con permisos, 88 rutas totales de Laravel y 80 operaciones OpenAPI, además de los seeders a 45 permisos. Los tests del catálogo heredado siguen verificando sus seis endpoints; los tests de inventario completo incluyen los once nuevos. Las siete aserciones de listas de precios que contaban operaciones de exámenes sin PUT pasan a esperar 15 en ese subconjunto, conservando su criterio original.

**Resultado final (2026-10-10):** PHPUnit 12.5.35, PHP 8.4.25 y PostgreSQL 17.11. Ejecución completa: **2.981 casos, 18.530 aserciones, cero errores, cero fallos y tres omisiones específicas de SQLite**. Duración del runner: 4 min 26 s.

| Suite / subconjunto | Casos | Aserciones | Fallos / errores | Omitidos |
| --- | ---: | ---: | ---: | ---: |
| Unit | 46 | 70 | 0 / 0 | 0 |
| Feature | 2.935 | 18.460 | 0 / 0 | 3 |
| Total | 2.981 | 18.530 | 0 / 0 | 3 |
| ClinicalExams, incluido en Feature | 41 | 194 | 0 / 0 | 0 |

Los cuatro casos clínicos de concurrencia ejecutaron procesos y conexiones PostgreSQL reales, sin omisiones. La batería dirigida de clínica, autorización e inventarios pasó con 74 casos y 426 aserciones. Pint verificó los 48 archivos PHP creados/modificados; git diff --check no informó errores.

Las omisiones existentes corresponden a intención numérica en SQLite y dos pruebas de sus triggers de descuento al migrar/retroceder; no son omisiones de configuración clínica ni concurrencia PostgreSQL. Las aserciones antiguas de inventario se actualizaron para los endpoints nuevos; la ejecución completa final las incluye y pasa. El reporte JUnit se conservó en `/tmp/be03-full.xml` y el log en `/tmp/be03-full.log`. El proyecto temporal de pruebas se retiró después de obtener el reporte; no se ejecutaron migraciones ni seeders sobre desarrollo.

## 11. DT-18 a DT-32 y límites

| Decisión | Aplicación |
| --- | --- |
| DT-18 | Parámetros múltiples, ordenados y locales en cada documento. |
| DT-19 | Referencias múltiples con identidades preservadas en versiones; snapshot aplicado pendiente de resultados. |
| DT-20 | Borradores editables; publicación y descendientes inmutables en PostgreSQL. |
| DT-21 | numeric, text, qualitative y predefined_options con reglas específicas. |
| DT-22 | Contrato estricto preparado; FK y asignación en órdenes diferidas expresamente. |
| DT-23 | Publicación/estado exigidos por contrato; reactivación clínica controlada; integración con líneas diferida. |
| DT-24 | Permisos independientes, SaaS/Teams y auditoría transaccional. |
| DT-25 | Borradores incompletos y publicación estricta, sin rangos universales obligatorios. |
| DT-26 | Prioridades explícitas y rechazo de ambigüedad; resolución autorizada pendiente. |
| DT-27 | Contrato de contexto al interpretar/validar con fecha explícita; no se fija contexto al crear borrador/orden. |
| DT-28 | Sin captura, interpretación, validación o corrección de resultados. |
| DT-29 | Importación explícita independiente y altas propias; sin sincronización automática. |
| DT-30 | Unicidad por laboratorio inclusive inactivos, importación única, restricciones y carreras PG. |
| DT-31 | Migración sin backfill clínico ni alteración de historias. |
| DT-32 | Endpoints anteriores conservados, null documentado, versiones separadas y SQL inmutable. |

No quedan decisiones funcionales abiertas para esta configuración. Quedan trabajos explícitamente separados: adopción clínica en líneas de órdenes, captura y snapshots de resultados, resolución auditada de referencias y administración/provisión autorizada de contenido global. No hay dependencias nuevas ni comandos destructivos sobre desarrollo/producción.

## 12. Archivos de la entrega

### Creados

- `docs/sprint-2/BE-S2-03-configuracion-clinica-versionada.md`
- `laravel/app/ClinicalExams/ClinicalConfigurationValidator.php`
- `laravel/app/ClinicalExams/ClinicalConflict.php`
- `laravel/app/ClinicalExams/ClinicalExamService.php`
- `laravel/app/Http/Controllers/Api/V1/ClinicalExamController.php`
- `laravel/app/Http/Requests/Api/V1/ClinicalExam/ClinicalExamRequest.php`
- `laravel/app/Http/Resources/Api/V1/ClinicalExamVersionResource.php`
- `laravel/app/Models/GlobalExamTemplate.php`
- `laravel/app/Models/LaboratoryExamVersion.php`
- `laravel/app/OpenApi/ClinicalExamDocumentation.php`
- `laravel/database/migrations/2026_10_10_000001_create_clinical_exam_versions.php`
- `laravel/tests/Feature/ClinicalExams/ClinicalExamConcurrencyTest.php`
- `laravel/tests/Feature/ClinicalExams/ClinicalExamTest.php`

### Modificados

- `laravel/app/Authorization/RbacCatalog.php`
- `laravel/app/Http/Controllers/Api/V1/LaboratoryExamController.php`
- `laravel/app/Http/Resources/Api/V1/ActiveLaboratoryExamResource.php`
- `laravel/app/Http/Resources/Api/V1/AvailablePriceListExamResource.php`
- `laravel/app/Http/Resources/Api/V1/LaboratoryExamResource.php`
- `laravel/app/Http/Resources/Api/V1/PriceListExamResource.php`
- `laravel/app/OpenApi/LaboratoryExamDocumentation.php`
- `laravel/app/OpenApi/PriceListExamDocumentation.php`
- `laravel/routes/api.php`
- `laravel/tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientActiveTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientPriceListStoreTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientStatusTest.php`
- `laravel/tests/Feature/Api/V1/CommercialClients/CommercialClientUpdateTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryExams/LaboratoryExamActiveTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryExams/LaboratoryExamIntegrationTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryExams/LaboratoryExamShowTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryExams/LaboratoryExamStatusTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryExams/LaboratoryExamUpdateTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderDiscountTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamDestroyTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamIndexTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamStoreTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderShowTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStatusTest.php`
- `laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStoreTest.php`
- `laravel/tests/Feature/Api/V1/PriceLists/PriceListActiveTest.php`
- `laravel/tests/Feature/Api/V1/PriceLists/PriceListIntegrationTest.php`
- `laravel/tests/Feature/Api/V1/PriceLists/PriceListSetDefaultTest.php`
- `laravel/tests/Feature/Api/V1/PriceLists/PriceListShowTest.php`
- `laravel/tests/Feature/Api/V1/PriceLists/PriceListStatusTest.php`
- `laravel/tests/Feature/Api/V1/PriceLists/PriceListStoreTest.php`
- `laravel/tests/Feature/Api/V1/PriceLists/PriceListUpdateTest.php`
- `laravel/tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php`
- `laravel/tests/Feature/Authorization/ProtectedDomainAuthorizationTest.php`
- `laravel/tests/Feature/Authorization/RolePermissionSeederTest.php`

## 13. Ampliación posterior de compatibilidad física (BE-S2-05)

[BE-S2-05 — Gestión de muestras físicas](BE-S2-05-gestion-muestras-fisicas.md) admite además documentos clínicos `schema_version: 2` con requisitos tipados de aditivos, condiciones, conservación/tiempos y obligatoriedad. Conserva schema 1 y todas las publicaciones anteriores sin reescribirlas. Los contratos externos de órdenes mantienen su propia versión de envelope.

Las instrucciones v1 en texto libre no se interpretan automáticamente: cuando están presentes bloquean compatibilidad física. En v2 las instrucciones son informativas y las restricciones evaluables se publican explícitamente en campos tipados. El documento BE-S2-05 detalla reglas, snapshots históricos, endpoints, verificación y limitaciones. Los resultados de pruebas e inventarios de este documento corresponden a la entrega original BE-S2-03.

## 14. Consumo posterior por resultados (BE-S2-06)

[BE-S2-06 — Captura de resultados clínicos](BE-S2-06-captura-resultados-clinicos.md) persiste valores preliminares por línea y código de parámetro, usando exclusivamente la publicación histórica fijada por BE-S2-04. Conserva tipos, restricciones, unidades, opciones, referencias y su snapshot de parámetro. No altera los documentos publicados ni introduce parámetros opcionales: los esquemas actuales no declaran esa propiedad, por lo que su completitud técnica exige todos los parámetros publicados.

La selección/aplicación de referencias, contexto de interpretación y resolución autorizada siguen pendientes de integración clínica posterior. Capturar valores no evalúa rangos ni significa validación/finalización. El contenido nuevo del dataset está explícitamente identificado como ficticio y se publica con el servicio existente, sin modificar exámenes o líneas anteriores.

## Actualización posterior — BE-S2-07 (2026-10-11)

BE-S2-07 implementa la resolución de referencias y snapshots de contexto al validar, usando únicamente la versión histórica de cada línea y el reference_context explícito aprobado. No convierte gender ni inventa rangos o condiciones; la configuración publicada permanece inmutable.

El informe y las cifras anteriores corresponden a esta entrega histórica. Para el contrato vigente consultar [BE-S2-07 — Validación de resultados clínicos](BE-S2-07-validacion-resultados-clinicos.md). El inventario ampliado es de 112 rutas Laravel, 104 operaciones OpenAPI y 60 permisos (97 rutas protegidas por permiso). RolePermissionSeeder permanece independiente; esta ampliación no ejecuta migraciones/seeders en entornos operativos ni commit/push. La verificación final se documenta en BE-S2-07.
