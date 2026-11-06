# BE-D02-05 — Registro de cobros idempotentes

Fecha: 2026-11-06. Base backend: `941acf3` más cambios locales sin commit. Solicitud de Daniel Lara: «Ok, empecemos entonces a implementar nuestra tarjeta entonces». Se aplica EB-001 APROBADO 1.1, D02 1.2 y D12 1.1; no se crean aprobaciones funcionales nuevas.

## Implementación y contrato

`POST /api/v1/laboratory-orders/{order}/payments` exige sesión Sanctum, pipeline SaaS, laboratorio activo y `payments.create` efectivo. El servicio ejecuta la operación mediante `CashAuthority`, con relectura de permisos y hasta tres intentos transaccionales ante deadlock. No hay concesiones operativas automáticas.

Entrada cerrada: `expected_revision` entero JSON positivo, `amount` cadena canónica positiva con dos decimales hasta `9999999999.99`, `method` (`cash`, `card`, `bank_transfer`) y referencia opcional en efectivo/obligatoria en tarjeta y transferencia. Se valida longitud antes de recortar espacios exteriores. No se aceptan moneda, actor, tenant, fechas, saldos ni política enviados por el cliente. No se usan floats ni se rechazan cobros por superar el saldo.

`Idempotency-Key` opaca de 16–100 caracteres, SHA-256 persistido y ámbito laboratorio/operación. Identidad canónica conforme a EB-001: incluye actor, orden, revisión y campos normalizados; campos no aplicables nulos. Mutex PostgreSQL transaccional por laboratorio/operación/hash antes del bloqueo exclusivo de orden y estado. Reintento exacto y autorizado: HTTP 201, cuerpo original y `Idempotency-Replayed:true`; mismo identificador con contenido, actor u orden distintos: 409 neutral. El replay precede a la revisión actual y no duplica movimiento, revisión ni auditoría. Una nueva clave identifica otra operación aunque coincidan importe o referencia.

Persistencia aditiva: estado, movimientos, operaciones/resultados y revisiones. Libro, revisiones, operaciones y auditoría de cobro protegidos contra UPDATE/DELETE en SQL. FK compuestas preservan laboratorio y orden; el actor conserva pertenencia verificable. Guardas de inserción bloquean la orden y validan moneda, revisión y límite del acumulado con SQL NUMERIC. Restricciones diferidas verifican al commit la concordancia de estado, total, secuencia, cobro, respuesta persistida y auditoría `cash.payment_recorded`. Un fallo revierte todo y permite repetir la misma clave.

La base común incluye columnas de destino/motivo y puntero de crédito, pero las restricciones actuales admiten exclusivamente cobros y puntero nulo. Las migraciones de BE07/11/12 deberán ampliar esas restricciones y vínculos al implementar sus operaciones; esta entrega no permite insertarlas parcialmente por SQL.

## Integración de creación y cambios de total

`CreateLaboratoryOrder` inicializa revisión 1 después de componer líneas/descuentos y obtener el total definitivo, dentro de la transacción existente. Las restricciones diferidas impiden confirmar una orden nueva sin ese estado. No hay revisiones financieras intermedias de composición ni cobros ficticios con total cero. Creación, detalle y listado exponen `financial_revision`; el listado carga la relación en lote.

El recalculador y sus cuatro escritores de líneas/descuentos conservan el bloqueo de orden y registran `order_total_changed` solo si el total cambia. La revisión conserva `total_snapshot`, evidencia técnica para comprobar en SQL que el total vigente y la revisión concuerdan. Las llamadas internas históricas con actor nulo mantienen esa ausencia explícita; no se atribuyen a otro usuario. Las escrituras HTTP conservan su actor autenticado. Una modificación directa de total sin revisión concordante es rechazada al confirmar; cambiar la moneda con contexto financiero también se rechaza.

## Preservación y activación

Sin backfill, borrado ni recreación de datos existentes. Las tablas nuevas comienzan vacías. Las órdenes anteriores sin snapshot rechazan cobros con 409 `cash_context_unavailable`; las que ya tenían snapshot de BE03 pero no estado financiero rechazan con 503 `cash_evidence_unavailable`. No se inicializan automáticamente al cobrar. `financial_revision` es nulo cuando falta estado. Se requiere una estrategia expresamente acordada para regularizar órdenes previas; no forma parte de BE05.

