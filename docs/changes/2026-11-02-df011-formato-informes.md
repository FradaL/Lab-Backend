# DF-011: formato de informes, primera entrega

## Alcance autorizado

Solicitud del usuario de cumplir DF-011, con dos aclaraciones expresas en esta
conversación: los críticos requieren confirmación explícita del validador
autorizado; el módulo de correcciones posteriores al cierre D10 queda para una
segunda entrega. No se declara cumplimiento integral de R5 ni de la gestión de
vigencia/sustitución de R7. No se reabren órdenes ni se modifican validaciones,
resoluciones, cierres o PDF históricos.

Bases: backend `d64540d` y frontend `6b3c080`. Implementación local en la rama
`feature/df011-report-compliance` de cada repositorio; no equivale a integración
ni despliegue. DF-011 conserva su aprobación 1.0 y el texto original R1–R12.

## Comportamiento implementado

- R1/R6: plantilla controlada con temas negro, verde azulado, azul y vino;
  logo, textos, tamaños y alineación conservados. El diseño no acepta resultados,
  referencias ni clasificaciones. Cada emisión conserva su presentación y bytes.
- R2: orden preferido de áreas y exámenes por identificadores locales. Agrupación
  con área histórica y separación por examen; elementos no priorizados mantienen
  su orden relativo. Los cambios afectan nuevas emisiones.
- R3: clasificación clínica exacta mediante BigDecimal y verificación SQL sobre
  los valores y referencias históricos. Normal, alto, bajo, crítico y fuera de
  referencia se expresan con texto legible en blanco y negro. Sin regla aplicable
  se informa que no hay clasificación; no se inventa normalidad. Umbrales críticos
  opcionales `critical_min`/`critical_max` son cadenas decimales inclusivas,
  configuradas/publicadas por el laboratorio. Nunca se toman del diseño. Una
  validación crítica sin `critical_confirmation: true` se rechaza sin cambiar la
  captura. Se conserva quién confirmó, cuándo y para qué parámetros.
- R4: número de versión impreso, parciales identificados y selección explícita
  de exámenes resueltos en frontend. Los pendientes se incluyen informativamente
  en el formato actual, sin publicar sus valores ni confundirlos con No realizado.
- R7: versión de emisión y QR de autenticidad. La respuesta pública mantiene
  datos mínimos y añade `validity: confirm_with_issuing_laboratory`; no acredita
  vigencia clínica. El PDF lo indica. Sustitución, suspensión y corregidos son D10.
- R8: identidad del validador conservada en nuevas validaciones; identificación
  profesional cuando corresponde. Espacio para firma manuscrita y sello, sin
  afirmar que el PDF está firmado. Firma digitalizada opcional: raster privado
  normalizado, autorización personal del dueño de una identidad profesional
  autorizada y confirmación explícita en cada validación. No es firma electrónica
  certificada. La revocación afecta futuras validaciones; las evidencias y PDF
  anteriores se conservan. La imagen no se expone en el historial JSON.
- R9: observaciones autorizadas al validar, con ámbitos parámetro, examen, área e
  informe. Se conservan texto, autor, fecha, línea y área cuando corresponde. El
  servidor rechaza parámetros/áreas ajenos. Se presentan en su ámbito y no como
  observaciones genéricas sin autor.
- R10: carta, identificación del paciente y orden en cada página, Página X de Y,
  repetición de contexto de área/examen/parámetro, y agrupación de resultado,
  unidad y referencia. Bloques de firma se mantienen juntos cuando caben. No se
  fuerza una página por examen ni se reconstruyen PDF anteriores.
- R11/R12: edición de borradores, publicación explícita, historial inmutable
  de versiones publicadas con publicador/fecha y vista previa PDF real con datos
  exclusivamente ficticios. Guardar no cambia el diseño vigente. Owner obtiene
  los permisos existentes del catálogo; la delegación requiere asignación
  explícita de `reports.settings.view/edit`, sin dar permisos por nombre de rol.

## Contratos e instalación

Las tres migraciones `2026_11_02_000001`–`000003` agregan verificación de evidencia
clínica futura, borradores/publicaciones e historial de autorizaciones de firmas.
No actualizan PDF ni validaciones existentes. Se aplicaron en el entorno local;
no se ejecutaron en producción.

- GET/PUT `laboratory/report-settings`: consulta/guardado del borrador. Se conserva
  la forma revision/presentation, con published_revision/state adicionales.
