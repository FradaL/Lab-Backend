# Clinical Exams API Guía de integración frontend

Esta guía define cómo debe integrar frontend la API de configuración clínica versionada de exámenes. Incluye los 11 endpoints de Clinical Exams, los contratos de entrada y salida, las reglas de publicación, el control de concurrencia y el paso adicional de activación del examen.

> [!CALLOUT] ⚠️
>
> Publicar una versión clínica no activa automáticamente el examen. Para poder agregarlo a una orden, el examen debe tener una versión publicada actual y además su registro de catálogo debe estar en estado active.

# Conceptos y ciclo de vida

Un examen clínico combina dos piezas relacionadas pero independientes:

- El examen de catálogo, identificado por laboratory_exam_id. Contiene identidad comercial y operativa, como código, nombre, área, tipo de muestra general y estado active o inactive.

- Las versiones clínicas, identificadas por id. Contienen la configuración de muestras, parámetros y referencias. Una versión empieza como draft y, al publicarse, se vuelve inmutable.

No se debe confundir number con revision:

| Campo | Significado | Uso en frontend |
| --- | --- | --- |
| id | Identificador único de la versión | Se envía en el parámetro {version} de las URLs |
| number | Número secuencial de la versión dentro del examen | Se muestra al usuario; no se usa como identificador de URL salvo que coincida accidentalmente |
| revision | Revisión del borrador para control de concurrencia | Se debe reenviar al guardar o publicar |
| base_version_id | Versión publicada que era actual cuando se creó el borrador | El backend la usa para detectar borradores obsoletos |
| status | draft o published | Solo draft puede editarse o publicarse |
| configuration_hash | SHA-256 de la configuración publicada | Es null en borradores y permite identificar exactamente el snapshot publicado |

~~~mermaid
flowchart LR
    A[Crear examen y borrador] --> B[Editar configuración]
    B --> C[Validar publicación]
    C -->|publishable true| D[Publicar versión]
    C -->|errores| B
    D --> E[Activar examen de catálogo]
    E --> F[Disponible para órdenes]
    D --> G[Crear nuevo borrador desde una versión publicada]
    G --> B
~~~

# Requisitos comunes de las solicitudes

Base de las rutas: /api/v1.

Todas las rutas de esta guía están dentro del middleware SaaS y requieren:

- Sesión autenticada con Laravel Sanctum mediante cookies.

- Encabezado X-Laboratory-ID con un entero positivo.

- Membresía activa del usuario en ese laboratorio.

- Laboratorio activo y suscripción SaaS vigente.

- El permiso específico del endpoint.

- Accept: application/json.

- En POST, PUT o PATCH: Content-Type: application/json y protección CSRF. En una SPA se inicializa con GET /sanctum/csrf-cookie y el cliente envía cookies y X-XSRF-TOKEN.

Ejemplo de encabezados:

~~~http
Accept: application/json
Content-Type: application/json
X-Laboratory-ID: 12
X-XSRF-TOKEN: <valor-de-la-cookie-XSRF-TOKEN>
Cookie: donqer-lab-session=...
~~~

El backend aplica aislamiento por laboratorio. Un ID válido que pertenezca a otro laboratorio se comporta como recurso inexistente y normalmente responde 404.

# Flujo recomendado para frontend

## Crear un examen desde cero

1. Cargar áreas de laboratorio activas y, si aplica, tipos de muestra activos.

2. Enviar POST /laboratory-exams/drafts.

3. Guardar data.laboratory_exam_id, data.id y data.revision.

4. Editar el documento completo con PUT /laboratory-exams/{laboratoryExam}/versions/{version}.

5. Antes de publicar, llamar a validate-publication sin body.

6. Si publishable es false, mapear data.errors a los campos del formulario.

7. Si es true, publicar enviando la revision más reciente.

8. Activar el examen mediante PATCH /laboratory-exams/{laboratoryExam}/status con status active.

## Importar una plantilla global

1. Listar plantillas con GET /global-exam-templates.

2. Mostrar sus sample_requirements y solicitar el mapeo de cada key a un sample_type_id local cuando sample_mode sea physical.

3. Enviar POST /laboratory-exams/import-template.

4. La respuesta es una copia local independiente en estado draft.

5. Continuar con validar, publicar y activar.

## Cambiar un examen que ya tiene una versión publicada

1. Consultar GET /versions/current.

2. Crear un nuevo borrador con POST /versions y source_version_id igual al id de una versión published.