El rollback de esquema se rechaza si existe estado financiero. Aplicación fuera de testing: verificar PostgreSQL 17, colisiones de nombres, migraciones y conteos de órdenes con/sin snapshot/estado, con metadatos y agregados sin información personal; aplicar la migración aditiva por el proceso del entorno. No usar `migrate:fresh` para actualizar una base existente ni ejecutar seeders sobre ella por esta entrega.

Factories: datos sintéticos con política/procedencia y membresía de su creador, sin permisos nuevos, inicialización dentro de transacción. Fixtures históricos de pruebas usan exclusivamente la base aislada verificada y restauran los triggers antes de terminar. Los fixtures que retroceden migraciones también retiran/restauran la nueva dependencia financiera.

## Verificación

Verificación final en Compose `be-d02-05-check` y `be-d02-05-review`, PostgreSQL 17 temporal y PHP 8.4.25, con `TestDatabaseGuard`. No se aplican migraciones ni seeders a bases compartidas. Resultados finales de conjuntos disjuntos, sin sumar ejecuciones anteriores:

| Batería | Casos | Aserciones | Resultado |
| --- | --- | --- | --- |
| Caja, política, composición, API de órdenes, autorización, precios, entrega y economía | 685 | 4.382 | 684 satisfactorios, 1 omisión previa; cero fallos/errores. |
| Seeders de órdenes y entrega | 15 | 919 | 15 satisfactorios; cero fallos/errores/omisiones. |
| Total único | 700 | 5.301 | 699 satisfactorios, 1 omisión previa. |

La omisión es `LaboratoryOrderEconomicRecalculationTest::test_invalid_in_memory_representable_intent_rolls_back_on_sqlite`, específica de SQLite: PostgreSQL rechaza ese estado inválido antes de llegar al calculador. No se omiten las pruebas nuevas. Las cinco carreras nuevas entre conexiones PostgreSQL cubren misma clave/contenido, contenido distinto, claves diferentes con revisión compartida, misma clave en órdenes distintas y cambio de total concurrente con cobro. También se verifican importes mínimos/máximos, tipos JSON, referencias, canonización, replay después de cambios, permisos/tenant, fallo de auditoría y rollback, protección SQL, rechazo de revisión fabricada e historia anterior a la activación.

Comando de regresión desde la raíz backend:

```bash
docker compose -p be-d02-05-check -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/Cash tests/Feature/OrderDeliveryPolicy tests/Feature/OrderComposition tests/Feature/Api/V1/LaboratoryOrders tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php tests/Feature/ReportDelivery/ReportDeliveryTest.php tests/Feature/ReportDelivery/ReportDeliveryConcurrencyTest.php tests/Unit/Services/LaboratoryOrders
docker compose -p be-d02-05-review -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/LaboratoryOrderSeederTest.php tests/Feature/ReportDelivery/ReportDeliverySeederTest.php
```

Los fixtures económicos de concurrencia usan ahora el recalculador para preparar totales/revisiones concordantes; los de migración histórica retiran/restauran también la dependencia financiera. Las expectativas de respuestas incorporan `financial_revision`. Inventario vigente: 194 operaciones OpenAPI, 202 rutas y 168 rutas API con middleware `can:`. Se actualizan los contadores correspondientes; la prueba de precios arrastraba inventarios anteriores de 186 operaciones/194 rutas, y un escenario de autorización de órdenes omitía la selección explícita exigida desde BE03. Se corrigen esas preparaciones sin otorgar permisos nuevos ni agregar rutas adicionales.

OpenAPI 3.1 generado y verificado con el endpoint, sesión Sanctum y referencia condicional por medio. Artefacto local: `/tmp/be-d02-05-openapi/api-docs.json`. El directorio habitual `laravel/storage/api-docs` pertenece a `nobody:nogroup` y no permite escritura desde esta sesión; no se cambian sus permisos ni se afirma haber actualizado el Swagger servido por otro entorno. Las pruebas generan su copia dentro del almacenamiento efímero del contenedor aislado.

Pint y `git diff --check` satisfactorios. Los resultados de ejecuciones diagnósticas/interrumpidas se mantienen separados de la batería final; no se suman para inflar cobertura.

## Límites

BE06 conserva resumen/listados/cálculo de saldo y excedente; BE07 crédito; BE11 anulación; BE12 devolución. BE08/09/10 conservan integración de evidencia financiera y entrega. El proveedor de cumplimiento no se sustituye por esta entrega; registrar un cobro no acredita autorización de entrega ni cumplimiento financiero integral. Sin frontend, cambios de asignaciones reales, Trello, commit, push ni despliegue.