- POST `laboratory/report-settings/publish`: revisión entera del borrador guardado;
  publicación idempotente de esa revisión. Guardar antes es obligatorio.
- GET `laboratory/report-settings/publications`: historial paginado. Para
  configuraciones anteriores no se inventan publicador ni fecha históricos.
- GET `laboratory/report-settings/preview`: PDF privado ficticio del borrador.
- GET/POST/DELETE `laboratory/validation-signature`: exclusivamente la propia firma.
  Subir requiere PNG/JPEG de hasta 1 MiB y 2000x1000, consentimiento y revisión;
  revocar requiere revisión. Ningún cliente puede elegir otro usuario/profesional.
- Validación de resultados: nuevos campos opcionales critical_confirmation,
  signature_confirmation y observations. La revisión y la autorización clínica
  se conservan. Las imágenes quedan fuera de Redux y las respuestas tardías se
  descartan al abandonar el contexto.

Los endpoints, campos y errores están documentados en OpenAPI. Los contratos de
emisión y descarga siguen usando revisión clínica, Idempotency-Key y autorización
por laboratorio. Si una emisión concurrente adelanta la secuencia durante el
render, se descarta el archivo provisional y se regenera fuera de transacciones
de filas, con un máximo de tres reintentos, antes de guardar la versión exacta.

## Verificación y límites

- Regresión de informes/validación: 119 pruebas, 761 aserciones satisfactorias.
- Maquetación, clasificación, DF-011 y concurrencia: 13 pruebas, 133 aserciones
  satisfactorias; esta batería se superpone parcialmente con la anterior.
- Verificación final específica: 3 pruebas/59 aserciones de catálogo, publicación,
  vista previa, críticos/observaciones y firma en PDF, conservando bytes tras
  revocar. Ambos PDF clínicos son ficticios y su texto se inspeccionó.
- PDF ficticio de cinco páginas inspeccionado con pdfinfo/pdftotext: carta,
  paciente/orden y total de páginas en todas ellas, versión y referencias juntas.
  Reproducción: exportar artefactos de ClinicalReportLayoutTest en
  /tmp/df011-artifacts y ejecutar tests/verify-df011-pdf.py sobre multipage.pdf.
- Frontend: 31 archivos de utilidades/esquemas satisfactorios; escenarios de
  configuración (incluidos errores y cambios de contexto), publicación/vista
  previa, teclado y móvil, parciales/completos y descarga, firma propia con
  consentimiento/revocación, contexto clínico y aislamiento entre revisiones.
  Build satisfactorio con advertencia de tamaño del bundle.
- OpenAPI generado en salida temporal: contratos nuevos presentes y documento
  válido. Configuración/vista previa PDF comprobadas con el administrador demo
  local, sin guardar/publicar diseños ni emitir informes reales.

La impresión física y la aprobación visual por usuarios no se presumen por las
pruebas. Las validaciones antiguas sin clasificación, nombre o firma conservados
no se completan desde datos actuales. D10 y el estado vigente de corregidos siguen
pendientes por decisión expresa del usuario, no se simulan con una nueva emisión.

## Implementación de la tabla DF-011 v1.1

### Fuente y alcance

Solicitud expresa de Daniel Lara «Donqer Lab — Implementación del formato de
resultados clínicos DF-011 v1.1», adjunto
`9f49ee00-8562-42de-81ea-ce6a1eaa281b/Pasted text.txt`, del 2026-11-02. Implementa
la decisión [DF-011 APROBADO 1.1](../../../donqer-lab-product/03-decisiones/DF-011-formato-informes.md),
sin crear otra versión funcional. Las secciones anteriores documentan la primera
entrega 1.0, previa a la tabla. Los permisos, migraciones y endpoints allí descritos
pertenecen a ese trabajo anterior, no a esta implementación de presentación.

Base backend `d64540d` más cambios locales anteriores de DF-011 en
`feature/df011-report-compliance`. Producto `fea3445` más cambios documentales
locales. Sin commits, push, cambios frontend ni bases compartidas.

### Archivos de esta implementación

