# Donqer Lab — BE-S2-01: Contratos técnicos de órdenes

**Versión:** 1.0 (documentación técnica)  
**Fecha:** 10 de octubre de 2026  
**Estado:** Backend revisado; DT-14–DT-17 aprobadas funcionalmente; propuestas técnicas pendientes de aprobación  
**Documento rector:** [Ciclo de vida de órdenes y reglas de negocio, v1.1](../DONQER_LAB_CICLO_DE_VIDA_ORDENES.md).

## 1. Alcance y clasificación

Este complemento registra contratos, evidencia y brechas de BE-S2-01. No implementa funcionalidades, no define migraciones ejecutables y no sustituye las reglas del documento funcional. Las referencias a código corresponden al repositorio inspeccionado; no prueban que una migración esté aplicada en una base activa.

Se utilizan tres categorías:

- **Implementado actualmente:** código y pruebas existentes inspeccionados.
- **Aprobado funcionalmente, pendiente de implementación:** obligaciones de D01–D13 y DT-14–DT-17 que aún no tienen soporte suficiente.
- **Propuesta técnica que necesita aprobación:** nombres, payloads, respuestas o procedimientos recomendados. No deben implementarse como decisiones ya ratificadas.

En esta revisión documental no se ejecutaron pruebas ni comandos de base de datos. Los casos existentes se citan como evidencia de cobertura escrita, no de ejecución exitosa. La auditoría anterior ejecutó únicamente la suite Unit: 31 pruebas y 55 aserciones; ese resultado no valida el futuro contrato clínico.

## 2. Estado actual del backend verificado

### 2.1 Rutas y permisos

Todas estas rutas existentes usan el prefijo `/api/v1`, el grupo `saas` y el permiso `can:` indicado. El grupo incluye `auth:sanctum`, `laboratory.context`, `permission.team`, `subscription.active` ([bootstrap/app.php](../../laravel/bootstrap/app.php), líneas 19–33).

| Método y ruta existente | LaboratoryOrderController | Permiso actual | Contrato observable |
|---|---|---|---|
| GET `/laboratory-orders` | `index` | `orders.view` | Listado paginado, filtros y búsqueda. |
| POST `/laboratory-orders` | `store` | `orders.create` | Cabecera `pending`, sin líneas. |
| GET `/laboratory-orders/{laboratoryOrder}` | `show` | `orders.view` | Recurso de cabecera. |
| GET `/laboratory-orders/{laboratoryOrder}/exams` | `listExams` | `orders.view` | Colección sin paginación de solicitudes, con snapshots. |
| POST `/laboratory-orders/{laboratoryOrder}/exams` | `addExam` | `orders.add_exam` | Añade una solicitud; sólo `pending`. |
| DELETE `/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}` | `removeExam` | `orders.remove_exam` | Borra físicamente una solicitud; sólo `pending`. |
| PUT `/laboratory-orders/{laboratoryOrder}/discount` | `updateDiscount` | `orders.manage_discount` | Establece intención porcentual o por importe; sólo `pending`. |
| DELETE `/laboratory-orders/{laboratoryOrder}/discount` | `removeDiscount` | `orders.manage_discount` | Quita descuento y recalcula; sólo `pending`. |
| PATCH `/laboratory-orders/{laboratoryOrder}/status` | `updateStatus` | `orders.change_status` | Recibe únicamente `status`. |

Fuente: [routes/api.php](../../laravel/routes/api.php), líneas 42–66; [LaboratoryOrderController](../../laravel/app/Http/Controllers/Api/V1/LaboratoryOrderController.php), métodos `store`, `addExam`, `removeExam`, `updateStatus`, `show` y `listExams`.

No hay actualización general de cabecera, ni endpoints clínicos de requisitos, muestras físicas, resultados, resoluciones finales o cancelación excepcional. No se encontraron policies de órdenes específicas. La autorización actual combina middleware de permisos con validaciones y acciones de dominio.

### 2.2 Request actual de creación

[StoreLaboratoryOrderRequest](../../laravel/app/Http/Requests/Api/V1/LaboratoryOrder/StoreLaboratoryOrderRequest.php), líneas 12–63, permite exclusivamente:

| Campo | Contrato actual |
|---|---|
| `branch_id`, `patient_id`, `price_list_id` | Obligatorios; enteros JSON positivos reales, no cadenas ni booleanos. |
| `doctor_id`, `commercial_client_id` | Claves obligatorias, valores anulables o enteros JSON positivos. |
| `ordered_at` | Obligatorio; string exacto `YYYY-MM-DD HH:mm:ss`. |
| `notes` | Clave obligatoria; string o null. |

Un campo adicional, incluido `exams`, se rechaza. `authorize()` devuelve true; la autorización efectiva está en la ruta. No se aceptan precios, estado, tenant ni actor elegidos por el cliente.

Ejemplo **compatible con el contrato actual**, con IDs ilustrativos que deben existir en el laboratorio:

```json
{
  "branch_id": 1,
  "patient_id": 42,
  "doctor_id": null,
  "commercial_client_id": null,
  "price_list_id": 3,
  "ordered_at": "2026-10-10 09:30:00",
  "notes": null
}
```

[CreateLaboratoryOrder](../../laravel/app/Actions/LaboratoryOrders/CreateLaboratoryOrder.php), líneas 39–129, verifica referencias activas del tenant y crea cabecera y evento `order.created` dentro de una transacción. Conserva nombre/tipo de entidad comercial, nombre de lista y moneda. Genera `ORD-` + ULID y totales cero. Las referencias se leen antes de la transacción: el futuro contrato debe resolver la consistencia frente a cambios concurrentes de catálogo.

La lista de precios es una selección explícita. La creación no ejecuta `EffectivePriceListResolver` ni exige que coincida con la asignación comercial vigente. Se mantiene como contrato actual: [LaboratoryOrderStoreTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStoreTest.php), `test_explicit_price_list_override_ignores_commercial_assignment_and_resolver`.

### 2.3 Modelos, persistencia y servicios

| Estructura real | Implementado actualmente | Brecha para las decisiones aprobadas |
|---|---|---|
| [LaboratoryOrder](../../laravel/app/Models/LaboratoryOrder.php) / `laboratory_orders` | Cabecera, relaciones, economía y cuatro estados. | Política financiera efectiva, reglas clínicas de transición y cancelación motivada. |
| [LaboratoryOrderExam](../../laravel/app/Models/LaboratoryOrderExam.php) / `laboratory_order_exams` | ID independiente; `laboratory_exam_id`, `price_list_id`, `unit_price`, `exam_code`, `exam_name`, `price_list_name`. | Avance operativo, configuración clínica histórica, resultados y resolución final. |
| [LaboratoryExam](../../laravel/app/Models/LaboratoryExam.php) / `laboratory_exams` | Área, un `sample_type_id`, código/nombre y tiempo de respuesta. | Varias necesidades de muestra, declaración explícita sin muestra, parámetros y configuración versionada. |
| [AddExamToLaboratoryOrder](../../laravel/app/Actions/LaboratoryOrders/AddExamToLaboratoryOrder.php) | Bloquea orden; valida catálogo/precio activo y moneda; crea snapshot comercial, recalcula y audita. | Snapshot clínico y generación/sincronización de requisitos. |
| [RemoveExamFromLaboratoryOrder](../../laravel/app/Actions/LaboratoryOrders/RemoveExamFromLaboratoryOrder.php) | Bloquea orden; comprueba tenant/padre; borra línea, recalcula y audita snapshot. | Protección de vínculos con muestras/actividad clínica y sincronización de requisitos. |
| [RecalculateLaboratoryOrderEconomics](../../laravel/app/Services/LaboratoryOrders/RecalculateLaboratoryOrderEconomics.php) | Recalcula desde las líneas del tenant; exige bloqueo por su llamador. | Integración consistente con composición atómica; conciliación legacy. |
| [LaboratoryOrderEconomicCalculator](../../laravel/app/Services/LaboratoryOrders/LaboratoryOrderEconomicCalculator.php) | Centavos enteros, porcentaje/importe, límite NUMERIC(12,2); impuestos calculados cero. | No representa pagos, saldos ni política financiera. |

Las migraciones establecen FK compuestas tenant para sucursal, paciente, médico, entidad, lista y relaciones de líneas. El creador referencia `users`, sin FK a membresía. Consultar [creación de órdenes](../../laravel/database/migrations/2026_10_03_120000_create_laboratory_orders_table.php), [creación de líneas](../../laravel/database/migrations/2026_10_05_012858_create_laboratory_order_exams_table.php) y [ampliación de estados](../../laravel/database/migrations/2026_10_04_120000_expand_laboratory_order_status_check.php).

La [migración de repeticiones](../../laravel/database/migrations/2026_10_05_014819_allow_repeated_exams_per_laboratory_order.php) elimina la unicidad orden/examen. No restaurarla: dos solicitudes iguales son legítimas y sus resultados/requisitos deben relacionarse con el ID de la línea, no sólo con `laboratory_exam_id`.

No se encontraron entidades clínicas de requisitos, muestras físicas, resultados/versiones, informes, destinatarios autorizados ni pagos. `SampleType` representa un maestro de tipos, no una muestra física.

### 2.4 Respuestas actuales

- Creación: HTTP 201, envelope `data` de `LaboratoryOrderResource`.
- Detalle/cambio de estado/descuento: recurso de cabecera; cambio de estado responde HTTP 200.
- Alta de examen: HTTP 201, `LaboratoryOrderExamResource`.
- Baja de examen: HTTP 204, sin cuerpo.

