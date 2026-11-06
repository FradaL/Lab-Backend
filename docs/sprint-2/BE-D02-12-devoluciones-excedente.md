# BE-D02-12 — Devoluciones manuales del excedente

Fecha: 2026-11-06. Base backend: `78e670b`; implementación versionada en `7fd3aab`, rama `SaraBackend/Registro-Devoluciones`. Solicitud de esta conversación: desarrollar BE-D02-12, trabajar únicamente en backend/documentación y realizar commits locales sin push. Referencia funcional: EB-001 APROBADO 1.1, D02 APROBADO 1.2 (devoluciones conservadas de 1.1) y D12 APROBADO 1.1. No introduce nuevas aprobaciones funcionales.

## Operación

`POST /api/v1/laboratory-orders/{order}/refunds`, con sesión Sanctum, pipeline SaaS, laboratorio activo y permiso efectivo independiente `payments.refund`. No exige `payments.create` ni `payments.view` para escribir; consultar sigue requiriendo su propia facultad. No hay concesiones nuevas de permisos.

Cuerpo cerrado: `expected_revision`, `amount`, `method`, `reference` y `reason`. Revisión JSON entera positiva; monto como cadena canónica de dos decimales, entre `0.01` y `9999999999.99`, en la moneda de la orden. Métodos `cash`, `card` o `bank_transfer`; referencia opcional en efectivo, obligatoria en los otros dos, de 1–150 caracteres cuando se aporta. Motivo obligatorio de 1–1000 caracteres. Validación de longitud antes de recortar espacios exteriores, sin admitir blancos como motivo/referencia. El medio puede diferir del cobro. Actor, fecha UTC con microsegundos, laboratorio, moneda y revisión resultante proceden del servidor. No se vincula a un cobro específico.

Respuesta 201 con `operation=record_refund`, movimiento `refund`, monto, método, referencia, motivo, actor y fecha; `target_payment_id` y `payment_status` nulos. Se conserva total/cobros originales; el resumen y los movimientos BE06 reflejan devoluciones acumuladas, pagos netos y excedente confirmado, sin efectos adicionales al consultar. Superar excedente produce 422 `cash_refund_exceeds_surplus`, con error de campo `amount` y sin persistencia parcial. Las revisiones obsoletas producen 409 `cash_revision_conflict` antes de evaluar la regla monetaria.

## Idempotencia, concurrencia e integridad

Se reutilizan `CashAuthority`, `OrderFinancialState`, `CashLedgerProjection` y `AuditWriter`. La autoridad relee contexto y facultad efectiva en la transacción. Bloqueos: mutex transaccional PostgreSQL por laboratorio/`record_refund`/hash de clave, orden exclusiva y estado financiero. Se calcula el excedente actual dentro de ese bloqueo usando sumas SQL NUMERIC y centavos exactos; la comparación usa NUMERIC. No se usan floats.

`Idempotency-Key` de 16–100 caracteres `[A-Za-z0-9_.-]`, hash SHA-256 y cuerpo canónico según EB-001, incluyendo motivo normalizado, actor, orden y revisión. Repetición exacta autorizada: mismo cuerpo original y HTTP 201 con `Idempotency-Replayed:true`, aunque el saldo/revisión hayan cambiado; no repite movimiento, revisión ni auditoría. Una clave nueva representa una operación distinta. La identidad está separada por laboratorio y tipo de operación. Un cambio de contenido/actor/orden con la misma clave produce 409 `cash_idempotency_conflict`; se valida pertenencia de la orden antes de recuperar resultados. La revocación del permiso impide el replay.

Movimiento, operación/resultado persistido, revisión `refund_recorded` y auditoría `cash.refund_recorded` se confirman juntos. Auditoría fallida o comprobación diferida fallida revierten todo, permitiendo repetir la identidad original. Las carreras con cobros y cambios efectivos de total usan el mismo bloqueo de orden y revisión esperada. Dos devoluciones no pueden gastar simultáneamente el mismo excedente.

Migración aditiva `2026_11_06_000003_enable_order_cash_refunds.php`: amplía las restricciones BE05, conserva filas existentes y admite exclusivamente cobros y devoluciones. La guarda SQL de inserción verifica moneda/revisión y excedente bajo bloqueo, incluso sin pasar por el servicio. Los acumulados se limitan por tipo de movimiento; se adapta también el control de cobros para no sumar devoluciones como dinero recibido. Las restricciones diferidas vinculan tipo de operación, movimiento, revisión, cuerpo persistido y auditoría. Se protege la auditoría de devoluciones contra UPDATE/DELETE, además de la inmutabilidad existente del libro, operaciones y revisiones.

`down()` restaura las guardas de cobro únicamente si no existen movimientos, operaciones o revisiones de devolución; con evidencia de devolución se rechaza sin borrarla. Los fixtures de migraciones anteriores retiran/restauran esta dependencia en orden. No se reescribe la migración histórica BE05.

## Activación y preservación

