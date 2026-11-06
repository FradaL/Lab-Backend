# BE-D02-03 — Política conservada al crear la orden

Fecha: 2026-11-06. Rama: `Sara/Guardar-politica-de-pago`. Implementación principal: `1a932b2`. Adaptación de seeders y regresiones: `d8e1cbc`.

## Base y dependencias

Alcance solicitado: guardar política y procedencia en la creación atómica, conservarlas ante cambios de configuración y devolverlas por API sin inferir valores históricos. Fuentes funcionales: `donqer-lab-product/06-especificaciones/plan-implementacion-d02-v1.md`, BE-D02-03; D02 vigente 1.2 (política fija conservada desde 1.0/1.1), D12 1.1 y EB-001/contratos de Caja 1.1. No se modifica ninguna aprobación funcional.

La rama comenzó en `423ae6b`. Se incorporó por fast-forward el `develop` local `970740b`, que integra BE-D02-02 (`d4e7e72`) y BE-D02-04 (`d6d57fa`). No se repite su implementación ni se modifica `develop`.

## Implementación

- Migración aditiva `2026_11_06_000001_preserve_order_delivery_policy.php`: columna JSONB nullable. Las filas anteriores conservan null, sin backfill ni borrado.
- `OrderDeliveryPolicy::capture`: bloqueo compartido de laboratorio y configuración, en ese orden. Comprueba historial/revisión, actor y fecha exacta antes de capturar los seis campos del contrato. La ausencia de selección devuelve 409 `delivery_policy_not_configured`; procedencia inconsistente devuelve 503 `cash_evidence_unavailable`. Ninguno crea una orden parcial.
- `CreateLaboratoryOrder`: captura servidor dentro de la misma transacción de cabecera, auditoría, líneas y descuento. La auditoría de creación conserva el snapshot. No acepta política del cliente ni concede permisos de configuración al operador de Recepción.
- Trigger SQL: exige objeto cerrado con modo/revisión/responsable/fechas vinculados a configuración vigente e historial del mismo laboratorio. Serializa también inserciones SQL con el configurador. Impide cambiar/quitar el snapshot y completar posteriormente uno histórico nulo; permite editar los demás campos por sus circuitos existentes.
- Detalle, creación y listado devuelven `delivery_policy`. El listado selecciona explícitamente la columna nueva; conserva ordenamiento y filtros. OpenAPI refleja objeto nullable y errores de creación.
- Factories generan selección y procedencia sintéticas explícitas, sin permisos nuevos. Los seeders identificados por `DemoDataset` eligen política antes de crear órdenes nuevas; no reescriben órdenes ni configuraciones previas. Los escenarios dedicados a nuevos laboratorios eligen su propia política con su actor autorizado.

## Discrepancia temporal de la dependencia

BE-D02-02 guardaba microsegundos en `report_delivery_policies.updated_at`, mientras Query Builder serializaba `report_delivery_policy_changes.changed_at` sin ellos. Se conserva ahora precisión UTC al escribir el historial. No se reescribe historia anterior. Una configuración preexistente con procedencia inconsistente no se regulariza por inferencia: el caso de uso devuelve 503 y requiere revisión del entorno. Este ajuste técnico no cambia las reglas de selección aprobadas.

## Límites de integración

Este alcance entrega la política original. No implementa movimientos, saldos, estado/revisiones financieras de BE-D02-05/06 ni su inicialización; no devuelve un `financial_revision:1` ficticio. Esa parte del contrato común EB-001 §5.4 requiere integración coordinada con el estado financiero. No se declara cumplimiento integral de ese contrato de Caja por terminar la captura de política.

BE-D02-08/09/10 deben integrar el snapshot en evaluador, evidencia, preparación, descarga, confirmación y triggers de entrega. Los consumidores actuales de entrega siguen usando configuración general hasta esa integración; esta entrega no acredita que todos los consumidores ya respeten la política fija. Los controles D12 existentes se conservan y se verifican por regresión, sin habilitar entrega financiera nueva.

## Verificación y datos

Pruebas ejecutadas únicamente en el proyecto Compose `be-d02-03-testing`, PostgreSQL 17.11 aislado con volumen tmpfs y configuración de testing verificada por `TestDatabaseGuard`. No se aplicaron migraciones ni seeders a Laradock, bases de desarrollo compartidas o producción. Resultados finales, sin sumar ejecuciones previas:

| Batería final | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Snapshot, composición, API de órdenes, entrega D12 y economía unitaria | 499 | 3.447 | Cero fallos/errores; 1 omisión. |
| Seeders de órdenes y entrega | 15 | 919 | Satisfactorias, cero fallos/errores/omisiones. |
| Total de casos únicos | 514 | 4.366 | 513 satisfactorias y 1 omisión previa. |

La única omisión es `LaboratoryOrderEconomicRecalculationTest::test_invalid_in_memory_representable_intent_rolls_back_on_sqlite`: exclusivo de SQLite; PostgreSQL impide el estado inválido antes de llegar al calculador. Las 28 pruebas nuevas, incluidas las cinco carreras reales BE-D02-03, están incluidas en el total, no se suman otra vez.

Comandos ejecutados desde la raíz backend:

```bash
docker compose -p be-d02-03-debug-testing -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/OrderDeliveryPolicy tests/Feature/OrderComposition tests/Feature/Api/V1/LaboratoryOrders tests/Feature/ReportDelivery/ReportDeliveryTest.php tests/Feature/ReportDelivery/ReportDeliveryConcurrencyTest.php tests/Unit/Services/LaboratoryOrders
docker compose -p be-d02-03-testing -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/ReportDelivery/ReportDeliverySeederTest.php tests/Feature/LaboratoryOrderSeederTest.php
```

Pint aplicado a los PHP modificados y `git diff --check` satisfactorio. OpenAPI local regenerado: 193 operaciones, campo de política y respuestas verificados; Swagger UI responde HTTP 200. Esta comprobación HTTP no se presenta como revisión visual ni prueba del frontend.

Los casos nuevos cubren los tres modos, selección inicial coincidente con el conservador, procedencia auditada, bloqueo sin configuración, cambio posterior/listado/detalle, inyección de campos, permisos, tenant, rollback, preservación real de filas anteriores a la migración, restricciones SQL y cinco carreras entre conexiones independientes.

Las pruebas históricas de entrega utilizan `HistoricalOrderFixture`, exclusivo de testing: verifica la identidad de la base aislada y emula filas previas bajo bloqueo exclusivo, restaurando el trigger antes de confirmar. No cambia código ni garantías de producción. Las pruebas con escrituras confirmadas recrean la base aislada como los escenarios de informes existentes; evitan ejecutar down sobre evidencia preservada.

## Mantenimiento de pruebas y contraste con la base

Se ejecutó la misma regresión previa sobre una copia temporal e inalterada de `970740b`, con el vendor instalado y una segunda base PostgreSQL aislada: **486 pruebas / 4.158 aserciones, 4 errores, 7 fallos y 1 omisión**. Los cuatro errores correspondían a fixtures de migraciones históricas que omitían la dependencia de firmas autorizadas; los siete fallos esperaban 186 operaciones OpenAPI frente a las 193 existentes en esa base. Se actualizaron esos fixtures y conteos, sin agregar rutas ni modificar comportamiento clínico.

Se adaptaron las preparaciones de órdenes nuevas a selección explícita y los contratos de respuesta al campo nuevo. El seeder principal construye una selección sintética identificada por `DemoDataset`, con actor/membresía registrados, historial y auditoría; no concede `reports.delivery_policy.manage` al administrador ni cambia roles. Los laboratorios dedicados usan su actor ya facultado y su selección explícita. El laboratorio libre de informes elige `unrestricted` antes de crear sus órdenes; el seeder de entrega conserva esa revisión sin reescribir snapshots ni suponer una configuración ausente.

Los resultados fallidos/intermedios se conservan como diagnóstico y no se presentan ni se suman como pruebas satisfactorias finales.

## Aplicación en otro entorno

1. Verificar metadatos, procedencia y conteos agregados; revisar configuración e historial existentes sin copiar información clínica ni secretos.
2. Coordinar BE-D02-02 y este código; aplicar la migración aditiva por el proceso del entorno. No ejecutar `migrate:fresh` sobre una base existente.
3. Confirmar que nuevas órdenes requieren selección y que las anteriores siguen con null. No reparar discrepancias de historia ni asignar políticas por suposición.
4. El down rechaza eliminar la columna si hay snapshots. Para revertir un despliegue con evidencia, conservar la estructura y preparar restauración explícita; no borrar historia.
5. Integrar estado financiero y consumidores BE-D02-08/09/10 antes de habilitar el recorrido financiero completo.

Sin frontend, push, despliegue ni cambios de estado en Trello.
