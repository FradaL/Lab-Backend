# BE-D02-09 — Excepción comercial exclusiva por preparación

Fecha: 2026-11-10. Seguimiento técnico 1.0 sobre backend `c91dc31`, rama `BE-D02-09`, con cambios locales sin commit.

## Fuente y alcance

Solicitud expresa del usuario de implementar BE-D02-09 en esta conversación, con vínculo a preparación e informe/versión exactos, motivo, permisos, máximo 15 minutos, consumo único y aislamiento por laboratorio. Fuentes funcionales: D02.3 conservado en D02 APROBADO 1.2 y D12 APROBADO 1.1; plan de producto BE-D02-09, PROPUESTO. La implementación no aprueba decisiones nuevas.

Dependencias observadas: permisos BE-D02-04 (`d6d57fa`) y proveedor financiero BE-D02-08 (`b46a24b`, integrado en `c91dc31`). Los textos anteriores que aún describen BE08 como pendiente conservan su corte histórico; esta revisión comprueba código, no despliegue ni ejecución de sus pruebas anteriores.

## Contrato conservado y vínculo exclusivo

Se conservan las rutas existentes de solicitud, autorización, revocación, preparación, descarga y confirmación. No se añade un segundo circuito de entrega.

1. Solicitar la excepción para la emisión exacta requiere `reports.delivery.prepare` y motivo no vacío. `report_id` referencia la emisión inmutable, no una selección de «última versión».
2. Autorizar requiere `reports.delivery_exceptions.authorize`, revisión 1 y evidencia/política vigentes. El servidor fija `authorized_at` y `expires_at = authorized_at + 15 minutos`; el cliente no controla estas fechas. La respuesta incluye `authorization.expires_at` y `authorization.preparation_id`, inicialmente null.
3. La primera preparación con ese `exception_authorization_id` guarda el vínculo exclusivo de forma atómica junto con su auditoría. Su vencimiento es el de la autorización, aunque se prepare más tarde. El vínculo no puede cambiar ni borrarse.
4. Una preparación nueva con la misma autorización devuelve 409 `report_delivery_exception_bound`. Repetir la misma clave y contenido recupera el registro original, sin extender su vigencia. Cambiar contenido con esa clave conserva el conflicto idempotente existente.
5. Descargar y confirmar revalidan plazo, vínculo, estado y evidencia. El límite es exclusivo: `ahora >= expires_at` ya está vencido. Revocar bloquea operaciones posteriores; confirmar consume la autorización en la misma transacción que la entrega y auditoría. El reintento idempotente de una entrega ya confirmada recupera su comprobante, sin otra entrega.

El historial de excepciones incorpora `authorization`, con plazo y preparación vinculada. Una autorización vencida sigue registrada como `authorized` con fecha vencida, sin elegibilidad; no se inventa un estado de dominio adicional. La revocación explícita existente permite cerrar una autorización no consumida antes de solicitar una nueva excepción; no hay renovación automática.

## Persistencia e historia

Migración aditiva `2026_11_10_000001_bind_delivery_exceptions_to_preparations.php`:

- Añade `expires_at` a autorizaciones, nullable únicamente para conservar registros anteriores sin inventar vigencia ni modificar eventos inmutables.
- Crea `report_delivery_exception_bindings`, con una autorización y una preparación únicas, claves compuestas por laboratorio/orden/emisión y protección contra edición/borrado. La tabla aditiva evita imponer unicidad retroactiva sobre preparaciones históricas que podían compartir autorización.
- Exige vencimiento de autorizaciones nuevas y máximo de 15 minutos; protege preparaciones nuevas y descargas con plazo máximo de 15 minutos, y entregas con la autorización exacta vigente. PostgreSQL verifica además su reloj real (`clock_timestamp()`), de modo que una fecha retroactiva no permite usar una autorización vencida.
- Un trigger diferido exige el vínculo al confirmar la transacción de preparación; la unicidad impide dos vínculos concurrentes, incluso mediante SQL directo.

Las autorizaciones anteriores sin plazo verificable y las preparaciones excepcionales sin vínculo se conservan, pero no habilitan nuevas descargas/entregas. El CHECK histórico de preparaciones de hasta 30 minutos se conserva para no revalidar ni modificar filas archivadas; el trigger nuevo exige un máximo de 15 minutos en las nuevas preparaciones y descargas. No se hace backfill, limpieza, reasignación ni eliminación. La migración no se ha aplicado a la base habitual. El rollback exige una estrategia explícita de restauración, como los antecedentes de entrega; no borra historia.