| Archivo backend | Propósito |
| --- | --- |
| `laravel/app/ClinicalReports/ClinicalReportRenderer.php` | Celdas vectoriales de cuatro columnas, envoltura de texto y continuidad de filas largas. |
| `laravel/tests/Unit/ClinicalReportTableTest.php` | Siete escenarios sintéticos: un parámetro, mezcla de tipos, fila extensa, 65 parámetros, varias áreas/exámenes, parcial y personalización/logo. |
| `laravel/tests/Feature/ClinicalReports/ClinicalReportTableHistoryTest.php` | Parcial/completo con archivo histórico sin tabla; reintento y descarga sin render, nueva emisión con renderer actual y conservación íntegra del registro anterior. |
| `laravel/tests/verify-df011-pdf.py` | Poppler/Pillow: geometría de cuatro columnas, texto exacto por columna, referencias, continuidad, ausencia de superposición, contraste raster, paciente/orden y páginas. |
| `docs/changes/2026-11-02-df011-formato-informes.md` | Alcance, estrategia, reproducción, evidencia y limitaciones. |

`ClinicalReportLayoutTest.php` se reutiliza sin modificarlo en esta tarea. Los demás
cambios pendientes de backend/frontend pertenecen al trabajo anterior y se
preservan. No hay nuevas migraciones, endpoints, contratos ni dependencias.

### Estrategia de renderizado y paginación

Tabla real mediante rectángulos y celdas de texto de TCPDF, sin HTML ni imágenes
de la tabla. Márgenes existentes de 15 mm y 180 mm útiles, papel carta. Anchos
53/49/23/55 mm; letra de cuerpo de 9 pt; líneas de 5 mm y margen interno de 2 mm.
Encabezado gris claro, bordes grises y texto negro. El estado gráfico de cada
rectángulo se encapsula con `q/Q` para que su relleno no altere textos o pies.

Se mide cada carácter con la fuente instalada y se prefiere envolver por espacios;
una palabra mayor que la celda se divide sin truncarla. Cada fila usa la altura
máxima de sus celdas. Se traslada completa cuando cabe en una página nueva. Las
filas que exceden una página se segmentan por líneas compartidas, repitiendo
contexto clínico y encabezados de tabla. Columnas consumidas quedan vacías en
continuaciones; el parámetro sigue identificado en el contexto repetido.

Nombre/código, valor, etiqueta de opción, unidad, referencia/clave y clasificación
se toman de snapshots históricos. No se recalcula ninguna clasificación. Unidad
nula: «—». Referencia no configurada o sin regla aplicable: conserva la explicación
previa, sin inventar un intervalo. Se conservan los bloques de observación, firma,
No realizado y pendientes. El orden por área/examen y su configuración siguen
intactos. Parciales, completos y vista previa PDF comparten el renderer.

### Reproducción aislada

Se utilizó `deploy/testing/compose.yaml` con proyecto temporal `donqer-df011-v11`,
PostgreSQL 17 y sin red externa del cluster de pruebas. La base y el usuario son
exclusivos de testing, verificados por `TestDatabaseGuard`. El montaje del código
es de solo lectura; un override temporal monta `/tmp/df011-v11-artifacts` del host
en `/tmp/df011-artifacts` del contenedor para exportar exclusivamente fixtures.
No se monta la configuración de desarrollo ni se conecta a su base.

Comando final (con el override de artefactos cuando se desean exportar):

```sh
docker compose -p donqer-df011-v11 -f deploy/testing/compose.yaml -f /tmp/df011-v11-compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/ClinicalReports tests/Feature/ReportDelivery tests/Unit/ClinicalReportLayoutTest.php tests/Unit/ClinicalReportTableTest.php
python3 laravel/tests/verify-df011-pdf.py /tmp/df011-v11-artifacts/multipage.pdf
```

El script requiere Poppler (`pdfinfo`, `pdftotext`, `pdftoppm`, `pdfimages`) y Pillow
instalados en el entorno de inspección; no se añaden dependencias al proyecto.
Pint se ejecuta sobre el renderer y los dos archivos PHP nuevos. Se verifica
`git diff --check` en backend y producto. Tras las pruebas se elimina únicamente
el proyecto temporal de testing, conservando los artefactos ficticios del host.

### Resultados de validación

**Estado final: implementado y validado en local.** Batería final:
**118 pruebas / 765 aserciones satisfactorias**, cero fallos, errores u omisiones,
en 5:33.958. Incluye todos los tests de ClinicalReports y ReportDelivery, más
ClinicalReportLayoutTest y ClinicalReportTableTest. Diez PDF ficticios pasan
verificación de geometría, texto exacto por columna, referencias, contraste,
continuidad, identidad y páginas. Pint y `git diff --check` satisfactorios en
backend y producto. Las ejecuciones preliminares no se suman: detectaron dos errores
del fixture histórico por no autorizar la fuente local, y después dos fallos por
comparar atributos Eloquent sin releer con la representación persistida de
PostgreSQL. Se corrigieron los fixtures, sin modificar lógica productiva.

