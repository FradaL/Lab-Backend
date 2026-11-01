# Simplificación de muestras por orden

El registro habitual usa el endpoint existente `POST /api/v1/laboratory-orders/{order}/samples/collect` con `sample_type_id`, `collected_at`, `collection_branch_id` y, opcionalmente, `context.notes`. El cliente genera `idempotency_key` para evitar duplicados. No requiere recipiente, aditivos, volumen ni condiciones.

El inicio de procesamiento exige una versión clínica publicada, una orden abierta y el permiso `orders.start_exam`, pero no exige muestras recibidas, aceptadas ni asociadas. También puede iniciarse sin registrar muestras. El registro de muestras y el procesamiento son independientes; no se inventan recepciones o aceptaciones.

La nueva migración `2026_11_01_000000_decouple_processing_from_sample_requirements.php` actualiza la protección PostgreSQL del inicio. Debe aplicarse con `php artisan migrate` desde `laravel` antes de probar el frontend actualizado. Su rollback restaura la validación previa para futuros inicios; no borra datos ni deshace inicios ya registrados.

Los datos y endpoints anteriores de características y asociaciones se conservan por compatibilidad. Los vínculos históricos adecuados todavía generan trazabilidad al iniciar un examen; las dependencias existentes conservan sus protecciones. No se modifican permisos, aislamiento por laboratorio, validación de resultados ni finalización clínica.

Las respuestas de muestras incluyen el nombre del tipo y de las sucursales para poder consultar lo registrado; las notas permanecen en el contexto del evento de recolección.

## Prueba manual

1. Abrir una orden pendiente con un examen de versión clínica publicada que tenga requisitos antiguos de recipiente o volumen.
2. En Muestras, registrar recolección indicando tipo, fecha/hora y sucursal. Probar con notas y sin notas. No debe aparecer la sección de características observadas.
3. Consultar el detalle: verificar tipo, sucursal, fecha/hora y notas. No debe mostrarse una sección vacía de asociaciones ni acciones de asociar/liberar.
4. Ir a Procesamiento e iniciar el examen sin recibir, aceptar o vincular la muestra. Capturar resultados desde Resultados.
5. Repetir con otra orden sin registrar muestras: el inicio también debe estar disponible.
6. Verificar que una fecha futura, una sucursal de otro laboratorio o un usuario sin permisos siguen siendo rechazados. Comprobar cambio de laboratorio y cierre de sesión.

## Regresión automatizada

Desde la raíz backend, usar exclusivamente el entorno temporal:

```sh
docker compose -p donqer-simplify-samples -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-simplify-samples -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/PhysicalSamples tests/Feature/ClinicalResults tests/Feature/ClinicalFinalization
docker compose -p donqer-simplify-samples -f deploy/testing/compose.yaml down --volumes --remove-orphans
```

Desde frontend: `npm run build`, `node --test src/modules/orders/utils/*.test.mjs` y `node tests/samples-merge-browser.mjs`. La prueba de Chromium usa respuestas HTTP simuladas y no escribe en Laravel.

## Verificación realizada

- Backend: suite final de muestras aprobada, 49 pruebas y 436 aserciones. Las 237 pruebas adicionales de captura/finalización no presentaron fallos en la corrida combinada. La corrida combinada inicial de 286 tuvo una expectativa antigua de eliminación después del inicio; se corrigió el orden de esa prueba y se repitió completa la suite de muestras con resultado satisfactorio.
- Frontend: build de producción aprobado; las 27 pruebas de utilidades de órdenes aprobadas. Vite mantiene el aviso de tamaño del bundle.
- Chromium: recorrido aprobado en escritorio y móvil, con nueve comprobaciones de formularios, procesamiento, permisos, conservación del borrador y cambio de laboratorio. Usa transporte Axios con respuestas simuladas; no es una prueba de navegador contra Laravel real.
- Pint y `git diff --check` aprobados. No se modificó `.env`, no se migró la base de desarrollo y no se realizaron commits ni push.

- Migración nueva: rollback de un paso y reaplicación aprobados en PostgreSQL temporal.

Ramas: backend `feature/simplify-samples-backend` desde `develop`; frontend `feature/simplify-samples-frontend` desde `main`.