## Límites clínicos y financieros

La excepción no registra cobros/crédito, no cambia total, deuda, revisión financiera, política de orden, resoluciones clínicas ni PDF. Conserva permisos de preparar y confirmar independientes; un empleado puede realizar ambas acciones si posee ambos permisos y el permiso de autorizar cuando corresponda. Se mantienen la emisión exacta, destinatario/declaración explícitos, descarga controlada, comprobación de integridad del PDF y revalidación financiera existentes.

BE-D02-09 no acredita el cumplimiento integral de D12. BE-D02-10 conserva la integración pendiente de liberación/vigencia clínica y finalidad interna/externa documentada; no se presenta la elegibilidad comercial como liberación clínica. Sin frontend, concesiones a usuarios reales, Trello, commit, push ni despliegue.

## Verificación

Validación final de entrega en Compose `donqer-be-d02-09`, PHP 8.4.26, PostgreSQL 17 desechable y código montado en solo lectura: **57 pruebas / 240 aserciones, cero fallos, errores u omisiones**, en 05:03.750. Incluye el alcance nuevo, migración histórica, reloj/límite exacto, reintentos, revocación/consumo, aislamiento, permisos, ausencia de mutación clínica/financiera, rollback de auditoría, SQL directo y ocho escenarios de concurrencia de entrega.

Se corrigió el fixture de `test_postgresql_rejects_policy_state_without_matching_history`: ahora usa un laboratorio sin política y exige SQLSTATE `23514`, en vez de aceptar un duplicado `23505` y esperar una tabla vacía después de crear una orden con política. La discrepancia estaba presente en `c91dc31`; no se cambió comportamiento funcional. También se adapta `CashAuthorizationConcurrencyTest` al patrón existente de recreación desechable y reinicio de `RefreshDatabaseState`, evitando el rollback automático incompatible con migraciones históricas protegidas (BE08 y BE09). No se debilitan sus aserciones ni las restricciones de producción.

| Grupo final, sin casos compartidos | Casos | Aserciones | Resultado |
| --- | --- | --- | --- |
| Entrega (`ReportDelivery`) | 57 | 240 | Satisfactorio. |
| Caja (`Cash`) | 309 | 1.781 | 4 errores y 2 fallos previos; 303 casos satisfactorios. |
| Política por orden y autorización protegida | 47 | 285 | 2 errores y 1 fallo previos; 44 casos satisfactorios. |

La regresión ampliada **no está completamente satisfactoria**. Los nueve casos problemáticos se reprodujeron sin los cambios de BE09, con el mismo lock y PHP, sobre una copia de `c91dc31` en PostgreSQL aislado. Las ejecuciones interrumpidas o fallidas de diagnóstico no se suman a las cifras finales; tampoco se suman de nuevo los casos repetidos en la comparación de base.

## Incidencias previas reproducidas

No se modifican estas pruebas, cálculos o restricciones financieras ajenas a BE09. Su resolución queda **Pendiente** del equipo backend; este registro no acredita cierre integral de Caja ni habilitación operativa.

| Incidencia | Casos reproducidos | Evidencia y límite |
| --- | --- | --- |
| Fixtures de esquemas históricos incompatibles con la FK de crédito posterior | `CashLedgerMigrationTest::test_activation_preserves_existing_snapshot_order_without_inventing_financial_context`; dos datasets de `CashVoidRefundIntegrationTest::test_each_migration_preserves_existing_evidence_and_the_other_capability`; `CashVoidRefundIntegrationTest::test_corrective_migration_repairs_two_original_migrations_already_applied`; `OrderDeliveryPolicyIntegrityTest::test_migration_preserves_old_order_with_null_and_does_not_assign_current_policy`; dataset `historical` de `test_sql_rejects_replacement_null_and_filling_a_historical_policy`. | Seis errores SQLSTATE `2BP01`: el fixture intenta retirar `laboratory_order_cash_operations` mientras `laboratory_order_credit_events` la referencia. No se ejecuta `CASCADE` ni se cambia el rollback de producción para ocultarlo. |
| Inventario de permisos/rutas desactualizado | `ProtectedDomainAuthorizationTest::test_protected_route_inventory_has_each_exact_catalog_permission_once`. | La lista esperada omite rutas existentes de Caja, crédito, diseño de informes y firma. BE09 no añade rutas. |
| Rechazo SQL esperado de devoluciones no observado | `CashVoidRefundIntegrationTest::test_sql_refund_guard_still_excludes_voided_money`; dataset `amount=20.01` de `RecordOrderRefundIntegrityTest::test_sql_rejects_invalid_refunds_even_without_service_validation`. | Dos fallos de aserción al escribir directamente por SQL: devolución contra cobro anulado y rechazo inmediato de monto superior al excedente. Las pruebas usan transacciones de testing; el resultado no acredita por sí solo persistencia fuera de ese entorno ni fallo del servicio HTTP. Requiere revisar las guardas y su momento de validación frente a EB-001 y las migraciones de crédito. |