3. Editar el nuevo borrador usando su revision actual.

4. Validar y publicar.

5. No intentar modificar la versión anterior: las versiones publicadas son inmutables.

Puede haber más de un borrador preparado a la vez. Cuando uno se publica, los demás quedan con una base obsoleta y no podrán publicarse; frontend debe tratar publication_conflict como una señal para recargar y crear otro borrador.

# Respuesta estándar de una versión clínica

Los endpoints que devuelven una versión usan el envoltorio data:

~~~json
{
  "data": {
    "id": 81,
    "laboratory_exam_id": 25,
    "number": 1,
    "revision": 3,
    "status": "published",
    "base_version_id": null,
    "configuration": {},
    "configuration_hash": "64-caracteres-hexadecimales",
    "published_at": "2026-10-15T14:30:00+00:00",
    "published_by": 7,
    "created_at": "2026-10-15T14:10:00+00:00",
    "updated_at": "2026-10-15T14:30:00+00:00"
  }
}
~~~

En un borrador, configuration_hash, published_at y published_by son null. Al guardar un borrador, revision aumenta en uno. Al publicar, revision vuelve a aumentar en uno.

# Contrato de configuration

El objeto configuration se envía completo al actualizar. PUT reemplaza la configuración anterior; no es un patch parcial.

## Campos raíz

| Campo | Tipo | Requerido para publicar | Reglas |
| --- | --- | --- | --- |
| schema_version | integer | Sí | Solo 1 o 2. Si se omite al crear o actualizar un borrador, el backend asigna 1 |
| code | string | Sí | Máximo 30. Al publicar debe coincidir exactamente con el code actual del examen de catálogo |
| name | string | Sí | Máximo 150 |
| description | string o null | No | Máximo 5000 |
| sample_mode | string | Sí | none o physical |
| sample_requirements | array | Condicional | Máximo 30. physical exige al menos uno; none exige una lista vacía o ausente |
| parameters | array | Sí | Máximo 100 y al menos uno al publicar |

No se aceptan propiedades raíz adicionales.

## Requisitos de muestra

Cada elemento de sample_requirements admite estos campos en schema_version 1 y 2:

| Campo | Tipo | Reglas |
| --- | --- | --- |
| key | string | Requerido si existe el elemento; máximo 60; patrón de letras, números, guion o guion bajo; único en la lista |
| sample_type_id | integer o null | Al publicar debe ser un tipo de muestra activo del laboratorio actual |
| volume | number o null | Mayor que 0 y finito |
| volume_unit | string o null | Máximo 50; obligatorio al publicar cuando se envía volume |
| container | string o null | Máximo 150 |
| instructions | string o null | Máximo 2000 |
| sample_type_name | string | Solo lectura. Nunca debe enviarlo frontend; el servidor lo agrega al publicar como snapshot del nombre |

schema_version 2 también admite:

| Campo | Tipo | Reglas |
| --- | --- | --- |
| mandatory | boolean | Opcional; en schema 1 todos los requisitos se tratan como obligatorios |
| additives | array de string | Máximo 30; cada valor máximo 100; valores únicos |
| collection_conditions | objeto string a string | Si se envía, debe ser un mapa no vacío, no una lista; máximo 30 entradas; valor máximo 150 |
| storage_conditions | objeto string a string | Mismas reglas que collection_conditions |
| max_age_minutes | integer | Entre 1 y 5,256,000 |
| max_transport_minutes | integer | Entre 1 y 5,256,000 |

El sample_type_id superior usado al crear el examen es metadata del catálogo. No reemplaza los sample_type_id específicos dentro de sample_requirements.

## Parámetros clínicos

Cada elemento de parameters admite:

| Campo | Tipo | Reglas |
| --- | --- | --- |
| code | string | Requerido al publicar; máximo 60; letras, números, guion o guion bajo; único en el examen |
| name | string | Requerido al publicar; máximo 150 |
| type | string | Requerido al publicar: numeric, text, qualitative o predefined_options |
| unit_required | boolean | Para numeric debe declararse explícitamente al publicar |
| unit | string o null | Máximo 50; solo para numeric; obligatorio si unit_required es true |
| text_max_length | integer | Solo para text; entre 1 y 100,000 |
| options | array | Máximo 100; solo para qualitative o predefined_options |
| references | array | Máximo 100 |

