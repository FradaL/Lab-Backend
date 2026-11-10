# BE-D02-10 — Integración comercial y controles de entrega

Fecha: 2026-11-10. Implementación local sobre la rama `BE-D02-10`, después de BE-D02-09. Criterios: [tarjeta BE-D02-10](https://trello.com/c/kcez0UGr), D02 1.2 y D12 1.1. Esta tarea integra el circuito sensible de entrega existente; la captura y rectificación clínica integral D10 se conserva como dependencia propia.

## Contrato y comportamiento

- La emisión del PDF no libera el informe. `POST /api/v1/laboratory-orders/{order}/reports/{report}/validity-events` con `state=released`, `expected_revision=0`, motivo e `Idempotency-Key` registra una liberación explícita tras verificar la revisión, las resoluciones definitivas, la finalización aplicable y el PDF íntegro. Requiere `reports.issue`; consultar el historial de vigencia requiere `reports.view_history`.
- Los estados siguientes son `suspended`, `invalidated` y `superseded`. Los dos últimos son terminales. `superseded` exige un informe posterior, liberado, vigente, del mismo laboratorio y orden, que cubra todas las líneas de la emisión anterior. Cada transición conserva actor, fecha, motivo y revisión inmutable. La liberación tras suspensión requiere revalidación y nueva revisión.
- La evaluación externa y la descarga interna consultan la liberación y vigencia actuales. Preparar, descargar y confirmar revalidan clínica, documento, política comercial conservada, evidencia financiera, excepción vigente y permisos según el paso. La preparación guarda la revisión de vigencia: una suspensión seguida de nueva liberación no reactiva una copia preparada antes.
- La entrega externa exige declaración manual `authorization_confirmed=true`, destinatario/canal/base/evidencia y el mismo empleado durante preparación, descarga y confirmación. La descarga externa (`purpose=external_delivery`) no registra una entrega; confirmarla requiere descarga previa. La descarga interna (`purpose=internal_use`) no exige pago ni crea entrega externa.
- Los rechazos de preparación, descarga o confirmación bajo control del servicio se auditan después del rollback, con motivo técnico y sin copiar datos del destinatario al evento. Los permisos de descarga y confirmación son independientes. La autorización BE-D02-09 sigue limitada a la emisión/preparación exactas, 15 minutos desde autorización y consumo único.

## Migración y adopción

`2026_11_10_000002_add_report_validity_controls.php` agrega eventos inmutables de vigencia y revisión a la preparación, y añade la verificación SQL a inserción/descarga/confirmación. No modifica emisiones, PDF ni historial previos. Las emisiones históricas no reciben una liberación inventada: quedan bloqueadas para nueva descarga/entrega hasta que un actor con `reports.issue` las libere tras revalidación. Las preparaciones antiguas sin revisión de vigencia no pueden continuar. Revisar y liberar manualmente cada emisión histórica necesaria antes de habilitar el circuito. El rollback exige respaldo/restauración explícitos.

## Límite clínico

El control de vigencia permite bloquear o sustituir una emisión exacta y conserva el historial. No implementa captura/rectificación de valores ya liberados, notificación a destinatarios de copias anteriores ni retirada de copias fuera del sistema. La sustitución requiere que exista una nueva emisión clínicamente válida. La implementación integral de D10 y el resto de D12 siguen su propio alcance.

## Verificación

Validación final en PostgreSQL 17 efímero mediante `deploy/testing/compose.yaml`: `tests/Feature/ReportDelivery` **76 pruebas / 320 aserciones satisfactorias**; `ClinicalReportTest.php` y `ClinicalReportIntegrityTest.php` **43 pruebas / 217 aserciones satisfactorias**; inventario de rutas protegidas **1 prueba / 4 aserciones satisfactoria**. La batería de entrega cubre operación ordinaria y excepcional, límite exacto, declaración, reintentos, concurrencia, permisos, tenant, clínica, movimientos financieros, preparación, descarga, confirmación y vigencia. OpenAPI se generó en almacenamiento temporal y se verificaron las rutas de vigencia y elegibilidad entre **158 paths**. Pint, sintaxis PHP y `git diff --check` se verifican en el corte final.

La base habitual no se migra. Los contenedores de pruebas se retiran al terminar. No se atribuye a este corte la resolución de fallos previos de Caja fuera del alcance BE-D02-10.