## Ajuste posterior: recolección y recepción independientes

- La recepción habitual ahora solicita tipo, procedencia (paciente por defecto o centro externo), fecha/hora de recepción, sucursal y notas opcionales. Se retiraron la fecha/sucursal de recolección, el nombre del centro y la descripción del recolector del formulario. Los metadatos antiguos permanecen disponibles en la API por compatibilidad.
- Una muestra registrada como recolectada conserva ese estado y ya no ofrece registrar recepción. El listado tampoco indica que esté pendiente de recepción. Las muestras recibidas se crean directamente mediante el registro de material entregado.
- `POST /samples/{sample}/receive` solo permite repetir una recepción ya existente con los mismos datos; intentar convertir una recolectada devuelve 409 `sample_state_conflict`. Las evaluaciones y los rechazos siguen disponibles donde corresponden; no se reescriben datos históricos.
- La migración adicional `2026_11_01_000001_preserve_collected_sample_origin.php` impide también la conversión mediante escrituras directas en PostgreSQL. Ejecutar `php artisan migrate` para aplicar las migraciones pendientes.

Para probar: registrar una recolección, comprobar que siga recolectada y sin acción de recepción; luego registrar por separado una muestra entregada por un paciente, comprobar que figure recibida y que el formulario ya no incluya contexto del recolector ni campos de recolección.

Verificación del ajuste: suite de muestras 50/50 aprobada; las dos pruebas focalizadas de recepción mínima y bloqueo API/SQL también aprobadas con aserciones adicionales. Build y 27 utilidades de frontend aprobados; recorrido Chromium de escritorio y móvil aprobado. No se migró la base de desarrollo.

## Recepción sin aceptación separada — DF-020 1.1

Registrar material recibido ya representa su aceptación operativa por parte del usuario. Su estado continúa siendo `received`, que lo distingue de `collected`; la interfaz retira **Aceptar muestra**. No se crea otro evento de aceptación. El endpoint antiguo `/samples/{sample}/accept` conserva sus permisos y responde idempotentemente sin cambiar recibidas ni reescribir aceptaciones históricas. Rechazos, compatibilidad por requisito y demás controles clínicos permanecen.

La migración `2026_11_01_000002_keep_received_samples_without_separate_acceptance.php` impide nuevas transiciones a `accepted` y reconoce material `received` junto al `accepted` histórico en asociaciones, dependencias, cumplimiento de reposiciones y sus guardas de evidencia. Reemplaza exclusivamente los predicados de estado pertinentes; no completa automáticamente intenciones de reposición ni autoriza excepciones terminales. Los demás controles SQL se preservan. No actualiza ni elimina muestras/eventos anteriores.

Aplicar las migraciones pendientes mediante `php artisan migrate` desde el entorno Laravel. Para probar: registrar material recibido, comprobar que siga **Recibida** y sin opción **Aceptar muestra**, iniciar/capturar según el flujo actual y verificar que rechazar sigue exigiendo permisos/motivo. Los datos demo nuevos usan una muestra recibida directamente; no convierten una recolectada ni ejecutan aceptación adicional.

Verificación de recepción/aceptación: 54 casos de muestras pasaron en la corrida inicial. La regresión de 126 casos de reposiciones, elegibilidad de excepciones, captura y datos demo tuvo un error de fixture histórico: reconstruía funciones antiguas sin reaplicar las migraciones recientes. Se corrigió la preparación; el caso y las dos pruebas reforzadas de recepción/rollback se repitieron satisfactoriamente (3 pruebas/27 aserciones). Los otros 125 casos de esa regresión no tuvieron fallos. No se contabilizan las repeticiones como pruebas únicas adicionales.

Frontend: build, 27 archivos de pruebas de utilidades y recorrido Chromium de escritorio/móvil aprobados; se comprobó ausencia de aceptación separada y conservación de permisos de rechazo y contexto. El navegador usa respuestas simuladas. Pint, sintaxis PHP y comprobación de diff aprobados. La migración se aplicó/revirtió/reaplicó únicamente en PostgreSQL temporal; no se modificaron `.env` ni datos de desarrollo.
