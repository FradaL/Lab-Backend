# BE-D02-11 — Anulación trazable de cobros

Fecha: 2026-11-06. Base backend `105d1f1` (incluye `d6d57fa` y `78e670b`) más cambios locales de esta tarea, sin commit. Fuente: Daniel Lara entrega la tarea original y solicita comenzar sin exceder su alcance. Contrato EB-001 APROBADO 1.1, P02/P02-C y D02 1.2 / D12 1.1 conservados. El plan global mantiene PROPUESTO.

## Alcance implementado

`POST /api/v1/laboratory-orders/{order}/payments/{payment}/void` exige sesión, contexto SaaS, `payments.void` efectivo, `Idempotency-Key`, `expected_revision` y `reason` obligatorio (1–1000 caracteres, no solo espacios). Rechaza otros campos; monto, moneda, responsable y fecha provienen del servidor/cobro original. No se concede ningún permiso.

`VoidOrderPayment` relee autoridad y pertenencia al laboratorio/orden, serializa la clave y bloquea la orden y estado financiero. Registra un movimiento `payment_void` por el importe completo con vínculo al cobro, una operación idempotente, revisión `payment_voided` y auditoría `cash.payment_voided`, atómicamente. No edita ni elimina el cobro. Un replay autorizado conserva HTTP 201 y cuerpo original, sin nuevo efecto ni revisión. Una clave reutilizada con otro contenido, actor o destino genera conflicto; otra operación contra un cobro ya anulado devuelve `cash_payment_already_voided` si la revisión está vigente. Orden/cobro ajenos o inexistentes devuelven 404 neutral.

El neto vigente se obtiene con la proyección decimal de BE06 dentro del bloqueo. Si quitar el cobro lo deja negativo, devuelve 409 `cash_void_negative_net` y conserva todos los movimientos y la revisión. Devoluciones previas no bloquean por sí solas. No hay ajustes, reversión de devoluciones ni cobro compensatorio automático. BE06 excluye el cobro anulado una sola vez y muestra ambos movimientos, con estado derivado `voided` para el original. BE05 permite registrar después un cobro correcto; su límite acumulado excluye los movimientos de anulación para no contarlos como dinero recibido.

## Persistencia y activación

Migración adicional `2026_11_06_000003_enable_order_payment_voids.php`: admite únicamente cobros/anulaciones, amplía operaciones y causas de revisión; exige forma por tipo, vínculo compuesto laboratorio/orden/cobro, un índice único parcial de anulación por cobro, coincidencia de importe/moneda y bloqueo SQL del neto negativo. Actualiza la concordancia diferida entre movimiento/operación/revisión/auditoría y protege también la auditoría de anulación contra edición/borrado. Conserva las funciones/garantías anteriores y restaura su definición al revertir si no hay evidencia de anulación; con evidencia el rollback se rechaza.

Debe aplicarse esta migración al activar BE11 en otro entorno, después de la base BE05. No se aplica a la base habitual durante esta tarea. No hay backfill ni inicialización implícita de órdenes históricas. Las devoluciones reales siguen sin escritor/persistencia habilitados: corresponden a BE12.

## Verificación

Regresión final en PostgreSQL 17 temporal con `TestDatabaseGuard`, PHP 8.4.26: **684 casos / 4.393 aserciones; 683 satisfactorios y 1 omisión previa exclusiva de SQLite; cero fallos/errores**. Incluye los 58 casos nuevos de BE11 (cuatro carreras); no se suman la batería específica inicial de 56 casos/300 aserciones ni las ejecuciones preliminares fallidas/interrumpidas. La omisión existente corresponde a `LaboratoryOrderEconomicRecalculationTest::test_invalid_in_memory_representable_intent_rolls_back_on_sqlite`; ninguna prueba BE11 está omitida.

Pint satisfactorio para los nueve archivos de implementación/pruebas específicas; `git diff --check` satisfactorio. OpenAPI 3.1 generado con **197 operaciones**, 205 rutas y 171 rutas API con middleware `can`, conforme a los inventarios comprobados en regresión. Artefacto `/tmp/be-d02-11-openapi/api-docs.json`; 1.076 referencias internas verificadas con un recorrido local y contrato de anulación comprobado por PHPUnit. No se ejecuta validador externo completo de OpenAPI: no está instalado en el host. El Swagger habitual no se modifica.

Comando de regresión desde la raíz backend:

```bash
docker compose -p be-d02-11-testing -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/Cash tests/Feature/Api/V1/LaboratoryOrders tests/Feature/Api/V1/Auth/CurrentAuthorizationTest.php tests/Feature/Api/V1/Pricing/EffectivePriceListResolverTest.php tests/Unit/Services/LaboratoryOrders
```

Pruebas específicas: conservación del original y proyección de saldo, replay después de cobro correcto, claves incompatibles, doble anulación, permisos/revocación, tenant/orden/destino, validación, rollback por auditoría, inmutabilidad, rechazo SQL, rollback/activación de migración y contrato OpenAPI. Cuatro carreras de anulaciones simultáneas/total usan conexiones PostgreSQL independientes.

Para devoluciones previas se utiliza exclusivamente una tabla temporal de sesión con datos sintéticos: se comprueba rechazo con neto negativo y aceptación con neto cero/positivo sin relajar restricciones de la tabla real. Esa prueba no acredita el escritor de BE12 ni una secuencia real cobro-devolución-anulación persistida en producción.

## Límites

Sin frontend, devoluciones manuales/automáticas, crédito, procedimientos especiales de corrección ni proveedor/integración de entrega BE08/09/10. No se acredita cumplimiento integral de D02/D12, protección de consumidores aún no integrados ni despliegue. Sin cambios en Trello, commits o push. La imposibilidad inicial de Docker se resolvió al habilitar acceso al socket; todas las migraciones de pruebas corresponden a PostgreSQL temporal aislado. Contenedor PostgreSQL y red de testing retirados al finalizar.

## Integración posterior BE11/BE12 — 2026-11-06

[Resolución de conflictos, compatibilidad SQL y pruebas conjuntas](BE-D02-11-12-integracion-migraciones.md), entre `f75c844` y `0890873`, más cambios locales. Conserva ambas operaciones y evidencia; añade migración correctiva para instalaciones previas. No aplica cambios a la base habitual ni acredita entrega de resultados. Las referencias anteriores a la otra tarea pendiente conservan su corte histórico.
