# BE-D02 — Recuperación segura de intento de cobro idempotente

Fecha: 2026-11-10. Estado: implementación local en `christian-be-d02-cash-attempt-recovery`, pendiente de integrar con `origin/develop` antes de crear PR.

## Motivación y contrato

Un timeout del POST de cobro no prueba que el cobro haya fallado. Se añade `GET /api/v1/laboratory-orders/{order}/payment-attempts/{idempotencyKey}` para recuperar evidencia persistida sin introducir otro cobro. No altera el contrato del POST de BE-D02-05 ni las reglas clínicas de entrega; reutiliza `laboratory_order_cash_operations` y no agrega migraciones.

La clave debe tener 16–100 caracteres `[A-Za-z0-9_.-]`. Se hashea y se busca únicamente entre operaciones `record_payment` del mismo laboratorio, orden y actor. Una coincidencia devuelve HTTP 200 con `status: confirmed`, ID de operación, HTTP 201 original y el snapshot persistido del POST. La ausencia devuelve HTTP 202 con `status: unknown`; es deliberadamente inconclusa y no autoriza una nueva clave.

## Autorización, tenant y efectos

La ruta exige sesión Sanctum, contexto de laboratorio y `payments.view` mediante middleware. `ReadPaymentAttempt` vuelve a comprobar `payments.create` con `CashAuthority`, por lo que una clave idempotente no concede permisos. La orden se resuelve dentro del laboratorio activo antes de consultar evidencia; la condición de búsqueda incluye laboratorio, orden, actor, tipo y hash de clave. No revela una operación de otro actor, orden o laboratorio.

GET sólo lee: no crea movimiento, operación, auditoría ni revisión financiera. El POST existente conserva el bloqueo asesor por clave y el bloqueo exclusivo de orden. Durante un POST aún no confirmado, GET puede devolver `unknown`; no duplica un cobro porque no escribe y ese resultado no se interpreta como rechazo.

## OpenAPI

`CashAttemptRecoveryDocumentation.php` declara el GET, autenticación `sanctumCookie`, cabecera de laboratorio, parámetros de orden y clave, respuestas 200/202/400/401/403/404/422 y esquemas cerrados de respuesta confirmada y desconocida. La generación predeterminada declara OpenAPI **3.1.0**:

```bash
php vendor/bin/openapi --mode hybrid --format json app -o /tmp/donqer-openapi-default.json
```

El artefacto se genera en `/tmp`; no se escribe `storage/api-docs`, que no es accesible en esta sesión. No se fuerza OpenAPI 3.0.0 ni se cambian dependencias globales de Swagger.

## Pruebas y concurrencia

En Codex pasaron la guarda de base aislada (15 pruebas/15 aserciones), Pint de los archivos afectados, generación OpenAPI 3.1.0 y `git diff --check`. La prueba funcional focalizada no puede ejecutarse desde Codex: el único host permitido, `postgres-testing`, vive en la red Docker interna y esta sesión no tiene acceso a `/var/run/docker.sock`.

El desarrollador reportó —no Codex— la ejecución autorizada en PostgreSQL temporal aislado:

```bash
sudo docker compose -p fe-d02-04-recovery-testing -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/Cash
sudo docker compose -p fe-d02-04-recovery-testing -f deploy/testing/compose.yaml down
```

Resultado reportado: PHPUnit 12.5.35 / PHP 8.4.25, 309 pruebas, 1,815 aserciones, cero fallos, 03:08.541. El segundo comando eliminó contenedor y red temporales sin `down -v`. El conjunto incluye pruebas de concurrencia ya existentes de pagos, lecturas, autorizaciones, devoluciones y anulaciones; `ReadPaymentAttemptTest` añade cuatro casos funcionales (confirmed, unknown/clave inválida, aislamiento y permisos), pero no contiene un caso concurrente específico POST/GET. Por tanto, no se afirma esa cobertura específica sólo por el total del conjunto.

## Compatibilidad, límites y dependencia

Es compatible con BE-D02-05 porque recupera el snapshot inmutable del POST sin modificarlo, y con BE-D02-06 porque no registra ni recalcula efectos financieros en GET. El frontend FE-D02-04 depende de este endpoint para recuperar marcadores de `sessionStorage`; sigue obligado a tratar `unknown` como incierto.

Los límites residuales son los propios de recuperación distribuida: cierre definitivo de pestaña, otra pestaña/dispositivo, borrado de almacenamiento, expiración del marcador, pérdida de autorización, cambio de actor/laboratorio o POST en tránsito pueden impedir una confirmación local inmediata. Ninguno convierte `unknown` en fallo ni habilita una nueva identidad automáticamente.

## Integración pendiente

Tras `git fetch origin --prune`, `HEAD...origin/develop = 0/11`. Los 11 commits entrantes incluyen crédito financiero, migraciones, lectores de Caja y modificaciones en `routes/api.php`; al aplicar su parche sobre este árbol local, Git rechaza el hunk de rutas. La integración requiere resolución consciente de contrato financiero y no se hace automáticamente. Hasta resolverla y repetir la suite aislada, este cambio no está listo para commit/PR desde esta rama.