[LaboratoryOrderResource](../../laravel/app/Http/Resources/Api/V1/LaboratoryOrderResource.php) conserva `id`, `code`, `ordered_at`, `status`, `notes`, `patient`, `doctor`, `commercial_client`, `price_list`, `branch`, `subtotal`, `discount_type`, `discount_value`, `discount`, `taxes`, `total`, `currency`, `created_by`, `created_at`, `updated_at`. No incluye `exams` ni `sample_requirements`. Importes serializados como strings decimales; fecha de orden sin offset. Paciente/médico/sucursal se leen del maestro actual; los snapshots comerciales son persistidos.

### 2.5 Transiciones y auditoría actuales

[LaboratoryOrder::canTransitionTo](../../laravel/app/Models/LaboratoryOrder.php), líneas 71–86, permite `pending → in_process/cancelled`, `in_process → completed/cancelled`, y ningún cambio desde terminales. Repetir el estado actual es un no-op válido.

[TransitionLaboratoryOrderStatus](../../laravel/app/Actions/LaboratoryOrders/TransitionLaboratoryOrderStatus.php), líneas 26–54, bloquea la orden y audita el cambio efectivo, pero no verifica exámenes, muestras, validaciones ni política financiera. [UpdateLaboratoryOrderStatusRequest](../../laravel/app/Http/Requests/Api/V1/LaboratoryOrder/UpdateLaboratoryOrderStatusRequest.php) rechaza cualquier clave distinta de `status`, incluido un motivo.

[OrderAuditEvents](../../laravel/app/Audit/OrderAuditEvents.php) define `order.created`, `order_exam.added`, `order_exam.removed`, `order.discount_set`, `order.discount_removed`, `order.status_changed`. No incluye un contrato de resolución clínica o autorización excepcional. [AuditWriter](../../laravel/app/Audit/AuditWriter.php) participa en la transacción del llamador; [AuditLog](../../laravel/app/Models/AuditLog.php) evita modificaciones mediante eventos Eloquent, sin imponer inmutabilidad ante SQL directo.

## 3. Contrato propuesto de creación con exámenes opcionales

**Aprobado funcionalmente:** conservar `POST /api/v1/laboratory-orders`, modalidad de cabecera cuando no se envían exámenes y creación atómica cuando se incluyen; solicitudes repetidas independientes, snapshots económicos/clínicos, requisitos y auditoría, sin muestras físicas.

**Propuesta técnica que necesita aprobación (P01):** añadir `exams` como lista JSON de objetos `{ "laboratory_exam_id": entero }`. Un elemento representa una solicitud; no introducir `quantity` ni aplicar `distinct` al identificador del examen. Ausencia de `exams` conserva modalidad A; recomendar `exams: []` equivalente a cabecera y rechazar `null`, objetos asociativos o elementos mal formados. El límite de elementos debe acordarse según carga y uso; no se fija un máximo arbitrario aquí.

Ejemplo propuesto de modalidad B; **actualmente es rechazado**:

```json
{
  "branch_id": 1,
  "patient_id": 42,
  "doctor_id": null,
  "commercial_client_id": null,
  "price_list_id": 3,
  "ordered_at": "2026-10-10 09:30:00",
  "notes": null,
  "exams": [
    { "laboratory_exam_id": 7 },
    { "laboratory_exam_id": 7 },
    { "laboratory_exam_id": 9 }
  ]
}
```

**Propuesta de responses (P02):** conservar HTTP 201, envelope y campos de `LaboratoryOrderResource` sin cambiar tipos ni introducir colecciones obligatorias en la respuesta inicial. Consultar líneas mediante el GET existente `/laboratory-orders/{laboratoryOrder}/exams`. Proponer un GET de requisitos de orden separado cuando exista ese módulo. Si se necesita retornar líneas/requisitos en el POST, aprobar expansión explícita y revisar consumidores estrictos; no se presupone compatibilidad sólo porque el cambio sea aditivo.

La modalidad B debe responder totales persistidos de todas las líneas; modalidad A conserva los ceros actuales. La respuesta se construye con el resultado confirmado de la transacción, sin efectos externos capaces de convertir un éxito persistido en un fallo ambiguo. Un código ULID nuevo no evita órdenes duplicadas por reintentos.

### Validaciones necesarias

**Aprobado funcionalmente:** referencias del tenant, repetición legítima, consistencia clínica/económica y rollback completo. **Propuesta técnica (P01/P07):** conservar la validación estricta actual de cabecera y extenderla con errores por índice, por ejemplo `exams.1.laboratory_exam_id`.