La inspección visual detectó y corrigió propagación del relleno gris al texto de
las celdas; ahora se verifica también contraste en el raster. Se inspeccionaron
PDF corto, mixto/cualitativo, personalizado, continuación y final de filas/tablas
largas. Los resultados pasan la comprobación estructural por coordenadas: no
basta con comprobar `%PDF` o extraer cadenas globales.

Artefactos ficticios locales en `/tmp/df011-v11-artifacts`:

- `table-short.pdf`: un parámetro, una página.
- `table-mixed.pdf`: numérico exacto, cualitativo, textual, opciones, Unicode y
  referencias/unidades ausentes, dos páginas.
- `table-long-table.pdf`: 65 parámetros y diez páginas.
- `table-long-text.pdf`: una fila deliberadamente extrema, 28 páginas, sin
  truncamiento; los marcadores finales del resultado y la referencia se conservan.
- `table-areas.pdf`: cuatro exámenes/dos áreas con orden configurado, cinco páginas.
- `table-partial.pdf` y `table-styled.pdf`: parcial/personalización, dos páginas.
- `multipage.pdf`: doce exámenes, seis páginas, reutilizando el fixture anterior.
- `issued-partial.pdf` y `issued-complete.pdf`: nuevas emisiones reales del servicio
  en testing con valor numérico exacto conservado y tabla.

### Documentación de producto sincronizada

- `06-especificaciones/formato-resultados-cuatro-columnas.md`: implementación,
  paginación, tipos, pruebas, límites y diagnóstico previo preservado.
- `03-decisiones/DF-011-formato-informes.md`: trazabilidad, misma versión 1.1.
- `03-decisiones/registro-decisiones.md`: seguimiento, sin nuevo identificador.
- `01-contexto/inventario-funcional.md`: soporte real de tabla PDF.
- `02-reglas-negocio/resultados.md`: referencia a presentación implementada,
  sin modificar las reglas clínicas.
- `03-decisiones/auditoria-decisiones-historicas.md`: seguimiento de H20.
- `06-especificaciones/README.md` y `README.md`: índices y corte vigente.

La política de revisar impacto de implementaciones futuras en producto ya existe
en `AGENTS.md` del repositorio de producto y se aplicó; no se duplica la política.

### Limitaciones y pendientes

No se modelan subsecciones físicas/químicas/microscópicas. Frontend conserva su
vista HTML orientativa previa; la vista PDF y las descargas ya reciben la tabla.
No se acredita impresión física ni aprobación visual del usuario. Una fila
patológicamente extensa genera tantas páginas como exija conservar su texto.
No se implementa D10 ni se declara cumplimiento integral de las doce reglas de
DF-011. No se alteran validación, finalización, emisión, entrega, políticas
financieras, permisos, almacenamiento privado, hashes o aislamiento multi-tenant.

## Corrección a tabla única continua DF-011 v1.1

### Autorización y alcance de la corrección

**DF-011 v1.1 — Aclaración de presentación aprobada: tabla única continua con
agrupación interna por área clínica y examen.** Daniel Lara, Product Owner,
solicitud «Donqer Lab — Corrección del formato PDF DF-011 v1.1», adjunto
`03f06ac2-31a2-4352-8be3-ebbc3d3c9781/Pasted text.txt`, 2026-11-02.
Esta corrección sucede a las tablas por examen documentadas arriba. Conserva la
versión funcional APROBADO 1.1 y todo el historial de las entregas anteriores.

Bases actuales backend `d64540d`, rama `feature/df011-report-compliance`, y
producto `fea3445`, rama `main`, con los cambios locales previos preservados.
No se modifican frontend, contratos, migraciones, permisos, reglas clínicas,
emisión ni entrega. No se accede a bases compartidas ni se reconstruyen PDF
archivados. Sin commits, push o despliegue.

### Cambio de renderizado

Se conserva TCPDF vectorial, carta, márgenes y cuatro columnas 53/49/23/55 mm.
El encabezado de columnas se dibuja al comenzar la tabla y al cambiar de página;
se elimina su reinicio por examen. Área y examen son filas de ancho completo
con texto reforzado (relleno más contorno fino de la misma fuente instalada),
fondo gris discreto para el área y color del tema publicado. El texto clínico
continúa negro, sin depender del color para comunicar su clasificación.

