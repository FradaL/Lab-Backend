# Corrección del resumen de exámenes de una orden

La tabla de Resumen consumía campos del modelo de demostración que no existen en la respuesta real del listado: `processing_status`, `result_status`, `validation_status` y `resolution`. Los contadores también buscaban `status` en esas líneas. Esto generaba «Sin estado», insignias vacías y ceros sin respaldo.

El frontend une las líneas comerciales con `GET /laboratory-orders/{order}/operational` por ID de orden e ID de línea, no por ID de examen. Presenta el estado operativo, `result_capture.status`, `result_capture.clinical_status` y `clinical_resolution`. Una captura completa preliminar no se presenta como validada ni como resolución definitiva. Los contadores usan las mismas líneas; si falta información muestran ausencia explícita en vez de inventar ceros. La consulta conserva los permisos existentes y no crea peticiones por cada examen.

El detalle del examen usa la misma información y las acciones disponibles llevan a Resultados o Historial según permisos; se retiraron acciones simuladas de ese panel. El diálogo usa los componentes compartidos y se cierra al cambiar de orden o contexto.

El listado de exámenes añade `exam.area`, con ID y nombre obtenidos exclusivamente de `report_area_snapshot`. Si no existe el snapshot, devuelve null y la interfaz muestra «No registrada». No consulta el área mutable del catálogo ni completa datos históricos retrospectivamente. El contrato OpenAPI refleja el campo adicional; no requiere migración.

Implementación local posterior a backend `17f12ac` y frontend `415f22d`, en las ramas actuales de simplificación. No se atribuye integración, despliegue ni cambios en reglas clínicas. Pruebas en PostgreSQL temporal para el listado: 20 casos/176 aserciones; incluye orden/laboratorio, snapshots, ausencia de N+1 y campos permitidos. Frontend: 28 archivos de utilidades y build aprobados. Recorridos Chromium con respuestas simuladas verifican áreas, exámenes repetidos, estados, contadores, detalle y, en móvil, error/reintento/revocación de permisos. No se probaron interacciones con una base de desarrollo ni se modificó `.env`.

La regresión de creación de líneas también pasó: 36 pruebas/227 aserciones, con el contrato esperado actualizado para incluir el área. La prueba de creación confirma que el snapshot guardado por el flujo real se serializa con ID y nombre. Son 56 casos backend entre listado y creación; no se reejecutó la suite completa del sistema.

## Presentación del origen y del responsable de inicio

En Procesamiento, los códigos de `clinical_configuration_source` se presentan como «Versión publicada», «Configuración anterior» o «Registro histórico». No se cambian los valores del contrato de escritura ni se traducen los códigos internos de la API.

La consulta operativa añade `processing_started_by_user` con solo ID y nombre de quien inició esa línea. Conserva `processing_started_by` como identificador técnico y carga las relaciones de usuarios en conjunto. La interfaz muestra **Iniciado por** con el nombre, no un número aislado. La referencia es el actor persistido, no el usuario que consulta actualmente; se conservan los nombres disponibles de usuarios que posteriormente quedaron inactivos. Si no hay inicio, muestra «Aún no iniciado»; si existe referencia pero no nombre, «Usuario no disponible». No necesita migración ni modifica eventos previos o permisos.

Verificación del responsable de inicio: 46 pruebas/404 aserciones de `PhysicalSampleTest.php` satisfactorias en PostgreSQL temporal; incluye un consultante diferente, el iniciador inactivo y respuesta restringida a ID/nombre. Build, Pint y recorrido Chromium con respuestas simuladas satisfactorios para el nombre y las etiquetas en español. Sin pruebas contra la base de desarrollo.