| Validación propuesta | Comportamiento a implementar |
|---|---|
| Lista y elementos | Lista JSON de objetos; entero positivo real; campos de servidor o desconocidos rechazados; límite por aprobar. |
| Referencias | Sucursal/paciente y opcionales activos del tenant; examen, lista y precio activos; errores neutrales ante inexistencia o referencia ajena. |
| Precio/moneda | Obtener precio del servidor por línea; misma lista/moneda de cabecera; preservar elección explícita de lista existente. |
| Configuración clínica | Cada línea con configuración histórica suficiente; declaración explícita para examen sin muestra. No interpretar ausencia de requisitos/configuración como aprobación clínica. |
| Snapshots | Identificar parámetros, unidades, métodos, referencias y reglas de muestra aplicables mediante versión o snapshot; elegir representación en P04. |
| Economía | Suma de todas las solicitudes, incluidas repetidas; límite monetario; no aceptar totales del cliente. |
| Fallo parcial | Una referencia, snapshot, generación o auditoría inválida revierte cabecera y todas las líneas/requisitos/eventos. |

Propuesta de errores: conservar 401 autenticación, 400 contexto inválido/ausente, 403 membresía/suscripción/permiso, 404 neutral para recursos de orden ajenos/inexistentes y 422 para payload o reglas de dominio, siguiendo contratos existentes. Los códigos de dominio adicionales y el uso de 409 para conflictos de versión/idempotencia requieren aprobación (P07).

## 4. Matriz de transiciones

Los cuatro estados globales se conservan. La matriz describe obligaciones funcionales aprobadas; no autoriza nuevos estados ni presupone nuevos permisos ya existentes.

| Origen → destino | Implementado actualmente | Aprobado funcionalmente, pendiente de implementación |
|---|---|---|
| `pending → in_process` | Permitido con `orders.change_status`, sin condiciones clínicas/financieras. | Al menos una línea; política efectiva permite inicio; al menos una línea procesable; autorización de inicio. |
| `pending → cancelled` | Permitido sin motivo. | Motivo obligatorio, autorización y auditoría; preservar registros. |
| `in_process → completed` | Permitido sin comprobar resultados. | Al menos una línea; todas validadas o con resolución clínica final justificada; ninguna pendiente; permiso para completar. Sin exigir pago ni entrega. |
| `in_process → cancelled`, sin resultados validados | Permitido sin motivo ni evaluación de avance. | Motivo, autorización y auditoría; conservar actividades/muestras y demás registros. |
| `in_process → cancelled`, con resultados validados | Igual transición ordinaria que cualquier `in_process`. | Procedimiento excepcional, permiso específico, motivo/autorización y evaluación documental/destinatarios. |
| `completed → cancelled` | Rechazado. | Cancelación ordinaria prohibida. No se aprueba procedimiento excepcional desde `completed`. |
| `completed/cancelled → otro estado` | Rechazado. | Ninguna reapertura ordinaria aprobada; corrección no reabre automáticamente `completed`. |
| `pending → completed`, `in_process → pending` | Rechazado. | No se aprueban estas transiciones. |
| Estado → mismo estado | HTTP 200, no-op, sin evento nuevo. | Mantenerlo es propuesta de compatibilidad P03; no reutilizar un no-op como nueva cancelación o autorización excepcional. |

**Propuesta de request de transición ordinaria (P03):** conservar PATCH existente y `{ "status": "in_process" }` / `{ "status": "completed" }`; para cancelación ordinaria admitir `reason` obligatorio, string no vacío normalizado. Rechazar campos clínicos y actores arbitrarios. La longitud y codificación del motivo se deben acordar. El servidor atribuye actor y fecha.

```json
{
  "status": "cancelled",
  "reason": "Solicitud de cancelación registrada antes de validar resultados."
}
```

**Propuesta de procedimiento excepcional (P06):** operación específica de cancelación, o autorización persistida consumida por PATCH; elegir un mecanismo antes de implementar. Una cadena `authorization` enviada por el cliente no demuestra autorización. Mientras no exista el procedimiento completo, impedir que PATCH ordinario cancele una orden con cualquier versión validada. No definir aún una nueva ruta como contrato ratificado.

## 5. Invariantes de composición, procesamiento y cierre

Referencia funcional: secciones 5.3–5.6 y 7–9 del documento rector.

1. **Identidad:** vincular requisitos, resultados y resoluciones a `LaboratoryOrderExam.id`. El mismo examen de catálogo puede originar varias solicitudes independientes.
2. **Composición:** actualizar requisitos al crear/agregar/eliminar líneas, sin muestras ficticias. Compartir requisitos sólo cuando la configuración técnica determine compatibilidad y suficiencia; compartir tipo de muestra por sí solo no demuestra compatibilidad.
3. **Trazabilidad:** no borrar muestras, resultados, eventos o documentos ni desvincular silenciosamente sus relaciones históricas al cambiar composición.
4. **Procesabilidad individual:** el inicio global no autoriza a procesar todas las líneas. Cada operación clínica pertinente debe comprobar requisitos satisfechos o declaración explícita sin muestra.
5. **Dimensiones separadas:** estado operativo de línea y versión/estado de resultado son distintos; sus nombres exactos permanecen pendientes (P04). No usar `entered` como prueba de examen clínicamente finalizado.
6. **Cierre:** todas las solicitudes deben estar validadas o tener resolución final justificada con motivo/responsable/fecha. Una resolución no fabrica valores validados ni resuelve automáticamente la presentación en informes (P05).
7. **Corrección:** versión nueva y revalidación; conservar versiones e informes previos; no reabrir automáticamente la cabecera completada.
8. **Cancelación:** preservar registros financieros, clínicos y documentales; nunca devolver dinero automáticamente; aplicar clasificación excepcional cuando haya resultados validados.