Cada opción tiene code y label obligatorios. code admite máximo 60, label máximo 150 y los códigos deben ser únicos dentro del parámetro. Los tipos qualitative y predefined_options necesitan al menos una opción al publicar.

Los tipos numeric y text no pueden incluir options. Los tipos no numéricos no pueden requerir ni declarar unidad.

## Referencias clínicas

Cada elemento de references admite:

| Campo | Tipo | Reglas |
| --- | --- | --- |
| key | string | Requerido; máximo 60; único dentro del parámetro |
| priority | integer | Requerido; entre 0 y 1,000,000 |
| age_min_days | integer o null | Mínimo 0; límite inclusivo; null o ausencia significa sin límite inferior |
| age_max_days | integer o null | Mínimo 0; límite inclusivo; debe ser mayor o igual a age_min_days |
| sex | string o null | Máximo 60; coincidencia exacta; null o ausencia funciona como comodín |
| conditions | objeto string a string | Máximo 30; se comparan todas con AND y valores exactos. Las claves empiezan con letra y continúan con letras, números o guion bajo, máximo 60 |
| min | number o null | Solo para referencias numeric |
| max | number o null | Solo para referencias numeric |
| text | string | Solo para referencias text; máximo 2000 |
| allowed_options | array de string | Solo para referencias qualitative o predefined_options; máximo 100; valores únicos y existentes en options |

Reglas al publicar:

- Una referencia numeric debe incluir min, max o ambos; si incluye ambos, min no puede ser mayor que max.

- Una referencia text necesita text no vacío.

- Una referencia categórica necesita allowed_options no vacío.

- Dos referencias que puedan coincidir con el mismo contexto no pueden tener la misma priority. Los límites de edad son inclusivos.

- El contrato operativo selecciona la prioridad más alta que coincida. Un empate o falta de contexto requiere una resolución autorizada y auditada.

# Ejemplos completos de configuration

## Examen sin muestra física

~~~json
{
  "schema_version": 1,
  "code": "GLU",
  "name": "Glucosa",
  "description": "Medición de glucosa",
  "sample_mode": "none",
  "sample_requirements": [],
  "parameters": [
    {
      "code": "RESULT",
      "name": "Resultado",
      "type": "numeric",
      "unit_required": true,
      "unit": "mg/dL",
      "references": [
        {
          "key": "adult",
          "priority": 10,
          "age_min_days": 6570,
          "min": 70,
          "max": 99
        }
      ]
    }
  ]
}
~~~

## Examen con muestra física y schema 2

~~~json
{
  "schema_version": 2,
  "code": "CBC",
  "name": "Hemograma completo",
  "sample_mode": "physical",
  "sample_requirements": [
    {
      "key": "primary_blood",
      "sample_type_id": 9,
      "volume": 3,
      "volume_unit": "mL",
      "container": "Tubo EDTA",
      "instructions": "Mezclar suavemente",
      "mandatory": true,
      "additives": ["EDTA"],
      "collection_conditions": {
        "fasting": "not_required"
      },
      "storage_conditions": {
        "temperature": "2-8C"
      },
      "max_age_minutes": 240,
      "max_transport_minutes": 120
    }
  ],
  "parameters": [
    {
      "code": "HGB",
      "name": "Hemoglobina",
      "type": "numeric",
      "unit_required": true,
      "unit": "g/dL",
      "references": [
        {
          "key": "adult_female",
          "priority": 20,
          "age_min_days": 6570,
          "sex": "female",
          "min": 12,
          "max": 16
        },
        {
          "key": "adult_male",
          "priority": 20,
          "age_min_days": 6570,
          "sex": "male",
          "min": 13,
          "max": 17
        }
      ]
    },
    {
      "code": "MORPHOLOGY",
      "name": "Morfología",
      "type": "qualitative",
      "options": [
        { "code": "normal", "label": "Normal" },
        { "code": "abnormal", "label": "Anormal" }
      ],
      "references": [
        {
          "key": "general",
          "priority": 0,
          "allowed_options": ["normal"]
        }
      ]
    }
  ]
}
~~~

# Resumen de endpoints y permisos