Comparación de base, proyecto `donqer-be-d02-09-baseline`: `OrderDeliveryPolicyIntegrityTest.php` y `ProtectedDomainAuthorizationTest.php`, **31 casos / 182 aserciones, los mismos 2 errores y 1 fallo**. Proyecto `donqer-be-d02-09-baseline-cash`: `CashLedgerMigrationTest.php`, `CashVoidRefundIntegrationTest.php` y `RecordOrderRefundIntegrityTest.php`, **27 casos / 46 aserciones, los mismos 4 errores y 2 fallos**. La copia se obtuvo con `git archive c91dc31 laravel`; código de base y dependencias montados en solo lectura, sin checkout ni cambio de rama del usuario.

## Reproducción de la validación

Se usa `deploy/testing/compose.yaml`, proyecto `donqer-be-d02-09`, PHP 8.4.26 y PostgreSQL 17. El `vendor` del host no estaba sincronizado con el lock y faltaba `tecnickcom/tc-lib-pdf`; se instaló el lock completo, sin scripts, en el volumen aislado `donqer-be-d02-09-vendor`, montado en `/var/www/vendor` mediante `/tmp/be09-compose.override.yaml`. No se cambiaron dependencias declaradas ni `vendor` del proyecto. La red de PostgreSQL es privada y su almacenamiento desechable; los comandos de test no usan el entorno operativo.

Para reproducir el mismo volumen desde la raíz del backend:

```bash
docker run --rm --workdir /var/www \
  --mount "type=bind,src=$PWD/laravel,dst=/var/www,readonly" \
  --mount type=volume,src=donqer-be-d02-09-vendor,dst=/var/www/vendor \
  donqer-lab-workspace composer install \
  --no-scripts --prefer-dist --no-interaction --no-progress
```

Contenido del override temporal `/tmp/be09-compose.override.yaml`:

```yaml
services:
  tests:
    volumes:
      - be09-vendor:/var/www/vendor:ro
volumes:
  be09-vendor:
    external: true
    name: donqer-be-d02-09-vendor
```

La validación final se divide en tres grupos sin pruebas compartidas: `ReportDelivery` en `donqer-be-d02-09`; `Cash` en `donqer-be-d02-09-cash`; `OrderDeliveryPolicy` y `ProtectedDomainAuthorizationTest.php` en `donqer-be-d02-09-auth`. Cada proyecto tiene PostgreSQL, red y almacenamiento temporal propios. El siguiente comando permite reproducir juntos los mismos grupos con las dependencias de `composer.lock`:

```bash
docker compose -p donqer-be-d02-09 \
  -f deploy/testing/compose.yaml \
  -f /tmp/be09-compose.override.yaml \
  run --rm tests php vendor/bin/phpunit \
  --do-not-cache-result --stop-on-error \
  tests/Feature/ReportDelivery tests/Feature/Cash \
  tests/Feature/OrderDeliveryPolicy \
  tests/Feature/Authorization/ProtectedDomainAuthorizationTest.php
```

OpenAPI se regenera con `php artisan l5-swagger:generate` dentro del mismo contenedor y se verifica `ReportDeliveryExceptionAuthorization` con `expires_at`/`preparation_id`: generación satisfactoria, 156 rutas documentadas. El artefacto se generó únicamente en el almacenamiento temporal del contenedor y se retiró al terminar, sin modificar un archivo ignorado del entorno habitual. Pint ya verificó los once archivos PHP del alcance; no se atribuye esta comprobación al código ajeno.

Se retiraron al terminar los cinco proyectos Compose temporales y el volumen `donqer-be-d02-09-vendor`. Las bases habituales y el `vendor` local se conservaron. Para repetir la prueba se recrean los recursos con los comandos anteriores.

## Historial

| Fecha | Cambio | Fuente |
| --- | --- | --- |
| 2026-11-10 | Implementar el alcance local BE-D02-09 y documentar contrato, historia, límites, 57 pruebas de entrega satisfactorias y nueve incidencias previas reproducidas de la regresión ampliada. | Solicitud expresa del usuario en esta conversación; agente de implementación. |