**Propuesta de composición (P04):** mantener inicialmente altas/bajas limitadas a `pending`, como hoy; si una línea/requisito ya tiene actividad clínica, rechazar su eliminación física y exigir un mecanismo explícito de resolución/anulación que preserve historia. Para requisitos compartidos sin actividad, retirar sólo demanda de la línea eliminada y recalcular suficiencia. El bloqueo, anulación y recalculo de cantidades necesitan contrato aprobado antes de implementar; DT-14 no aprobó cambios de composición en `in_process`.

## 6. Transaccionalidad y concurrencia

**Aprobado funcionalmente:** la modalidad B es una unidad atómica de cabecera, líneas, snapshots, requisitos y auditoría; falla completa ante cualquier error. Operaciones sensibles deben comprobar las condiciones en backend y preservar historia.

**Propuesta técnica (P07):**

- Extender `CreateLaboratoryOrder` con una orquestación de dominio que reutilice validaciones y cálculo actuales; evitar varias peticiones HTTP o commits por línea. Recalcular una vez al terminar el lote es una optimización candidata, sin alterar snapshots ni auditoría por solicitud.
- Mantener el bloqueo de cabecera en composición y transición. Coordinar las futuras acciones de muestras, validación, resoluciones y pagos con el mismo esquema de bloqueos o versiones; bloquear sólo cabecera no protege datos que otros escritores modifican sin ese protocolo.
- Reevaluar bajo protección transaccional la existencia de líneas, procesabilidad, política/saldo, finales y presencia de validaciones. La clasificación de cancelación debe considerar cualquier versión validada conservada, no sólo el estado de un borrador de corrección; concretar consulta con el modelo de versiones.
- Definir orden uniforme de bloqueos para evitar deadlocks. Elegir locks adicionales de filas/versiones de catálogo para mantener coherencia frente a cambios de precio, configuración o estado.
- Serializar validación vs. cancelación, última resolución vs. cierre, cambios de composición vs. inicio y rechazo de muestra vs. procesamiento. El resultado confirmado debe cumplir las invariantes, cualquiera que sea la operación ganadora.
- Ningún correo/PDF remoto o devolución automática dentro de la transacción de órdenes. Los efectos externos futuros requieren coordinación posterior al commit y trazabilidad propia.

### Idempotencia

**Implementado actualmente:** repetir estado es no-op; dos altas iguales generan dos solicitudes legítimas; dos creaciones iguales pueden generar dos órdenes. DELETE repetido de la misma línea devuelve 404. Los tests de concurrencia de altas esperan dos líneas distintas.

**Propuesta técnica (P07):** evaluar `Idempotency-Key` para creación y mutaciones con reintentos. Una clave delimita una operación, no la unicidad de examen. Deben aprobarse alcance tenant/actor/endpoint, hash del payload, retención, respuesta de replay, claves con payload distinto, solicitudes simultáneas y reintento tras rollback. No deduplicar por paciente/fecha/examen ni silenciar solicitudes repetidas legítimas.

## 7. Multi-tenant, permisos y auditoría

**Implementado actualmente:** [ResolveLaboratoryContext](../../laravel/app/Http/Middleware/ResolveLaboratoryContext.php) exige header y membresía/laboratorio activos; [SetPermissionTeamContext](../../laravel/app/Http/Middleware/SetPermissionTeamContext.php) selecciona el team y limpia relaciones; [EnsureActiveSubscription](../../laravel/app/Http/Middleware/EnsureActiveSubscription.php) comprueba vigencia. [BelongsToLaboratory](../../laravel/app/Models/Concerns/BelongsToLaboratory.php) aporta un filtro explícito, no scope global.

[RbacCatalog](../../laravel/app/Authorization/RbacCatalog.php) contiene 41 permisos y seis roles; `orders.change_status` engloba hoy inicio, cierre y cancelación. `assertValid()` y tests fijan ese número. No existen permisos clínicos ni de cancelación excepcional.

**Aprobado funcionalmente:** autorización de inicio y cierre, permiso específico de cancelación excepcional, aislamiento y actor confiable, autorización diferenciada de captura/validación y de recolección/recepción.

**Propuesta técnica (P08):** separar `orders.start_processing`, `orders.complete`, `orders.cancel`, `orders.cancel_exceptional`; definir si `orders.change_status` se migra, se conserva como permiso puente limitado o se retira. No convertirlo automáticamente en cancelación excepcional. Definir si `orders.create` permite líneas embebidas o exige también `orders.add_exam`; actualizar matriz de roles, seeders, APIs de autorización y pruebas juntas.