Las filas de parámetros comparten bordes exteriores con bandas de observaciones,
evidencia de validación, responsables y firmas. Se eliminan separaciones vacías
entre exámenes. No realizado y pendientes se integran como información claramente
diferenciada; no reciben valores clínicos ni se convierten en parámetros.
No se elimina ni se resume evidencia para compactar el formato.

Antes de comenzar un examen se mide la reserva de área/examen/validación y su
primer resultado. En continuaciones se repiten las cuatro columnas y el contexto
clínico dentro de bandas, con paciente/orden e institución fuera de la tabla.
Las filas que caben en una página nueva se mantienen completas y las extremas
se segmentan por líneas, con los mismos anchos y sin truncamiento. Una
presentación cuyo contexto repetido no deja espacio para contenido se rechaza
con el conflicto existente, en lugar de superponer contenido al pie.

`resultCells` únicamente distribuye snapshots históricos en las mismas cuatro
celdas. No convierte valores, consulta el catálogo actual ni recalcula referencias
o clasificaciones. El bloque de firma digitalizada conserva imagen, profesional,
autorización y aviso; se mide incluyendo el borde y las bandas adyacentes.

### Archivos de esta corrección

- `laravel/app/ClinicalReports/ClinicalReportRenderer.php`: tabla continua y
  paginación; único archivo productivo cambiado por esta corrección.
- `laravel/tests/Unit/ClinicalReportTableTest.php`: once escenarios; añade varios
  exámenes de una misma área, observaciones por cuatro ámbitos/No realizado y
  temas azul/vino, conservando mixtos, parcial, logo/verde azulado y extremos.
- `laravel/tests/verify-df011-pdf.py`: un encabezado por página, bordes exteriores
  ininterrumpidos incluso en evidencia, columnas internas solo en parámetros,
  bandas de ancho completo, primer resultado junto al examen, color del tema,
  fondo del área, contraste, superposiciones, texto exacto y contexto por página.
- `docs/artifacts/df011-v11-tabla-continua-ficticia.pdf`: copia del fixture
  `table-areas.pdf` para revisión del Product Owner; dos áreas, dos exámenes por
  área y seis parámetros por examen, cinco páginas.
- Este informe y siete documentos de `donqer-lab-product`, conservando fuentes
  y cortes anteriores: `03-decisiones/DF-011-formato-informes.md`,
  `06-especificaciones/formato-resultados-cuatro-columnas.md`,
  `03-decisiones/registro-decisiones.md`, `01-contexto/inventario-funcional.md`,
  `03-decisiones/auditoria-decisiones-historicas.md`,
  `06-especificaciones/README.md` y `README.md`.

Se reutilizan sin modificar `ClinicalReportLayoutTest`,
`ClinicalReportTableHistoryTest` y `ReportDf011Test`, junto con las baterías de
ClinicalReports y ReportDelivery. Los demás archivos pendientes pertenecen al
trabajo anterior descrito arriba.

### Reproducción y revisión

Proyecto temporal exclusivo `donqer-df011-continuous`, PostgreSQL 17 con datos
ficticios, red interna y configuración `.env.testing.example`. Código de solo
lectura y `TestDatabaseGuard` activos. Override temporal de exportación:

```yaml
services:
  tests:
    volumes:
      - /tmp/df011-continuous-artifacts:/tmp/df011-artifacts
```

```sh
docker compose -p donqer-df011-continuous -f deploy/testing/compose.yaml -f /tmp/df011-continuous-compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result tests/Feature/ClinicalReports tests/Feature/ReportDelivery tests/Unit/ClinicalReportLayoutTest.php tests/Unit/ClinicalReportTableTest.php
python3 laravel/tests/verify-df011-pdf.py /tmp/df011-continuous-artifacts/multipage.pdf
laravel/vendor/bin/pint --test laravel/app/ClinicalReports/ClinicalReportRenderer.php laravel/tests/Unit/ClinicalReportTableTest.php
```

La comprobación Python usa Poppler/Pillow ya disponibles, sin añadir dependencias.
Además de extraer texto, clasifica bandas y celdas por los separadores raster,
comprueba los cuatro límites de columna y verifica la continuidad de los bordes
exteriores en cada altura de la tabla. Comprueba que varios exámenes comparten
una página sin reiniciar los encabezados. No basta con encontrar cadenas globales.