| Método y ruta | Propósito | Permiso |
| --- | --- | --- |
| POST /laboratory-exams/drafts | Crear examen local inactivo y primer borrador | laboratory_exams.create_draft |
| GET /global-exam-templates | Listar plantillas globales | laboratory_exams.view |
| POST /laboratory-exams/import-template | Importar una plantilla como copia local | laboratory_exams.import_template |
| GET /laboratory-exams/{laboratoryExam}/versions | Listar versiones | laboratory_exams.view |
| POST /laboratory-exams/{laboratoryExam}/versions | Crear un nuevo borrador | laboratory_exams.create_draft |
| GET /laboratory-exams/{laboratoryExam}/versions/current | Obtener la publicación actual o null | laboratory_exams.view |
| GET /laboratory-exams/{laboratoryExam}/order-configuration | Obtener el contrato utilizable en órdenes | laboratory_exams.view |
| GET /laboratory-exams/{laboratoryExam}/versions/{version} | Consultar una versión específica | laboratory_exams.view |
| PUT /laboratory-exams/{laboratoryExam}/versions/{version} | Reemplazar un borrador | laboratory_exams.edit_draft |
| POST /laboratory-exams/{laboratoryExam}/versions/{version}/validate-publication | Validar sin publicar | laboratory_exams.publish |
| POST /laboratory-exams/{laboratoryExam}/versions/{version}/publish | Publicar de forma atómica | laboratory_exams.publish |

# Detalle de endpoints

## 1. Crear examen y borrador

POST /api/v1/laboratory-exams/drafts

Crea en una sola transacción:

- Un examen de catálogo en estado inactive.

- Su registro de estado clínico.

- La versión número 1 en estado draft y revision 1.

Body:

| Campo | Requerido | Reglas |
| --- | --- | --- |
| code | Sí | String, máximo 30; se recorta al inicio y final; debe ser único dentro del laboratorio |
| name | Sí | String, máximo 150; se recorta al inicio y final |
| laboratory_area_id | Sí | Entero positivo; debe identificar un área activa del laboratorio |
| sample_type_id | No | Entero positivo o null; si se envía debe ser un tipo de muestra activo local |
| configuration | No | Objeto; puede ser incompleto mientras sea estructuralmente válido como borrador |

~~~json
{
  "code": "CBC",
  "name": "Hemograma completo",
  "laboratory_area_id": 4,
  "sample_type_id": 9,
  "configuration": {
    "schema_version": 2,
    "sample_mode": "physical",
    "sample_requirements": [],
    "parameters": []
  }
}
~~~

El backend sobrescribe configuration.code y configuration.name con los valores superiores. Si falta schema_version, usa 1.

Respuesta: 201 con una ClinicalExamVersion dentro de data.

Conflictos relevantes:

- exam_code_conflict, 409: el código ya está reservado en el laboratorio.

- 422 en laboratory_area_id o sample_type_id: el catálogo relacionado no existe, pertenece a otro laboratorio o no está activo.

- Los campos superiores desconocidos producen 422.

## 2. Listar plantillas globales

GET /api/v1/global-exam-templates?page=1

No lleva body. page es opcional, entero mínimo 1. La página contiene como máximo 50 elementos y no admite per_page.

Cada elemento de data tiene:

~~~json
{
  "id": 3,
  "key": "CBC_STANDARD",
  "name": "Hemograma estándar",
  "configuration": {}
}
~~~

Las plantillas son artefactos globales inmutables. La plantilla no cambia cuando se edita la copia local importada.

La respuesta incluye la estructura de paginación de Laravel: data, links y meta.

## 3. Importar plantilla

POST /api/v1/laboratory-exams/import-template

Crea un examen local inactive y un borrador independiente basado en la plantilla.

Body:

| Campo | Requerido | Reglas |
| --- | --- | --- |
| template_id | Sí | Entero positivo; ID de una plantilla global existente |
| code | Sí | String, máximo 30 y único en el laboratorio |
| name | Sí | String, máximo 150 |
| laboratory_area_id | Sí | Área activa local |
| sample_type_id | No | Tipo de muestra activo local o null; corresponde a metadata del catálogo |
| sample_type_map | No | Objeto requirement_key a sample_type_id; máximo 30 entradas |

~~~json
{
  "template_id": 3,
  "code": "CBC",
  "name": "Hemograma completo",
  "laboratory_area_id": 4,
  "sample_type_id": 9,
  "sample_type_map": {
    "primary_blood": 9,
    "secondary_blood": 10
  }
}
~~~

El servidor reemplaza code y name de la plantilla por los valores del body. También reemplaza cada sample_requirements[n].sample_type_id usando sample_type_map y elimina cualquier sample_type_name previo.