Cada nueva referencia debe comprobar tenant y padre correcto. Para relaciones clínicas, mismo laboratorio no basta: muestra, requisito, línea y resultado deben pertenecer a la orden adecuada. Las FK compuestas son una segunda barrera; jobs/acciones deben recibir contexto confiable aun sin HTTP. Evaluar la activación global del usuario en operaciones sensibles: actualmente se comprueba explícitamente al login, no en el middleware SaaS revisado.

**Propuesta de auditoría (P04/P06):** conservar eventos existentes y agregar eventos semánticos de resoluciones/cancelaciones con motivo, actor servidor, fecha y referencia a evidencia excepcional. Distinguir captura/validación y decisiones clínicas/comerciales. Determinar almacenamiento estructurado consultable de motivos/resoluciones, sin depender sólo de texto libre en logs. Fallo de auditoría debe revertir la mutación. No volcar payloads completos ni datos sensibles innecesarios.

## 8. Compatibilidad con consumidores actuales

**Aprobado funcionalmente:** mismo endpoint, exámenes opcionales, modalidad de cabecera temporalmente compatible y mismos estados globales.

| Contrato existente | Tratamiento recomendado, sujeto a P01–P03/P08 |
|---|---|
| POST sin `exams` | Conservar campos, tipos, HTTP 201 y cabecera `pending` con economía cero. |
| POST con `exams` | Ampliación opcional; hoy falla 422. No cambiar silenciosamente selección explícita de lista. |
| GET cabecera/listado | Mantener representación económica/comercial; campos nuevos requieren contrato documentado. |
| GET líneas | Mantener identidad de cada solicitud y snapshots. No agrupar repetidos en la response. |
| POST/DELETE líneas | Conservar rutas; ampliar sincronización/protección clínica. La baja puede dejar de ser admisible si hay trazabilidad. |
| PUT/DELETE descuento | Mantener contrato actual de `pending`; coordinar economía con pagos cuando exista ese módulo. |
| PATCH inicio/cierre | Misma ruta candidata, pero reglas más estrictas aprobadas; clientes que avanzan sin evidencia clínica recibirán rechazo. |
| PATCH cancelación | Motivo nuevo obligatorio rompe payloads antiguos de cancelación: publicar/adaptar contrato, sin mantener una vía que evada DT-17. |
| Permisos | La compatibilidad temporal de creación no autoriza ampliar privilegios clínicos automáticamente. |

Documentar despliegue coordinado y actualización futura de OpenAPI/consumidores. Esta tarea no modifica frontend ni documentación PHP OpenAPI. No se aprueba un plazo de retirada de la modalidad A: es decisión pendiente P09.

## 9. Estrategia para órdenes históricas

**Propuesta técnica/operativa (P09):** inventariar datos en una tarea autorizada, clasificar por estado/líneas/configuración disponible y preparar migración gradual. Esta tarea no consulta ni modifica datos activos.

- `pending` sin líneas: conservar como cabecera pendiente de composición; nunca fabricar requisitos o muestras. No puede iniciar ni completar bajo las reglas nuevas.
- Líneas existentes: conservar IDs y snapshots económicos. Configuración clínica ausente debe identificarse y reconciliarse mediante revisión; no inferirla como configuración sin muestra.
- `in_process` sin pruebas clínicas nuevas: no inventar recolección, validación o resolución para permitir cierre/cancelación. Definir reconciliación autorizada y qué operaciones quedan bloqueadas mientras tanto.
- `completed`: conservar estado e historia; no convertirlo retrospectivamente en prueba de validación, ni crear resultados validados ficticios. Definir habilitación de correcciones/importaciones cuando no exista una versión clínica original verificable.
- `cancelled`: preservar terminalidad y registros; motivo ausente se registra como brecha legacy, no se atribuye uno inventado ni un autor nuevo.
- Backfills de snapshots comerciales sólo recuperan valores vigentes al migrar, no sus valores originales. Descuentos antiguos sin intención e impuestos históricos se eliminan al recalcular según el comportamiento actual: [LaboratoryOrderEconomicRecalculationTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderEconomicRecalculationTest.php), `test_explicit_recalculation_clears_legacy_discount_and_historical_taxes`.
- Usar expansión, conciliación verificable y restricciones posteriores; probar PostgreSQL y recuperación antes del despliegue. No restaurar unicidad de exámenes repetidos ni cambiar masivamente estados para sortear nuevas reglas.

## 10. Pruebas y criterios de aceptación

### Cobertura escrita actual a conservar o actualizar