Se inspeccionaron visualmente las cinco páginas de `table-areas.pdf`, incluidos
el paso entre exámenes en la misma página, las continuaciones y el cambio de área.
Se percibe una única estructura de bordes y columnas; no hay tablas separadas
por examen ni espacios en blanco intermedios. Esta inspección técnica no sustituye
la aprobación visual expresa del Product Owner.

### Resultados de esta corrección

**Estado técnico: implementado y validado en local; aprobación visual del Product
Owner pendiente.** Regresión de ClinicalReports, ReportDelivery y maquetación:
**122 pruebas / 777 aserciones**, sin fallos, errores u omisiones, en 7:37.561.
Después de ajustar la reserva del primer resultado/contexto y la altura de la
firma se comprobó la revisión final con `ClinicalReportTableHistoryTest`,
`ReportDf011Test`, `ClinicalReportLayoutTest` y `ClinicalReportTableTest`:
**17 pruebas / 119 aserciones**, sin fallos, errores u omisiones, en 00:21.533.
Ambas baterías se superponen; no se suman sus cifras ni las ejecuciones previas.

**Dieciséis PDF ficticios verificados** en la ejecución final de Poppler/Pillow:
once escenarios `table-*`, `multipage`, parcial/completo emitidos por el servicio,
críticos con observaciones y firma digitalizada. Incluyen los cuatro temas
controlados y la conservación de bytes de PDF emitidos tras revocar la firma.
Los casos históricos verifican descarga/reintento sin invocar el renderer,
bytes/hash y registro anterior intactos, y nueva emisión con el renderer actual.
Pint y `git diff --check` satisfactorios en backend y producto.

Artefactos en `/tmp/df011-continuous-artifacts`. El PDF persistido para revisión
es una copia del `table-areas.pdf` final. La inspección visual incluyó sus cinco
páginas, el tema verde azulado/logo y la página de firma. El proyecto Docker
exclusivo de testing se retiró después de las verificaciones, conservando los
artefactos del host. No se detuvieron servicios de desarrollo ni bases compartidas.

### Limitaciones pendientes

**Aprobación visual del Product Owner pendiente.**
[PDF ficticio para revisar](../artifacts/df011-v11-tabla-continua-ficticia.pdf).
No se acredita impresión física. El fixture patológico de texto extenso conserva
todo su contenido en treinta páginas por el contexto repetido dentro de la tabla;
no es un ejemplo de longitud habitual ni se limita eliminando texto. El frontend
orientativo HTML conserva su alcance anterior. D10, subsecciones clínicas no
modeladas y cumplimiento integral de R5/R7 conservan sus pendientes previos.

## Ajuste de encabezado QR y fecha

Solicitud posterior de Daniel Lara en esta conversación, 2026-11-02: incorporar
el QR al encabezado, omitir afiliación y «Identidad conservada al crear la orden»,
y presentar la fecha de emisión como día/mes/año. Se interpreta el separador
duplicado del mensaje como el formato habitual **dd/mm/AAAA**, sin hora.

`ClinicalReportRenderer.php` reserva 142 mm para los textos/logo del encabezado,
6 mm de separación y 32 mm para el QR en la esquina superior derecha. El QR se
repite en cada página y se elimina del cuerpo. La altura del encabezado reserva
ambos bloques. Las alineaciones configuradas operan en la zona institucional;
el pie y la tabla conservan sus 180 mm útiles.

Se omiten las líneas de afiliación y explicación del origen de identidad en el
PDF. Los datos de identidad y el timestamp de emisión permanecen intactos en sus
snapshots. Solo la fecha de emisión impresa se formatea como `d/m/Y`, usando la
fecha del timestamp conservado sin convertir su zona horaria. Las demás fechas
de evidencia conservan su representación anterior.

Se adapta `ReportPresentationSettingsTest.php` a la zona reservada, verificando
logo proporcional y alineaciones izquierda/centro/derecha sin invadir el QR.
`verify-df011-pdf.py` comprueba la fecha exacta de cada fixture, ausencia de las
líneas omitidas y tinta del QR en su región del encabezado en cada página,
además de las comprobaciones previas de tabla, temas, evidencia y paginación.
La primera ejecución detectó una expectativa de centrado del logo sobre 180 mm;
se corrigió al nuevo ancho de 142 mm sin eliminar comprobaciones de alineación.

Se sincronizan la decisión DF-011 y su especificación de cuatro columnas en
producto, conservando APROBADO 1.1 y los seguimientos anteriores. No se atribuye
la batería anterior de 122 pruebas a este ajuste. Sin cambios de contrato,
reglas clínicas, permisos, emisión, entrega ni bases compartidas. Sin commits,
push ni reconstrucción de PDF históricos.

