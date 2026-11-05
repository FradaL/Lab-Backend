# Listas de Particular sin asignaciones comerciales — 2026-11-05

Daniel Lara corrige el alcance de Recepción: una lista asignada a una entidad comercial no debe ofrecerse para Particular. Complementa la selección entre listas activas aprobada anteriormente. Fuente: conversación «la \"Nueva Lista\" esta asignada ya a una entidad comercial y me aparece como particular, y eso no debería pasar así».

Se añade el parámetro opcional `context=particular` a `GET /api/v1/price-lists/active`. Devuelve la misma colección ligera completa, ordenada por nombre e ID, pero excluye cualquier lista con registros de asignaciones comerciales del laboratorio, independientemente de su estado o vigencia. También excluye las listas inactivas y las de otros laboratorios. Si la predeterminada tiene una asignación, queda excluida igualmente. No se cambia ni repara ninguna asignación o marca predeterminada.

Sin `context`, el endpoint conserva el catálogo completo de listas activas para gestionar asignaciones comerciales. Los únicos valores permitidos para el nuevo parámetro son `particular`; los demás valores, arreglos y parámetros desconocidos devuelven 422. Payload y permisos existentes sin cambios. No se añaden migraciones ni se modifican las reglas de creación de órdenes o el resolvedor de sugerencias de precios.

Fuentes: `laravel/app/Http/Requests/Api/V1/PriceList/ActivePriceListRequest.php`, `laravel/app/Http/Controllers/Api/V1/PriceListController.php`, relación existente `PriceList::commercialClientAssignments()`. El frontend envía el contexto únicamente desde Recepción/Particular y conserva las consultas sin filtro en gestión comercial. Invalida el catálogo activo al modificar asignaciones.

Verificación backend ejecutada en proyecto Docker aislado `donqer-particular-prices-test`, PostgreSQL 17 temporal y PHP 8.4: `php vendor/bin/phpunit --do-not-cache-result tests/Feature/Api/V1/PriceLists/PriceListActiveTest.php`, 34 pruebas / 245 aserciones satisfactorias. Incluye exclusión de predeterminada asignada, asignaciones inactivas/futuras/vencidas, catálogo general conservado, listas inactivas, aislamiento entre laboratorios, validación y documentación OpenAPI. `vendor/bin/pint --dirty` y sintaxis PHP satisfactorios. Sin escrituras en bases compartidas; cambios locales sin commit ni despliegue.

Referencia funcional: `../donqer-lab-product/05-ux-ui/pantallas/UX-006-listas-activas-particular.md`, ampliación 1.1. Integración del frontend con el backend real y validación del Product Owner pendientes.