| Evidencia real | Contrato cubierto / impacto |
|---|---|
| [LaboratoryOrderStoreTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStoreTest.php) | Cabecera exacta, tipos estrictos, campos desconocidos, referencias tenant/activas, lista explícita y pipeline. Revisar rechazo de campos futuros y expectativas de no crear líneas. |
| [LaboratoryOrderExamStoreTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamStoreTest.php) | Repeticiones, snapshots, precio/moneda, pending y rollback; incorporar requisitos/configuración. |
| [LaboratoryOrderExamDestroyTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamDestroyTest.php) | ID de línea, padre/tenant, baja repetida 404, pending y rollback; ampliar protección clínica. |
| [LaboratoryOrderStatusTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStatusTest.php) | Transiciones, no-op y payload sólo status. Actualizar fixtures para DT-15/16 y motivos DT-17. |
| [LaboratoryOrderAuthorizationTest](../../laravel/tests/Feature/Authorization/LaboratoryOrderAuthorizationTest.php) | Matriz de roles, permisos directos, A→B→A, 404 cruzados y denegación sin mutaciones. |
| [LaboratoryOrderAuditTest](../../laravel/tests/Feature/Audit/LaboratoryOrderAuditTest.php) | Eventos allowlist, no-op, snapshots y rollback por fallo de auditoría. |
| [LaboratoryOrderHistoricalSnapshotTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderHistoricalSnapshotTest.php) | Historia comercial independiente del catálogo mutable. |
| [LaboratoryOrderStatusConcurrencyTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderStatusConcurrencyTest.php) y [LaboratoryOrderExamConcurrencyTest](../../laravel/tests/Feature/Api/V1/LaboratoryOrders/LaboratoryOrderExamConcurrencyTest.php) | Serialización de operaciones actuales en PostgreSQL; no validan todavía carreras clínicas. |

### Escenarios nuevos trazables

| ID | Decisión | Criterio de aceptación/prueba necesaria |
|---|---|---|
| CT-01 | DT-14 | Payload actual sin `exams` conserva response y ceros; cero líneas, requisitos y muestras. |
| CT-02 | DT-14/P01 | Vacío, null, objeto, ID como cadena, campo desconocido, límites y errores por índice según contrato aprobado. |
| CT-03 | DT-14 | Dos solicitudes del mismo catálogo crean dos IDs y snapshots independientes; no usar `distinct`. |
| CT-04 | DT-14 | Un fallo en cualquier línea, snapshot, requisito, total o auditoría deja cero escrituras de la creación. |
| CT-05 | DT-14/D05 | Crear con material requerido genera requisitos; nunca muestras físicas. Compatibles comparten demanda según configuración. |
| CT-06 | DT-14 | Altas/bajas sincronizan requisitos compartidos; vínculos con actividad clínica no se borran silenciosamente. |
| CT-07 | DT-15 | Falta de líneas, permiso, política favorable o línea procesable: inicio rechazado sin mutación/auditoría de éxito. |
| CT-08 | DT-15 | Una línea procesable habilita inicio aunque otra espere muestras; la segunda sigue bloqueada para trabajo clínico. |
| CT-09 | DT-15 | Configuración explícita sin muestra permite procesabilidad; configuración ausente no la permite. |
| CT-10 | DT-16 | Cierre autorizado con todas validadas/finales justificadas, aunque haya saldo y ninguna entrega. |
| CT-11 | DT-16 | Cero líneas, una pendiente, falta de permiso o resolución sin motivo/actor/fecha impiden completar. |
| CT-12 | DT-16/D10 | Corrección de completed crea versión/revalidación sin reabrir ni alterar versión/PDF previo. |
| CT-13 | DT-17 | Pending/in_process sin validados requieren motivo; cancelación conserva muestras, actividades, finanzas y documentos. |
| CT-14 | DT-17 | In_process con cualquier versión validada no admite vía ordinaria; excepción exige permiso/procedimiento y evaluación de impacto. |
| CT-15 | DT-17 | Completed no admite cancelación ordinaria; cancelled no permite transición ordinaria a otro estado; ninguna devolución automática. |
| CT-16 | Transversal | Tenant, padre correcto, usuario activo, membresía, suscripción y permisos; A→B→A sin fuga ni actor falsificado. |
| CT-17 | P07 | Carreras inicio/composición, inicio/rechazo, cierre/resolución, cancelación/validación, pagos/política cumplen invariantes PostgreSQL. |
| CT-18 | P07 | Retry de creación y alta según idempotencia aprobada; distinguir replay de dos solicitudes legítimas iguales. |
| CT-19 | P09 | Legacy preserva snapshots e historia sin fabricar datos clínicos; rollback/migración ensayados en base aislada. |

Usar PHPUnit existente. Las pruebas con `RefreshDatabase` requieren una base desechable explícitamente verificada; las suites PostgreSQL/pcntl no deben ejecutarse contra BD compartida ni darse por cubiertas mediante SQLite.

## 11. Riesgos y decisiones pendientes de aprobación

Los siguientes puntos son propuestas o detalles abiertos; las reglas generales de DT-14–DT-17 no se vuelven a someter a aprobación.