[PDF ficticio con el encabezado actualizado](../artifacts/df011-v11-encabezado-qr-ficticio.pdf).
El ejemplo anterior de tabla continua se conserva en su archivo original.
La revisión visual del nuevo PDF corresponde al Product Owner.

Verificación final de este ajuste: **23 pruebas / 236 aserciones satisfactorias**,
cero fallos, errores u omisiones, en 00:48.310. Incluye historial, firma,
observaciones, configuración/alineaciones y los fixtures de maquetación.
**Dieciséis PDF ficticios verificados** con Poppler/Pillow, incluido QR y fecha
por página, sin superposiciones ni pérdida de resultados. Pint y
`git diff --check` satisfactorios. Inspección visual de la primera página,
continuación y personalización con logo. El proyecto temporal exclusivo
`donqer-df011-header` se retiró conservando los artefactos del host.

Reproducción: usar el comando de la corrección anterior con proyecto
`donqer-df011-header` y los archivos `ClinicalReportTableHistoryTest.php`,
`ReportDf011Test.php`, `ReportPresentationSettingsTest.php`,
`ClinicalReportLayoutTest.php` y `ClinicalReportTableTest.php` como selección de
PHPUnit; después ejecutar el mismo verificador Python. El override solo exporta
fixtures ficticios en `/tmp/df011-continuous-artifacts`.

## Responsables y firmas después de la tabla

Solicitud expresa posterior de Daniel Lara en esta conversación, 2026-11-02:
el texto del validador y los espacios de firma/sello deben aparecer debajo de
la tabla, después de mostrar los resultados. Este seguimiento sustituye la
ubicación dentro de bandas descrita en las entregas anteriores, conservando
DF-011 APROBADO 1.1 y su historial.

`ClinicalReportRenderer.php` reúne la evidencia de los validadores mientras
presenta los resultados. Después de todos los resultados, observaciones y
pendientes cierra la tabla y presenta **RESPONSABLES, FIRMAS Y SELLOS**, sin
bordes de tabla ni encabezados de resultados en continuaciones de esta sección.
Cada bloque identifica los exámenes, líneas y validaciones que le corresponden.
Se comparte un bloque únicamente cuando coinciden el usuario validador y los
snapshots completos de profesional, identidad y firma, incluida su autorización.
Evidencias distintas conservan bloques separados; no se sustituye identidad
histórica con datos actuales ni se reutiliza un consentimiento diferente.

Los avisos de ausencia de identidad profesional, espacio manuscrito y firma
digitalizada se conservan al final según la evidencia existente. Se mantiene
la integridad de la imagen y el aviso de que no equivale a firma electrónica
certificada. Se reserva el bloque completo cuando cabe en una página nueva;
una lista extraordinariamente extensa de asociaciones puede continuar por texto,
sin cortar ni omitir exámenes. No se modifican validaciones ni bytes archivados.

Archivos de este ajuste: renderer, `ClinicalReportTableTest.php`, verificador
Python, este informe, decisión DF-011 y especificación de cuatro columnas en
producto. Dos fixtures adicionales cubren responsables distintos y el texto
histórico de «Identificador del validador: 1» sin profesional. Los trece escenarios
de tabla comprueban que los bloques manuales finales no se duplican por examen.
El verificador exige sección final, ausencia de firmas dentro de la tabla,
resultados anteriores a las firmas y ausencia de superposición incluso en páginas
sin tabla. Conserva las comprobaciones de QR/fecha, resultados exactos y columnas.

**Verificación: 25 pruebas / 242 aserciones satisfactorias**, cero fallos,
errores u omisiones, en 00:43.031. Incluye historial, firma digitalizada y
revocación, observaciones, configuración y maquetación. **Dieciocho PDF ficticios
verificados** con Poppler/Pillow. Pint y `git diff --check` satisfactorios.
Inspección visual del final de dos áreas/cuatro exámenes con un único bloque
de firma, continuación sin firmas intermedias y ausencia de profesional con
el texto solicitado exclusivamente al final.

Reproducción: misma selección de cinco archivos PHPUnit del ajuste de encabezado,
con proyecto aislado `donqer-df011-signatures` y el mismo override de artefactos;
después ejecutar `verify-df011-pdf.py`. El proyecto temporal se retiró y los
artefactos ficticios se conservan en `/tmp/df011-continuous-artifacts`.