Migración aplicada solo en PostgreSQL temporal de pruebas. Antes de aplicarla a otro entorno, revisar motor, migraciones, restricciones y cantidades de estados/movimientos mediante metadatos/agregados, y usar el proceso de despliegue del entorno. No se ejecutan migraciones o seeders sobre la base habitual, backfill, eliminación ni recreación de datos compartidos.

Órdenes históricas sin snapshot: 409 `cash_context_unavailable`. Un contexto nuevo ausente/inconsistente: 503 `cash_evidence_unavailable`, sin inicializarlo al devolver ni presumir saldos cero. Moneda y total permanecen sujetos a las protecciones de BE05.

## Verificación

Batería final en PostgreSQL 17 temporal y PHP 8.4.26, con `TestDatabaseGuard`. Conjuntos disjuntos; se cuentan únicamente sus ejecuciones finales, sin sumar diagnósticos o repeticiones previas:

| Proyecto / alcance | Casos | Aserciones | Resultado |
| --- | --- | --- | --- |
| `be-d02-12-check`: Caja, política por orden y composición | 320 | 1.884 | 320 satisfactorios, sin fallos, errores ni omisiones. |
| `be-d02-12-regression`: API de órdenes, autorización, precios, entrega y economía | 506 | 3.373 | 505 satisfactorios y 1 omisión previa; sin fallos ni errores. |
| Total único | 826 | 5.257 | 825 satisfactorios y 1 omisión previa. |

Incluye los 90 casos nuevos de BE12. La única omisión corresponde a `LaboratoryOrderEconomicRecalculationTest::test_invalid_in_memory_representable_intent_rolls_back_on_sqlite`: PostgreSQL impide el estado inválido antes de que llegue al calculador. No se omiten las pruebas nuevas. Las dos ejecuciones terminaron con código 0.

## Reproducción de la verificación

Desde la raíz de `Lab-Backend`, con la imagen PHP de pruebas del proyecto disponible. Los dos proyectos Compose usan redes internas y bases PostgreSQL temporales diferentes; sus conjuntos de pruebas son disjuntos.

```bash
docker compose -p be-d02-12-check -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --stop-on-error --stop-on-failure tests/Feature/Cash tests/Feature/OrderDeliveryPolicy tests/Feature/OrderComposition
docker compose -p be-d02-12-regression -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --stop-on-error --stop-on-failure tests/Feature/Api/V1/LaboratoryOrders tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php tests/Feature/ReportDelivery/ReportDeliveryTest.php tests/Feature/ReportDelivery/ReportDeliveryConcurrencyTest.php tests/Unit/Services/LaboratoryOrders
```

La cobertura nueva incluye devolución parcial/total/mínima/máxima, total cero, ausencia de excedente, validación estricta antes de normalizar, referencia/motivo, identidad canónica y respuesta perdida, replay tras cambios posteriores, permisos independientes y revocados, aislamiento de órdenes/laboratorios, rollback por auditoría inmediata/diferida, guardas SQL e inmutabilidad, preservación/reversión de migración y OpenAPI. Seis carreras nuevas usan conexiones PostgreSQL independientes: reintento idéntico, contenido distinto, claves diferentes, misma clave en órdenes diferentes, cobro/devolución y cambio efectivo de total/devolución.

Se actualizan los contadores globales de pruebas a 197 operaciones OpenAPI, 205 rutas en el bootstrap de pruebas y 171 rutas API con middleware `can:`. No se cambian contratos previos para resolver estos inventarios. Pint aplicado a los archivos PHP modificados y `git diff --check` satisfactorios. Los enlaces locales nuevos se comprueban en la sincronización documental.

## Límites y dependencias

BE11 conserva pendiente su escritor de anulaciones y la adaptación de las guardas SQL para ese tercer tipo de movimiento. No se acredita una carrera contra un escritor BE11 inexistente; BE12 usa el bloqueo común que deberá conservar BE11. La proyección BE06 ya admite anulación mediante fixtures, sin equivaler a su persistencia real. Crédito sigue en BE07. BE08/09/10 mantienen integración del proveedor financiero y revalidación de entrega; registrar una devolución no demuestra protección de consumidores aún no integrados ni cumplimiento integral D02/D12.

Sin frontend, transferencias bancarias, monedero, traspaso entre órdenes, conciliación, modificación de Trello, push ni despliegue. Swagger se genera en almacenamiento efímero durante las pruebas; no se afirma actualizar el documento servido por el entorno habitual.

## Historial

| Fecha | Cambio | Fuente |
| --- | --- | --- |
| 2026-11-06 | Implementar registro manual de devoluciones, excedente protegido, idempotencia, auditoría y revisión; ampliar guardas SQL y contratos, con pruebas y preservación histórica. | Solicitud explícita de BE-D02-12 y autorización de commits locales en esta conversación. |

## Integración posterior BE11/BE12 — 2026-11-06

[Resolución de conflictos, compatibilidad SQL y pruebas conjuntas](BE-D02-11-12-integracion-migraciones.md), entre `f75c844` y `0890873`, más cambios locales. Conserva ambas operaciones y evidencia; añade migración correctiva para instalaciones previas. No aplica cambios a la base habitual ni acredita entrega de resultados. Las referencias anteriores a la otra tarea pendiente conservan su corte histórico.