| ID | Decisión pendiente | Recomendación / riesgo |
|---|---|---|
| P01 | Forma de `exams`, arreglo vacío/null y máximo de líneas | Objetos por solicitud, vacío como cabecera, null inválido; límite a acordar. Cambiar el formato después afecta consumidores. |
| P02 | Response de creación ampliada | Conservar recurso actual y consultar colecciones separadas; si se amplía, aprobar campos y pruebas de compatibilidad. |
| P03 | Motivo en PATCH y no-op de estados | Añadir `reason` para cancelación ordinaria; conservar no-op sin eventos, definir validación del replay. El payload antiguo de cancelación queda incompatible. |
| P04 | Versiones/snapshots clínicos, estados de línea y cambios con trazabilidad | Reutilizar líneas/catalogo locales; mantener composición pending inicialmente; proteger borrado con actividad. Definir persistencia y sincronización antes de migrar. |
| P05 | Resoluciones finales y representación documental | Aprobar tipos permitidos, responsables y cómo se muestran en informe completo, sin inventar valores validados. |
| P06 | Procedimiento excepcional y consecuencias documentales | Elegir evidencia/autorización, operación técnica, evaluación/notificación y posibilidad de nuevas entregas de canceladas; hasta definirlo, bloquear vía ordinaria con validados. |
| P07 | Política temporal/finanzas, bloqueos, idempotencia y errores | Definir fijación/revisión de política efectiva y saldo, coordinación de escritores, claves/replay/retención y conflictos; evita carreras y duplicados. |
| P08 | Nombres/matriz de permisos y transición desde `orders.change_status` | Separar capacidades; decidir permiso para líneas embebidas; no conceder excepción automáticamente. |
| P09 | Reconciliación legacy y duración de compatibilidad de cabecera | Revisar datos en tarea autorizada, definir operación durante transición y criterios/plazo de retirada; no asumir que historial comercial acredita actividad clínica. |

Otros detalles funcionales previos —compatibilidad/volumen de muestras, evidencia de destinatarios, resultados críticos, canales efectivos— siguen pendientes en la sección 15 del documento rector. Deben resolverse en sus módulos antes de habilitar el flujo clínico correspondiente; BE-S2-01 no los inventa ni elimina.

Riesgos adicionales: el catálogo actual de tipo único no puede expresar todas las necesidades; el borrado físico deja de ser seguro con vínculos clínicos; la auditoría Eloquent puede eludirse con SQL; el recálculo legacy puede alterar cargos antes de incorporar pagos; modificar el catálogo RBAC requiere actualizar su comprobación fija de 41 permisos. No se corrigen en esta tarea.

## 12. Dependencias y próximo paso

Los IDs siguientes corresponden al backlog preliminar de la auditoría; no constituyen tarjetas creadas ni cambios implementados.

1. **BE-S2-02 — Validación aislada y controles transversales:** siguiente tarea recomendada. Preparar PostgreSQL desechable, comprobar activación del usuario, contexto/permisos y protocolo de concurrencia. Depende de este documento y de P07/P08 para los contratos afectados; no necesita esperar a resolver todos los detalles de PDF o correo.
2. **BE-S2-03 — Configuración clínica local versionada:** requiere P04, reglas técnicas de muestras y aprobación local. Sin ella no puede cumplirse el snapshot clínico de DT-14 ni la declaración sin muestra de DT-15.
3. **BE-S2-04 — Orden con líneas y requisitos:** requiere P01/P02/P04/P08/P09, base clínica y pruebas aisladas; ampliar creación y composición existentes de manera atómica.
4. **BE-S2-05 — Muestras físicas:** satisface requisitos individuales y trazabilidad; depende de catálogo/composición.
5. **BE-S2-06/07 — Finanzas/políticas:** definir saldo y temporalidad efectiva antes de habilitar inicio con DT-15 o liberación con D02.
6. **BE-S2-08/09 — Resultados/versiones/correcciones:** sustentan validación y finales; acordar P05. El contrato de cierre puede documentarse ahora, pero no comprobar validaciones inexistentes.
7. **BE-S2-10–13 — Destinatarios, informes, entrega y transiciones clínicas:** integrar DT-16/17 y P06; cancelación excepcional depende de identificar versiones, emisiones y destinatarios afectados. No habilitarla como simple cambio de string.
8. **BE-S2-14 — Aceptación/migración integral:** ensayar CT-01–19 y regresión de Sprint 1, sin atribuir datos clínicos ficticios a órdenes históricas.

La creación de cabecera compatible no depende de pagos o muestras físicas. La modalidad con exámenes sí depende de configuración clínica y requisitos. Inicio, cierre y cancelación excepcional se habilitan sólo cuando estén disponibles las evidencias y controles que exige cada decisión.

## 13. Historial

| Versión | Fecha | Cambio |
|---|---|---|
| 1.0 | 10 de octubre de 2026 | BE-S2-01: evidencia real, propuestas de contratos, transiciones DT-14–DT-17, compatibilidad, legado, pruebas y dependencias. Sin implementación ni cambios de BD. |