Un key adicional que no exista en la plantilla produce 422. Un key de la plantilla que no se mapee queda con sample_type_id null: el borrador puede crearse, pero no podrá publicarse hasta asignarle un tipo activo.

Una misma plantilla solo puede importarse una vez por laboratorio. Un segundo intento responde 409 con template_already_imported.

## 4. Listar versiones de un examen

GET /api/v1/laboratory-exams/{laboratoryExam}/versions?page=1

Parámetros:

- laboratoryExam: ID del examen de catálogo.

- page: opcional, entero mínimo 1.

Devuelve todas las versiones del examen, ordenadas por number descendente, 50 por página. Incluye borradores y publicaciones. Usa la respuesta paginada de recursos de Laravel con data, links y meta.

No envíe filtros ni per_page: los campos de entrada no reconocidos se rechazan.

## 5. Consultar una versión específica

GET /api/v1/laboratory-exams/{laboratoryExam}/versions/{version}

version es el id de la versión, no su number.

Devuelve 200 con la versión dentro de data. La versión debe pertenecer al examen y al laboratorio indicados; de lo contrario responde 404. No lleva body ni parámetros de query.

## 6. Crear un nuevo borrador

POST /api/v1/laboratory-exams/{laboratoryExam}/versions

Body opcional:

~~~json
{
  "source_version_id": 81
}
~~~

source_version_id acepta un entero positivo o null y representa el id de una versión del mismo examen.

Comportamiento:

- Si el examen ya tiene una versión publicada actual, source_version_id es obligatorio y debe apuntar a una versión published.

- Se puede copiar la versión publicada actual o una publicación histórica.

- Si todavía no existe una publicación actual, el body puede omitirse. En ese caso se crea una configuración mínima con schema_version 1, code y name.

- El nuevo borrador recibe el siguiente number, revision 1 y base_version_id igual a la publicación que era actual al crear el borrador.

- sample_type_name se elimina de la copia porque el servidor lo volverá a calcular al publicar.

Respuesta: 201 con el nuevo borrador.

Conflictos:

- source_version_required, 409: existe una publicación actual pero no se indicó fuente.

- source_not_published, 409: la fuente es un draft.

- 404: la fuente no pertenece al examen o laboratorio.

## 7. Reemplazar la configuración de un borrador

PUT /api/v1/laboratory-exams/{laboratoryExam}/versions/{version}

Body obligatorio:

~~~json
{
  "revision": 1,
  "configuration": {
    "schema_version": 1,
    "code": "CBC",
    "name": "Hemograma completo",
    "sample_mode": "none",
    "parameters": [
      {
        "code": "OBS",
        "name": "Observación",
        "type": "text",
        "text_max_length": 1000
      }
    ]
  }
}
~~~

revision debe ser exactamente la revision recibida por frontend al leer o crear el borrador. configuration debe estar presente y ser un objeto. El PUT reemplaza todo el documento, por lo que frontend debe conservar y reenviar los campos que no hayan cambiado.

En éxito responde 200 y aumenta revision. Actualice el estado local con toda la respuesta; no incremente la revisión de forma optimista sin usar el valor retornado.

Errores importantes:

- revision_conflict, 409: otro guardado cambió el borrador. Recargar antes de permitir un nuevo guardado.

- version_not_editable, 409: la versión ya está publicada.

- 422: estructura inválida, relación de muestra inválida, campo desconocido o intento de enviar sample_type_name.

## 8. Validar antes de publicar

POST /api/v1/laboratory-exams/{laboratoryExam}/versions/{version}/validate-publication

No lleva body. No necesita revision y no modifica ni reserva el borrador.

Respuesta:

~~~json
{
  "data": {
    "publishable": false,
    "errors": {
      "parameters": [
        "Publication requires at least one parameter."
      ],
      "sample_requirements.0.sample_type_id": [
        "Physical requirements need a local sample type."
      ]
    }
  }
}
~~~

errors es un mapa de ruta de campo a lista de mensajes. Las rutas usan índices de arrays, por ejemplo parameters.2.references.0.allowed_options.

Además de todas las reglas del contrato, verifica:

- Que la versión siga siendo draft.

- Que configuration.code coincida con el código actual del catálogo.

- Que base_version_id todavía coincida con la versión publicada actual.

Esta validación es orientativa y puede quedar obsoleta si otro usuario publica o edita antes del POST de publicación. El endpoint publish siempre vuelve a validar dentro de su transacción.