[PDF ficticio con firmas al final](../artifacts/df011-v11-firmas-al-final-ficticio.pdf),
dos áreas, cuatro exámenes y cinco páginas. Los ejemplos anteriores permanecen
intactos. Aprobación visual del nuevo PDF pendiente del Product Owner; sin
impresión física acreditada. Sin cambios de contratos, reglas clínicas, permisos,
emisión, entrega o bases compartidas. Sin commits, push ni despliegue.

## Omisión de leyenda de vigencia y observación autorizada

Solicitud expresa de Daniel Lara en esta conversación, 2026-11-02: quitar
«Vigencia actual: confirmar con el laboratorio emisor. El QR comprueba autenticidad»
y «La observación autorizada». Se eliminan del renderer esa línea y el bloque
genérico `Observación autorizada`, incluido el contenido de `validation.comment`.
Los comentarios se conservan en los snapshots y PDF archivados. Las observaciones
clínicas con ámbito parámetro/examen/área/informe mantienen su presentación.
El contrato público de autenticidad y los pendientes de vigencia/D10 permanecen
intactos. La fuente y su alcance se registran en DF-011 y su especificación en
producto, misma versión APROBADO 1.1, preservando los cortes anteriores.

El verificador PDF comprueba la ausencia tanto de las etiquetas retiradas como
del comentario genérico de cada fixture. Se reutilizan los tests de historial,
DF-011 y maquetación: **19 pruebas / 125 aserciones satisfactorias**, cero fallos,
errores u omisiones, en 00:23.242. **Dieciocho PDF ficticios verificados** con
Poppler/Pillow, conservando resultados, observaciones por ámbito, firmas al final,
QR/fecha por página y ausencia de superposición. Pint y `git diff --check`
satisfactorios. Inspección visual de la primera página actualizada.

Reproducción: misma selección del ajuste anterior sin
`ReportPresentationSettingsTest.php`, con proyecto aislado
`donqer-df011-clean-text` y el mismo override de exportación; ejecutar después
el verificador Python. El entorno temporal se retiró conservando los artefactos.
[PDF ficticio actualizado](../artifacts/df011-v11-textos-ajustados-ficticio.pdf).
Los ejemplos anteriores se conservan intactos. Sin cambios de API, permisos,
reglas clínicas, emisión, entrega ni bases compartidas; sin commits/push.
La revisión visual del nuevo PDF corresponde al Product Owner.

## Formato uniforme de fechas del informe

Solicitud expresa posterior de Daniel Lara, 2026-11-02: las fechas todavía
visibles como `2026-11-02T04:49:51.944525+00:00` deben mostrarse como día/mes/año.
El renderer utiliza ahora `displayDate` para emisión, validación, fecha de
referencia clínica, autorización de observaciones y decisión histórica.
Todas se imprimen como **dd/mm/AAAA**, sin hora, microsegundos ni sufijo de zona.
La medición previa de la fila de validación también usa el texto formateado.
No se convierten zonas horarias, se alteran timestamps/snapshots, se reescribe
texto clínico libre ni se reconstruyen PDF históricos.

Se amplían los fixtures con el timestamp exacto del ejemplo solicitado, fechas
de referencia y autorización, y una decisión del formato histórico. El
verificador exige las fechas exactas esperadas y rechaza fechas ISO en los
fixtures controlados. Verificación específica: **20 pruebas / 128 aserciones
satisfactorias**, cero fallos, errores u omisiones, en 00:21.911;
**diecinueve PDF ficticios verificados** con Poppler/Pillow. Pint y
`git diff --check` satisfactorios. Inspección visual de validación y autorización
de observaciones, ambas con `02/11/2026` y sin superposición.

Archivos: renderer, `ClinicalReportTableTest.php`, `verify-df011-pdf.py`, este
informe, decisión DF-011 y especificación de cuatro columnas en producto.
Reproducción: misma selección de PHPUnit y override de exportación del ajuste
anterior, usando proyecto exclusivo `donqer-df011-dates`; después ejecutar el
verificador Python. El entorno temporal se retiró conservando los artefactos.
[PDF ficticio con fechas uniformes](../artifacts/df011-v11-fechas-uniformes-ficticio.pdf).
Los ejemplos anteriores se preservan. DF-011 conserva APROBADO 1.1, sin cambios
de API, reglas clínicas, permisos, emisión, entrega ni bases compartidas.
Sin commits/push. Revisión visual del nuevo PDF pendiente del Product Owner.
