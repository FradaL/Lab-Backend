# Integración de BE-D02-11 y BE-D02-12 al resolver conflictos

Fecha: 2026-11-06. Fuente: Daniel Lara solicita resolver los conflictos sin romper ni perder cambios. Rama `BE-D02-11`, HEAD `f75c844`, integración de `develop` / MERGE_HEAD `0890873`, más cambios locales de resolución. No constituye nueva aprobación funcional. Se conservan EB-001 1.1, D02 1.2 y D12 1.1.

## Conflictos resueltos

Se conservan los dos apartados históricos del README, ambas rutas financieras y sus permisos (`payments.void` y `payments.refund`), y la preservación de entrada cruda para cobros, anulaciones y devoluciones en el middleware. Las variantes de la consulta de acumulados en `RecordOrderPayment` eran equivalentes: se conserva el filtro `type='payment'` para no sumar otros movimientos como dinero recibido.

Además de los cuatro archivos marcados por Git, las migraciones independientes reemplazaban las mismas funciones y restricciones SQL. La de anulación podía retirar el soporte de devolución; la de devolución asumía nombres de restricciones anteriores y no admitía anulaciones existentes. No bastaba con retirar los marcadores.

## Compatibilidad y preservación

Las dos migraciones `000003` conservan sus nombres e identificadores. Delegan el SQL común a `database/migrations/support/CashLedgerSchema.php`: cada una habilita su operación conservando la que ya exista. Las restricciones se identifican por tabla/definición, sin asumir que los nombres previos sobreviven a la otra migración. Las guardas conservan aislamiento, importe/moneda/vínculo de la anulación, unicidad, límite de excedente, bloqueo de neto negativo, inmutabilidad y concordancia de movimiento/operación/revisión/auditoría.

Nueva migración `2026_11_06_000004_reconcile_cash_voids_and_refunds.php`: aplica el esquema compatible también cuando las migraciones originales ya constan como ejecutadas. No crea, edita ni elimina movimientos o evidencia. Su rollback no retira protecciones: las migraciones precedentes mantienen las capacidades y sus propios `down()` comprueban evidencia antes de retirar una de ellas. Los `down()` de BE11/BE12 preservan la capacidad restante y rechazan retirar soporte si existen movimientos, operaciones o revisiones propios.

La combinación no introduce reglas nuevas: el contrato aprobado ya establece anulación completa, devolución contra excedente y bloqueo cuando el neto resultante sería negativo. No hay devoluciones inversas, ajustes ni compensaciones automáticas.

## Verificación

**Regresión final: 807 casos / 5.162 aserciones; 806 satisfactorios y 1 omisión previa exclusiva de SQLite; cero fallos/errores.** PostgreSQL 17 temporal con `TestDatabaseGuard`, PHP 8.4.26. Incluye las pruebas de ambas tareas, cuatro casos de integración histórica/SQL y la nueva carrera entre anulación y devolución. No se suman las ejecuciones preliminares de 312 casos/1.762 aserciones y 3 casos/19 aserciones. La omisión corresponde al caso previo `LaboratoryOrderEconomicRecalculationTest::test_invalid_in_memory_representable_intent_rolls_back_on_sqlite`; las pruebas de integración están ejecutadas.

Pint satisfactorio sobre los diez archivos de resolución/pruebas. `git diff --check` y revisión del índice satisfactorios, sin archivos no fusionados ni marcadores de conflicto. OpenAPI 3.1 generado en `/tmp/cash-merge-openapi/api-docs.json`: ambas rutas presentes, 198 operaciones y 1.079 referencias internas verificadas. Los inventarios de regresión comprueban 206 rutas y 172 rutas API con middleware `can`. No se modifica el Swagger habitual ni se ejecuta validador externo completo.

Fixtures SQL versionados en `tests/Fixtures/Cash` reproducen las definiciones originales de BE11/BE12 anteriores a la integración, exclusivamente en PostgreSQL temporal. Las pruebas comparan filas completas de movimientos, operaciones, estado, revisiones y auditoría antes/después de las migraciones. Incluyen ambos órdenes de instalación con evidencia previa, reversión de una capacidad preservando la otra, reparación de los esquemas originales ya aplicados y secuencias reales de cobro/devolución/anulación. La concurrencia añade anulación frente a devolución sobre la misma orden/revisión.

Comando de regresión desde la raíz backend:

```bash
docker compose -p cash-merge-testing -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --stop-on-error --stop-on-failure tests/Feature/Cash tests/Feature/Api/V1/LaboratoryOrders tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php tests/Unit/Services/LaboratoryOrders tests/Feature/OrderDeliveryPolicy
```

## Activación y límites

La migración correctiva debe ejecutarse al adoptar la integración; editar archivos ya ejecutados no vuelve a ejecutar automáticamente sus `up()`. No se aplica ninguna migración a la base habitual ni se modifican datos reales durante esta resolución. No hay nuevas facultades, grants, frontend, proveedor de entrega, cierre de Trello, commit, push ni despliegue. La fusión queda preparada en el índice; su commit corresponde al usuario. Las pruebas no acreditan integración de entrega BE08/09/10 ni cumplimiento integral de D02/D12. Los informes anteriores conservan su corte histórico; sus menciones a integración BE11/BE12 pendiente no describen esta combinación posterior.