## 9. Publicar una versión

POST /api/v1/laboratory-exams/{laboratoryExam}/versions/{version}/publish

Body:

~~~json
{
  "revision": 4
}
~~~

revision es obligatoria y debe coincidir con la revisión actual del borrador.

La operación es atómica:

1. Bloquea el examen y la versión.

2. Confirma que sigue siendo draft y que revision coincide.

3. Confirma que su base no quedó obsoleta.

4. Ejecuta las reglas estrictas de publicación.

5. Revalida que cada tipo de muestra siga activo.

6. Inserta sample_type_name como snapshot de servidor.

7. Calcula configuration_hash.

8. Cambia status a published, registra fecha y usuario, y aumenta revision.

9. Convierte esta versión en la publicación current del examen.

Después de publicarse, ni la versión ni sus descendientes JSON se pueden editar o eliminar.

Errores:

- revision_conflict, 409: revisión desactualizada.

- version_not_editable, 409: ya fue publicada.

- publication_conflict, 409: otra versión fue publicada después de crear este borrador; se debe crear un nuevo borrador.

- publication_invalid, 422: errors contiene los detalles por campo.

- 422 si un tipo de muestra dejó de estar activo durante el proceso.

Respuesta: 200 con la versión publicada.

## 10. Consultar la versión publicada actual

GET /api/v1/laboratory-exams/{laboratoryExam}/versions/current

No lleva body.

Si existe una publicación actual:

~~~json
{
  "data": {
    "id": 81,
    "status": "published"
  }
}
~~~

La respuesta real incluye todos los campos de ClinicalExamVersion.

Si todavía no se ha publicado ninguna versión:

~~~json
{
  "data": null
}
~~~

El estado active o inactive del examen no cambia esta consulta. Este endpoint responde la publicación actual aunque el examen esté inactivo.

## 11. Consultar configuración utilizable en órdenes

GET /api/v1/laboratory-exams/{laboratoryExam}/order-configuration

Solo responde exitosamente si el examen de catálogo está active y tiene una versión publicada actual.

~~~json
{
  "data": {
    "schema_version": 1,
    "laboratory_exam_id": 25,
    "clinical_version_id": 81,
    "clinical_version_number": 2,
    "configuration_hash": "64-caracteres-hexadecimales",
    "configuration": {},
    "result_context_contract": {
      "capture_at": "interpretation_or_validation",
      "required_snapshot_fields": [
        "reference_date",
        "age_days",
        "sex",
        "conditions",
        "clinical_version_id",
        "parameter_code",
        "reference_key"
      ],
      "priority_rule": "highest_matching_priority",
      "missing_context_rule": "authorized_audited_resolution",
      "no_match_or_tie_rule": "authorized_audited_resolution"
    }
  }
}
~~~

schema_version en la raíz de esta respuesta es la versión del contrato de integración de órdenes y actualmente siempre es 1. No debe confundirse con data.configuration.schema_version, que puede ser 1 o 2.

Si falta una publicación o el examen está inactive, responde 409 con clinical_exam_unavailable.

# Endpoint relacionado para activar o desactivar

PATCH /api/v1/laboratory-exams/{laboratoryExam}/status

Este endpoint pertenece al módulo de catálogo Laboratory Exams, pero es necesario para completar el flujo clínico.

Permiso: laboratory_exams.change_status.

Body:

~~~json
{
  "status": "active"
}
~~~

status acepta active o inactive. Cuando el examen está inscrito en el sistema clínico, el backend impide activarlo hasta que tenga una versión publicada actual y responde:

~~~json
{
  "message": "Publish a clinical version before activating this exam.",
  "code": "clinical_exam_unavailable",
  "errors": []
}
~~~

Desactivar el examen no elimina ni modifica sus versiones. Las versiones publicadas continúan consultables, pero order-configuration deja de estar disponible hasta reactivarlo.

# Respuestas de error

## Validación 422

Los errores normales de Laravel usan:

~~~json
{
  "message": "The given data was invalid.",
  "errors": {
    "campo": [
      "Mensaje de validación."
    ]
  }
}
~~~

publication_invalid también usa status 422, pero conserva el formato de conflicto clínico:

~~~json
{
  "message": "The draft is not publishable.",
  "code": "publication_invalid",
  "errors": {
    "parameters": [
      "Publication requires at least one parameter."
    ]
  }
}
~~~

## Conflicto clínico 409

