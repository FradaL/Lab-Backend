# BE-D02-06 — Resumen financiero y movimientos

Fecha: 2026-11-06. Base backend `941acf3` con BE-D02-05 local y cambios locales BE06, sin commit. Fuente: Daniel Lara solicita «excelente, ahora hagamos BE-D02-06». [Tarjeta](https://trello.com/c/krVGzcFR), observada en Haciendo, sin completar. Se conserva EB-001 APROBADO 1.1 / D02 1.2 / D12 1.1. No se crean aprobaciones funcionales.

## Implementación

- `GET /api/v1/laboratory-orders/{order}/cash-summary`: total vigente, cobros válidos, devoluciones, pagos netos, saldo pendiente, excedente, snapshot de política, revisión y campo de crédito. No confunde total con dinero recibido.
- `GET /api/v1/laboratory-orders/{order}/cash-movements`: historial con responsables, fechas UTC, importes, medio/referencia/motivo, vínculo a cobro original y estado derivado del cobro. Orden por revisión descendente y luego ID descendente; conserva el original anulado en el listado.

Ambos requieren sesión, contexto SaaS y `payments.view` efectivo mediante el pipeline existente y `CashAuthority`. Cobrar y consultar son facultades independientes. Sin grants automáticos. La consulta de órdenes y todas las fuentes de movimientos/revisiones se limitan al laboratorio activo; una orden ajena o inexistente devuelve 404 neutral.

`ReadOrderCash` conserva un bloqueo compartido de la orden durante toda la transacción, incluido el conteo, las filas y la construcción de la respuesta. Los escritores de BE05 y del total toman el bloqueo exclusivo de la misma orden. Así cada GET devuelve una revisión coherente, sin mezclar totales y eventos confirmados en distintos momentos. Dos GET separados pueden devolver revisiones distintas: el consumidor debe refrescar si no coinciden; no se ofrece una lectura conjunta implícita.

`CashLedgerProjection` calcula sumas históricas en SQL NUMERIC. Excluye un cobro si existe su anulación; no resta otra vez el movimiento de anulación. Las devoluciones se restan de cobros válidos para obtener pagos netos; saldo y excedente se calculan sobre el total vigente. Comprueba el rango NUMERIC(18,2) antes de convertir a centavos enteros y formatear cadenas de dos decimales, sin floats. Un acumulado fuera de rango devuelve 409 `cash_accumulator_overflow`; importes inválidos o neto negativo implican 503 `cash_evidence_unavailable`, sin truncar ni inventar un saldo.

Consultar no crea auditoría de movimiento, operaciones, revisiones ni cambios de estado financiero. Se reutiliza la revisión inicial y la actualización por cobro/cambio real de total que BE05 ya integra. Un replay de cobro conserva su resultado y no incrementa revisión; el resumen posterior muestra el estado financiero vigente.

## Contrato y paginación

Solo movimientos acepta `page` entero positivo y `per_page` de 1 a 100; valores por defecto 1/15. Campos desconocidos o tipos inválidos: 422. Resumen no acepta parámetros. `meta` incluye `current_page`, `last_page`, `per_page`, `total` y `financial_revision`. Una página válida fuera del rango devuelve `data: []` conservando la página pedida; se evita calcular offsets desbordados para páginas enormes. Sin movimientos: total 0 y last_page 1.

`cash-summary` usa exactamente los campos de EB-001. Ejemplo ficticio: total 80.00, cobros válidos 100.00, devoluciones 0.00 => neto 100.00, saldo 0.00 y excedente 20.00. Total cero sin movimientos: importes conocidos cero, sin cobro ficticio.

Las órdenes históricas sin snapshot ni estado devuelven resumen HTTP 200 con `available:false`, motivo `cash_context_unavailable`, total/moneda conocidos y saldos/revisión/crédito desconocidos en null; el listado rechaza con 409. Snapshot presente pero estado/revisión ausentes o inconsistentes: 503. No se inicializan datos al leer ni se realiza backfill.

OpenAPI 3.1 documenta las dos rutas, objetos cerrados, disponibilidad conocida/desconocida, tipos y condiciones de cobros/anulaciones/devoluciones. La variante de movimiento usa casos `oneOf` mutuamente exclusivos, equivalentes a las condiciones del contrato, para conservar `additionalProperties:false` con el generador Swagger instalado. Inventario vigente: 196 operaciones documentadas, 204 rutas y 170 rutas API con middleware can. Se actualizan las expectativas de inventario correspondientes a las dos rutas nuevas.

## Verificación

Regresión final: **626 casos / 4.078 aserciones; 625 satisfactorios, 1 omisión previa, cero fallos/errores**. Incluye las 51 pruebas nuevas y las regresiones de BE05, API de órdenes, autorización, precios y cálculo económico; no se suma la ejecución preliminar de los mismos casos. PostgreSQL 17 temporal / PHP 8.4.25, Compose `be-d02-06-testing` y `TestDatabaseGuard`; sin conexiones ni migraciones en bases compartidas.

La única omisión es `LaboratoryOrderEconomicRecalculationTest::test_invalid_in_memory_representable_intent_rolls_back_on_sqlite`, exclusiva de SQLite: PostgreSQL impide ese estado inválido antes de alcanzar el calculador. Ninguna prueba nueva está omitida. Pint y `git diff --check` satisfactorios. OpenAPI 3.1 generado y verificado, con 196 operaciones y esquemas cerrados de movimientos/disponibilidad; artefacto local `/tmp/be-d02-06-openapi/api-docs.json`. No se modifica el directorio habitual de Swagger, con la restricción de escritura ya identificada en BE05. Los contenedores/red temporales se retiran al terminar.

Comando final desde la raíz backend:

```bash
docker compose -p be-d02-06-testing -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/Cash tests/Feature/Api/V1/LaboratoryOrders tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php tests/Unit/Services/LaboratoryOrders
```

Las cuatro carreras nuevas verifican una espera real en `pg_stat_activity`: lectura después de cobro, lectura después de cambio de total, cobro esperando al resumen y cobro esperando al listado entre su conteo y lectura de filas. Las últimas dos observan la revisión anterior completa; las primeras dos observan la nueva revisión confirmada.

Los fixtures de anulación/devolución ejecutan la misma proyección SQL sobre una relación sintética de solo lectura (`jsonb_to_recordset`), sin relajar las restricciones de producción de BE05. Prueban cálculo y forma de lectura; no acreditan escritores ni aceptación de secuencias financieras de BE11/12. Se cubren decimales, límites, no duplicación de efectos, campos desconocidos, paginación/vacíos, procedencia, permisos/tenant, sesiones, datos históricos y ausencia de efectos de lectura.

## Límites y activación

BE06 no añade migraciones ni modifica las restricciones de escritura de BE05, que todavía solo aceptan cobros. Para activar en otro entorno se necesita aplicar coordinadamente la base BE05 ya documentada; BE06 no regulariza órdenes anteriores ni aplica migraciones o seeders a la base habitual.

Crédito: el estado actual obliga a que el puntero de autorización sea null y todavía no existe el escritor/tabla BE07. El resumen devuelve `credit:null`; un puntero inesperado provoca 503, no un crédito inventado. BE07 debe integrar la lectura de su autorización activa/suficiencia al añadir esa persistencia. Crédito y excepciones no entran en las sumas de dinero.

BE11/12 deben ampliar las restricciones/vínculos de persistencia y validar sus escrituras antes de habilitar eventos reales de anulación/devolución. La proyección de lectura queda preparada y probada con fixtures. BE08/09/10 siguen siendo responsables del proveedor financiero y de la integración de entrega; esta consulta no habilita entregar resultados ni acredita cumplimiento integral de D02/D12.

Sin frontend, nuevas asignaciones reales, cambios en Trello, commit, push o despliegue. Se mantienen las modificaciones locales de BE05 como dependencia; no se atribuyen a un commit inexistente.