~~~json
{
  "message": "The draft has changed. Reload it before writing.",
  "code": "revision_conflict",
  "errors": []
}
~~~

Códigos que frontend debe manejar explícitamente:

| code | HTTP | Acción recomendada |
| --- | --- | --- |
| exam_code_conflict | 409 | Marcar code como ya utilizado |
| template_already_imported | 409 | Informar que la plantilla ya tiene una copia local |
| source_version_required | 409 | Consultar current y reenviar su id como fuente |
| source_not_published | 409 | Permitir seleccionar solo versiones published |
| revision_conflict | 409 | Recargar la versión y resolver cambios locales |
| version_not_editable | 409 | Abrir la versión como solo lectura |
| publication_conflict | 409 | Desechar o conservar el borrador como referencia y crear uno nuevo desde la publicación actual |
| publication_invalid | 422 | Mostrar errors por ruta de campo |
| clinical_exam_unavailable | 409 | Publicar una versión y/o activar el examen |

Otros estados comunes:

- 400: falta X-Laboratory-ID o tiene formato inválido.

- 401: no existe sesión autenticada.

- 403: membresía, suscripción o permiso insuficiente.

- 404: recurso inexistente en el laboratorio actual.

- 422: body o query inválido, campo desconocido o relación local inválida.

# Recomendaciones de implementación frontend

- Mantener laboratory_exam_id y version.id como identificadores separados.

- Usar siempre el id de versión en {version}; number es solo informativo.

- Conservar revision junto al formulario y reemplazarla con la respuesta de cada PUT.

- Deshabilitar edición cuando status sea published.

- Tratar configuration como un documento completo en el PUT.

- No enviar sample_type_name, configuration_hash, published_at, published_by, number, base_version_id, status ni otros campos controlados por servidor.

- No permitir propiedades adicionales en formularios o serializadores.

- Para referencias, construir errors mediante rutas con índices para señalar el control exacto.

- Ejecutar validate-publication antes de habilitar la confirmación final, pero asumir que publish todavía puede responder conflicto.

- Después de publicar, conservar la respuesta porque contiene el snapshot de sample_type_name y el hash definitivos.

- Consultar current después de un publication_conflict o al abrir el editor.

- Para una plantilla física, construir sample_type_map a partir de cada sample_requirements[].key.

- No inferir que una publicación implica disponibilidad comercial: comprobar también el estado active.

- Si order-configuration responde clinical_exam_unavailable, mostrar una acción diferenciada según el usuario pueda publicar o cambiar estado.

# Secuencia completa de ejemplo

~~~http
POST /api/v1/laboratory-exams/drafts
~~~

~~~json
{
  "code": "OBS",
  "name": "Observación clínica",
  "laboratory_area_id": 4,
  "sample_type_id": null,
  "configuration": {
    "schema_version": 1,
    "sample_mode": "none",
    "parameters": [
      {
        "code": "NOTE",
        "name": "Nota",
        "type": "text",
        "text_max_length": 2000
      }
    ]
  }
}
~~~

La respuesta crea laboratory_exam_id 25, version id 81 y revision 1.

~~~http
POST /api/v1/laboratory-exams/25/versions/81/validate-publication
~~~

Sin body. Si publishable es true:

~~~http
POST /api/v1/laboratory-exams/25/versions/81/publish
~~~

~~~json
{
  "revision": 1
}
~~~

La respuesta publicada podría devolver revision 2.

~~~http
PATCH /api/v1/laboratory-exams/25/status
~~~

~~~json
{
  "status": "active"
}
~~~

Finalmente:

~~~http
GET /api/v1/laboratory-exams/25/order-configuration
~~~

Si responde 200, el examen ya cumple el contrato clínico necesario para integrarse en órdenes.

# Fuentes de verdad en el backend

La integración está definida principalmente en:

- routes/api.php

- app/Http/Controllers/Api/V1/ClinicalExamController.php

- app/Http/Requests/Api/V1/ClinicalExam/ClinicalExamRequest.php

- app/ClinicalExams/ClinicalExamService.php

- app/ClinicalExams/ClinicalConfigurationValidator.php

- app/Http/Resources/Api/V1/ClinicalExamVersionResource.php

- app/OpenApi/ClinicalExamDocumentation.php

- tests/Feature/ClinicalExams/ClinicalExamTest.php

Si el contrato cambia, frontend debe contrastar esta guía con el OpenAPI generado y con estas reglas de runtime.

