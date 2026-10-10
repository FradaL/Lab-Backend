# Donqer Lab — Ciclo de vida de órdenes y reglas de negocio

**Versión:** 1.1 (diseño funcional; BE-S2-01)  
**Fecha:** 10 de octubre de 2026  
**Estado:** D01–D13 y DT-14–DT-17 aprobadas funcionalmente; implementación clínica pendiente  
**Audiencia:** Desarrollo backend, frontend, QA, producto y responsables técnicos de laboratorios.

> **Importante:** Este documento es la fuente principal de las reglas funcionales aprobadas y describe el comportamiento esperado, no su implementación. Los nombres que se identifican como propuestas no constituyen contratos implementados. La correspondencia con el backend y las propuestas técnicas se registran en [BE-S2-01 — Contratos de órdenes](sprint-2/BE-S2-01-contratos-ordenes.md).

## 1. Propósito y alcance

Donqer Lab es un SaaS multi-tenant para laboratorios clínicos. Este documento describe el ciclo de una orden desde su creación hasta el procesamiento de muestras, captura y validación de resultados, emisión y entrega de informes, incluidas correcciones posteriores. También define cómo interactúan pagos, destinatarios autorizados y políticas de cada laboratorio.

El objetivo es que una persona nueva en el equipo comprenda **qué debe ocurrir, por qué y qué invariantes no se deben romper**, sin confundir decisiones de producto con detalles de implementación.

## 2. Principios transversales

1. **Aislamiento por laboratorio:** ninguna lectura, escritura o autorización debe permitir acceso cruzado entre tenants. El contexto del laboratorio se resuelve en el backend; no se confía exclusivamente en identificadores enviados por el cliente.
2. **Estados separados:** el estado de la orden, el avance operativo individual de cada examen, los requisitos y muestras, los resultados y su validación, la situación financiera y la emisión/entrega de informes representan procesos diferentes. No condensarlos en un único campo.
3. **Trazabilidad:** conservar quién hizo cada acción, cuándo, sobre qué recurso y con qué justificación cuando aplique.
4. **Historial inmutable:** no sobrescribir resultados validados ni informes emitidos. Las correcciones generan versiones nuevas.
5. **Autorización en backend:** permisos, políticas de pago y autorización del destinatario se verifican en cada operación sensible.
6. **Snapshot/versiones:** cambios posteriores del catálogo, referencias clínicas o plantilla de informes no alteran documentos históricos.
7. **Seguridad clínica:** los requisitos profesionales, regulatorios y la gestión de resultados críticos deben respetarse; no se presupone que una política comercial pueda bloquear comunicaciones clínicas obligatorias.

## 3. Vista general del ciclo

1. **Crear/componer orden:** se registra la cabecera y, opcionalmente, exámenes. Sin exámenes, la orden queda `pending`, pendiente de composición y sin requisitos. Con exámenes, se crean líneas con identidad independiente, snapshots económicos y clínicos, requisitos y auditoría en una única transacción; un fallo revierte todo. Al modificar la composición se sincronizan requisitos preservando trazabilidad.
2. **Resolver autorización de procesamiento:** verificar permiso, política financiera efectiva, al menos un examen solicitado y al menos uno clínicamente procesable; no es necesario que todos estén listos.
3. **Recolectar/recibir muestras:** registrar cada muestra física cuando exista; comprobar aceptación o rechazo y cumplimiento de requisitos.
4. **Procesar exámenes:** cada examen avanza operativamente de manera independiente cuando sus requisitos están satisfechos o su configuración establece que no necesita muestra física. Su avance operativo se distingue de la captura y validación de resultados.
5. **Validar resultados:** autorización separada de la captura; el examen puede avanzar independientemente de los demás.
6. **Completar clínicamente:** desde `in_process`, con autorización, al menos un examen y todos validados o con resolución clínica final justificada; no deben quedar exámenes clínicamente pendientes. No requiere pago completo ni entrega.
7. **Emitir informe:** completo o parcial según configuración; incluir únicamente resultados validados y sus versiones concretas. Emisión y finalización clínica son dimensiones separadas.
8. **Entregar informe:** verificar política financiera y autorización del destinatario; registrar canal, versión, responsable y evidencia/estado.
9. **Corregir cuando proceda:** generar versión corregida, motivarla, validarla nuevamente, emitir informe corregido si corresponde y gestionar nuevas notificaciones/entregas. No reabre automáticamente una orden completada.
10. **Cancelar cuando proceda:** aplicar DT-17 según avance clínico, con motivo, autorización y auditoría, conservando registros clínicos, financieros y documentales.

**Nota sobre estados:** se conservan `pending`, `in_process`, `completed`, `cancelled`, verificados en el backend. Las condiciones funcionales de transición de DT-15–DT-17 todavía requieren implementación. Una entrega parcial **no** completa automáticamente la orden. Los nombres de estados individuales de exámenes, muestras, resultados e informes siguen siendo propuestas a definir. El orden de esta vista no exige esperar al cierre clínico para emitir un informe parcial autorizado.

## 4. Decisiones funcionales aprobadas

| ID | Decisión | Regla |
|---|---|---|
| D01 | Inicio de procesamiento y pago | Configurable por laboratorio o entidad comercial. |
| D02 | Entrega de resultados y pago | Política financiera independiente de la política para iniciar procesamiento. |
| D03 | Informes parciales | Habilitables por laboratorio; sólo incluyen resultados validados. |
| D04 | Muestras físicas | Modelo individual con trazabilidad implementada por etapas. |
| D05 | Requisitos vs. muestras | Generar requisitos al crear la orden; crear muestra física sólo al recolectarla o recibirla. |
| D06 | Recolección y recepción | Operaciones y permisos independientes; una persona con ambos permisos puede realizarlas. |
| D07 | Rechazo y reposición | Conservar muestra rechazada; requisito queda pendiente si no hay suficientes muestras aceptadas; reposición manual cuando exista muestra nueva. |
| D08 | Catálogo de exámenes | Catálogo base de plantillas copiables con personalización por laboratorio; también creación desde cero. |
| D09 | Captura y validación | Permisos independientes; la misma persona puede realizar ambas si está autorizada. |
| D10 | Correcciones | Versionadas, con motivo obligatorio y nueva validación; informe corregido cuando corresponda. |
| D11 | Formato de informes | Plantilla estándar personalizable por laboratorio. |
| D12 | Canales de entrega | Múltiples canales, con registro auditable de cada entrega. |
| D13 | Destinatarios | Autorizados por orden; autorización verificada antes de entregar resultados. |

Las trece decisiones anteriores conservan su significado. Se incorporan las siguientes decisiones aprobadas:

| ID | Decisión | Regla |
|---|---|---|
| DT-14 | Creación con exámenes opcionales | Mantener `POST /api/v1/laboratory-orders`; cabecera sin exámenes temporalmente compatible, o creación atómica con líneas, snapshots, requisitos y auditoría. |
| DT-15 | Procesamiento independiente | Iniciar con al menos un examen procesable, política financiera favorable y autorización; cada examen avanza independientemente. |
| DT-16 | Finalización clínica | Completar con todos los exámenes validados o resueltos clínicamente con justificación, sin pendientes; pago y entrega no condicionan el cierre. |
| DT-17 | Cancelación controlada | Motivo y auditoría obligatorios; procedimiento excepcional y permiso específico si existen resultados validados en una orden `in_process`; preservar todo el historial. |

**Correspondencia D05/DT-14:** D05 mantiene la generación de requisitos al crear una orden con exámenes. DT-14 precisa la modalidad temporal de cabecera: sin exámenes no hay demanda de muestras que generar. Cuando se incorporen líneas, se generan sus requisitos. Esta precisión no autoriza muestras ficticias ni elimina requisitos necesarios. No se identifican contradicciones entre las nuevas decisiones y D01–D13; los detalles no aprobados permanecen en la sección 15.

## 5. Orden y políticas financieras

### 5.1 Separación de dimensiones

Una orden puede estar en procesamiento, tener exámenes validados, tener saldo pendiente y haber tenido un informe parcial entregado. Esas circunstancias no son contradictorias. Evitar un solo estado que pretenda expresar todas.

Una orden `completed` también puede tener saldo pendiente y no haber entregado informes: su estado expresa finalización clínica, no cierre financiero ni documental (DT-16).

### 5.2 Políticas de pago

Definir dos políticas independientes:

- **Procesamiento:** ¿puede comenzar el trabajo sin pago completo?
- **Entrega:** ¿puede liberarse un informe al destinatario con saldo pendiente?

La configuración de una entidad comercial puede prevalecer sobre el valor predeterminado del laboratorio. La política efectiva aplicada a una orden debe conservarse para trazabilidad; el momento y reglas para recalcularla ante cambios posteriores requieren confirmación técnica y de producto.

**Invariante:** permiso de procesamiento no implica permiso de entrega. La excepción por comunicación de resultados críticos o deberes legales exige un flujo explícito, autorizado y auditado.

El pago completo y la entrega no son requisitos para completar clínicamente una orden. Una cancelación no provoca devoluciones automáticas; los registros financieros se conservan (DT-16/DT-17).

### 5.3 Creación y composición (DT-14)

Se mantiene `POST /api/v1/laboratory-orders` y se amplía para aceptar un arreglo opcional de exámenes, preservando temporalmente a los consumidores actuales.

- **Modalidad A, sin exámenes:** crear cabecera `pending`, pendiente de composición; no generar requisitos inexistentes ni muestras físicas.
- **Modalidad B, con exámenes:** crear cabecera, líneas, snapshots económicos y clínicos, requisitos y auditoría dentro de una única transacción. Revertir todo ante cualquier fallo.
- Cada solicitud de examen tiene identidad independiente, incluso si el mismo examen se solicita varias veces.
- Agregar o eliminar exámenes exige sincronizar requisitos según composición y trazabilidad. No borrar silenciosamente información clínica con trazabilidad.
- La representación de un arreglo vacío, el formato exacto de sus elementos y la respuesta ampliada son contratos técnicos pendientes, no nuevas decisiones funcionales.

### 5.4 Inicio y avance clínico (DT-15)

Para pasar de `pending` a `in_process`, el backend debe comprobar simultáneamente:

1. Al menos un examen solicitado.
2. Política financiera efectiva que permita iniciar procesamiento.
3. Al menos un examen clínicamente procesable.
4. Autorización del usuario para iniciar procesamiento.

Un examen es procesable si tiene satisfechos sus requisitos de muestras o su configuración clínica establece explícitamente que no necesita muestra física. La ausencia de configuración no demuestra que no necesite muestra. No se espera a que todos los exámenes tengan muestras disponibles. Cada examen tiene avance clínico propio, separado de los estados de captura y validación de sus resultados.

### 5.5 Finalización clínica (DT-16)

Para pasar de `in_process` a `completed`, se exige al menos un examen, autorización para completar y que todos los exámenes estén validados o tengan resolución clínica final justificada, sin pendientes clínicos. Cada resolución excepcional conserva motivo, responsable y fecha.

No se exige pago completo, emisión ni entrega de informes para completar. Las correcciones posteriores requieren versiones y revalidación, y no reabren automáticamente la orden. El catálogo de resoluciones finales y su representación técnica deben definirse antes de implementarlas.

### 5.6 Cancelación (DT-17)

| Estado/avance | Regla aprobada |
|---|---|
| `pending` | Cancelación con motivo obligatorio y auditoría, bajo autorización backend. |
| `in_process` sin resultados validados | Cancelación con motivo y auditoría; conservar muestras y actividades clínicas existentes. |
| `in_process` con resultados validados | Procedimiento excepcional con permiso específico, motivo y autorización. Conservar resultados, versiones, muestras y documentos; evaluar efectos sobre informes emitidos y destinatarios que recibieron resultados. |
| `completed` | No permite cancelación ordinaria. No se define aquí una transición excepcional desde este estado. |
| `cancelled` | Terminal para operaciones ordinarias. |

Toda cancelación preserva trazabilidad y registros clínicos, financieros y documentales, sin devoluciones automáticas. El procedimiento excepcional y la evidencia de autorización se deben precisar antes de habilitarlo.

## 6. Catálogo y configuración clínica

Donqer Lab mantiene un **catálogo base de plantillas** de exámenes y parámetros. Un laboratorio puede copiar una plantilla y adaptarla de manera independiente: parámetros, unidades, métodos, presentación e intervalos de referencia. También puede crear exámenes propios.

- Cambios globales **no** se propagan automáticamente a configuraciones locales.
- Cada laboratorio verifica y aprueba sus métodos e intervalos de referencia; no asumir rangos universales.
- Un examen puede tener parámetros numéricos, cualitativos, textuales u otros necesarios.
- Las configuraciones utilizadas en resultados históricos deben conservarse mediante versiones o snapshots.
- La configuración identifica explícitamente si un examen requiere muestras físicas y cuáles; un examen configurado sin muestra puede ser procesable según DT-15.
- La relación entre el catálogo existente y las nuevas plantillas debe diseñarse **después de auditar** los modelos actuales, para evitar duplicados.

## 7. Requisitos y muestras

### 7.1 Diferencia esencial

Un **requisito de muestra** expresa lo que necesitan los exámenes solicitados. Una **muestra física** representa material efectivamente recolectado o recibido. Al crear una orden con exámenes se generan los requisitos aplicables, **no** registros ficticios de muestras. La cabecera sin exámenes no genera requisitos; al agregar líneas se generan y sincronizan según DT-14. Un examen configurado sin muestra física no genera una necesidad ficticia de material.

Un requisito puede satisfacer varios exámenes compatibles; un examen puede requerir varias muestras; un requisito puede requerir múltiples muestras o reposiciones. La compatibilidad, volumen y cantidad se determinan por criterios técnicos del laboratorio.

### 7.2 Operaciones

- **Recolección interna:** registrar muestra, responsable y fecha/hora; luego registrar recepción y aceptación/rechazo.
- **Recepción externa:** registrar directamente recepción, identificando procedencia y datos disponibles; no inventar una recolección interna.
- **Recepción:** llegada física no equivale a aceptación para procesamiento.
- **Aceptación:** verifica que la muestra cumple condiciones requeridas.
- **Rechazo:** motivo obligatorio, registro persistente e historial de evento.
- **Reposición:** no se crea muestra física automática; el personal registra otra muestra cuando realmente exista.

Estados conceptuales posibles: `collected`, `received`, `accepted`, `rejected`. El estado **pendiente** corresponde inicialmente al requisito, no a una muestra inexistente. Confirmar nombres definitivos al auditar el backend.

**Regla de cumplimiento:** un requisito se considera satisfecho sólo si tiene suficientes muestras aceptadas y compatibles. Rechazar una muestra no vuelve pendiente un requisito que continúa cubierto por otras muestras válidas.

El cumplimiento se evalúa por examen solicitado: una orden puede iniciar con un examen procesable mientras otros continúan esperando muestras. Sincronizar la composición no elimina muestras, eventos ni vínculos históricos con actividad clínica. Una cancelación conserva las muestras y sus actividades (DT-14/DT-15/DT-17).

### 7.3 Trazabilidad por etapas

- **Sprint 2:** requisitos, muestra física, recolección, recepción, aceptación, rechazo, reposición, eventos y permisos.
- **Etapa posterior:** etiquetas/códigos de barras, ubicaciones y movimientos.
- **Etapa avanzada:** alícuotas, conservación, cadena de custodia cuando aplique y trazabilidad ampliada.

## 8. Captura, revisión y validación de resultados

Los resultados se registran por examen solicitado y por parámetro de la configuración clínica aplicable. Propuesta conceptual: `pending → entered → in_review → validated`; el paso de revisión no exige obligatoriamente un segundo profesional, salvo requisitos aplicables.

- **Capturar:** requiere permiso propio; permite registrar o ajustar borradores.
- **Validar:** requiere permiso independiente y competencia profesional aplicable.
- **Misma persona:** puede capturar y validar si dispone de ambos permisos y la regulación/procedimiento lo permite.
- **Auditoría:** registrar por separado captura y validación aunque el actor sea el mismo.
- **Informe:** sólo puede incorporar versiones de resultados validadas.

La aprobación debe identificar inequívocamente qué valores y configuración fueron validados.

La captura y validación no sustituyen el avance operativo individual del examen. Para completar una orden, cada examen debe estar validado o tener resolución clínica final justificada; una resolución excepcional no equivale a un resultado validado ni habilita valores no validados en un informe. Conservar motivo, responsable y fecha de esa resolución (DT-16).

## 9. Correcciones y versionado

Una vez validado, un resultado no se modifica mediante una edición ordinaria. Para corregirlo:

1. Usuario autorizado inicia solicitud con **motivo obligatorio**.
2. Se crea una nueva versión de trabajo; la anterior se conserva.
3. Se registran cambios, autor y marcas temporales.
4. La nueva versión requiere **nueva validación**.
5. Sólo entonces puede convertirse en la versión vigente para nuevas emisiones.
6. Si existieron informes emitidos o entregados con la versión anterior, se identifican los afectados; se emite un **informe corregido** cuando corresponda y se gestiona notificación/entrega a destinatarios autorizados.

**Invariantes:** no borrar la versión anterior, no sustituir silenciosamente un PDF ya emitido, no asumir que emitir un informe corregido equivale a haberlo entregado.

Una corrección posterior no reabre automáticamente una orden `completed`; se gestiona mediante el flujo de versiones y revalidación. Cancelar excepcionalmente una orden `in_process` con resultados validados tampoco elimina versiones ni documentos, y exige evaluar su impacto sobre emisiones y entregas previas (DT-16/DT-17).

## 10. Informes: emisión, versiones y personalización

Cada laboratorio podrá personalizar la plantilla estándar: identidad, logo, contacto, encabezado/pie, agrupación y presentación dentro de límites clínicos. Los datos obligatorios de trazabilidad no pueden omitirse.

- **Parcial:** sólo si la política del laboratorio lo permite; incluye exámenes con resultados validados y no completa automáticamente la orden.
- **Completo:** reúne los exámenes correspondientes una vez disponibles y validados conforme a reglas pendientes de precisar.
- **Corregido:** nueva versión identificada, enlazada con la anterior.
- **Inmutabilidad:** cada emisión preserva contenido, resultados/versiones, plantilla y metadatos utilizados.
- **Generación vs. entrega:** crear/descargar/imprimir un PDF no equivale a entregarlo al destinatario.

Completar clínicamente la orden no implica emisión ni entrega. Una resolución clínica final excepcional no permite presentar valores no validados; su representación en un informe completo permanece pendiente de definición. La cancelación excepcional exige evaluar informes emitidos y conservarlos; no autoriza invalidación, sustitución o notificación automática sin definir el procedimiento (DT-16/DT-17).

## 11. Entregas y destinatarios autorizados

Canales previstos: impresión/entrega física, correo electrónico, descarga interna y, en etapa posterior, portal del paciente; otros canales requerirán evaluación de seguridad.

Cada entrega debe registrar al menos: versión exacta del informe, canal, destinatario, autorización verificada, usuario/actor, fecha/hora y estado/evidencia disponible. Diferenciar **enviado**, **entregado** y **leído**; no afirmar confirmaciones que el canal no proporciona.

Los destinatarios se autorizan **por orden**. Paciente, representante, médico solicitante, aseguradora y entidad comercial no son categorías equivalentes. La relación comercial o el pago **no** otorgan acceso automático a información clínica.

La autorización debe verificarse antes de **cada** entrega, incluidas parciales y corregidas. Una revocación impide nuevas entregas basadas en la autorización revocada, sin borrar el historial de las anteriores. Contemplar menores, representantes y bases legales aplicables.

La orden puede completarse sin entrega y con saldo pendiente; cada entrega sigue su propia política financiera. Si una cancelación excepcional afecta resultados ya recibidos, identificar emisiones y destinatarios involucrados y aplicar el procedimiento autorizado, conservando el historial. La posibilidad de nuevas entregas de órdenes canceladas queda pendiente de definición.

## 12. Permisos y seguridad (nombres propuestos)

| Área | Permisos conceptuales |
|---|---|
| Órdenes | Autorizar inicio, finalización y cancelación; permiso específico para cancelación excepcional con resultados validados. Los nombres y su relación con el permiso actual se definirán en BE-S2-01. |
| Muestras | `samples.view`, `samples.collect`, `samples.receive`, `samples.accept`, `samples.reject`, `samples.history` |
| Resultados | `results.view`, `results.enter`, `results.validate`, `results.history`, `results.amend` |
| Informes/entregas | Definir tras auditoría de permisos existentes; separar emisión, descarga, entrega y consulta de historial. |

Los nombres son **propuestas**. Reutilizar el esquema real de roles y permisos del backend, no introducir un sistema paralelo. Verificar contexto de laboratorio, pertenencia, permisos, políticas financieras, autorización clínica y destinatario en servidor.

## 13. Entidades conceptuales (no migraciones definitivas)

- **Orden y exámenes solicitados:** reutilizar cabecera y solicitudes con identidad independiente; ampliar avance clínico y referencias a snapshots/versiones.
- **Resoluciones clínicas finales y cancelaciones:** conservar motivo, responsable, fecha y evidencia de autorización cuando corresponda; representación técnica pendiente.
- **Requisitos de muestras:** qué material se necesita y para qué exámenes.
- **Muestras físicas:** material registrado y su estado.
- **Relaciones muestra–requisito–examen:** soportan reutilización, multiplicidad y reposición.
- **Eventos de muestras:** trazabilidad de transiciones.
- **Plantillas globales y configuraciones locales:** exámenes y parámetros.
- **Resultados y versiones:** valores, validación, corrección y snapshots.
- **Informes y versiones:** documentos emitidos con referencias exactas a resultados.
- **Entregas:** eventos por versión, canal y destinatario.
- **Autorizaciones de destinatarios:** alcance, vigencia, evidencia y revocación.
- **Políticas financieras:** valores por laboratorio/entidad y política efectiva de la orden.

No crear todas estas tablas automáticamente. Determinar qué ya existe y qué puede implementarse con relaciones, eventos o snapshots en estructuras actuales.

## 14. Casos de aceptación mínimos

1. Orden con dos exámenes que comparten una muestra: un requisito compatible puede cubrir ambos.
2. Orden creada con exámenes que requieren material, sin recolección: hay requisitos pendientes, pero cero muestras físicas.
3. Muestra externa: recepción directa sin inventar responsable de recolección interna.
4. Muestra rechazada: conserva motivo e historial; una reposición manual satisface el requisito.
5. Usuario sólo con captura: no puede validar.
6. Usuario con captura y validación: puede ejecutar ambas, registradas como eventos distintos.
7. Orden con exámenes pendientes y otros validados: informe parcial sólo si el laboratorio lo permite.
8. Política de procesamiento permisiva y entrega restrictiva: se puede trabajar, pero no liberar informe sin cumplir la política.
9. Entidad comercial pagadora sin autorización clínica: no recibe resultados.
10. Informe enviado por correo: no marcarlo como leído ni entregado sin evidencia suficiente.
11. Resultado validado corregido: versión original e informe anterior permanecen; la nueva versión requiere validación y, si procede, nueva emisión y entrega.
12. Intento de acceso entre laboratorios: se rechaza en backend.
13. Cabecera sin exámenes: `pending`, pendiente de composición, cero requisitos y cero muestras; consumidor actual conserva compatibilidad temporal.
14. Creación con líneas repetidas: solicitudes independientes, snapshots y requisitos coherentes; fallo de cualquier escritura o auditoría revierte la creación completa.
15. Agregar/eliminar líneas: sincronizar requisitos sin eliminar silenciosamente trazabilidad clínica existente.
16. Orden vacía: no puede iniciar procesamiento ni completarse clínicamente.
17. Un examen con requisitos satisfechos y otro esperando muestra: iniciar con autorización y política favorable; el segundo no se procesa por el mero cambio de estado de la orden.
18. Examen configurado explícitamente sin muestra física: puede habilitar inicio sin crear material ficticio; una configuración ausente no basta.
19. Inicio sin permiso, sin política financiera favorable o sin examen procesable: rechazar sin mutaciones.
20. Todos los exámenes validados o resueltos con justificación, motivo/responsable/fecha y sin pendientes: completar con permiso aunque exista saldo y ningún informe entregado.
21. Examen clínicamente pendiente o resolución excepcional incompleta: impedir `completed`.
22. Corrección de resultado de una orden completada: nueva versión y revalidación, sin reapertura automática ni sobrescritura histórica.
23. Cancelación de `pending` o `in_process` sin resultados validados: motivo obligatorio y auditoría; preservar actividades, muestras y registros financieros, sin devolución automática.
24. Cancelación de `in_process` con resultados validados: exigir permiso específico y procedimiento excepcional autorizado, conservar versiones/documentos y evaluar emisiones y destinatarios previos.
25. `completed`: rechazar cancelación ordinaria; `cancelled`: impedir transiciones ordinarias a otros estados.

## 15. Pendientes antes de implementar

- Mantener la correspondencia auditada y revisar cambios del backend antes de implementar; evidencia actual en el complemento BE-S2-01.
- Definir nombres y transiciones individuales de exámenes, separados de resultados; los cuatro estados globales se conservan.
- Aprobar detalles del contrato opcional de exámenes, responses y compatibilidad temporal, incluida la representación del arreglo vacío.
- Precisar las restricciones de composición con trazabilidad y el tratamiento de datos históricos incompletos, sin inferir actividad clínica.
- Diseñar estructura mínima de requisitos, muestras y eventos sin duplicar entidades.
- Definir reglas de cantidad, compatibilidad y aceptación de muestras.
- Precisar alcance temporal de políticas financieras y sus excepciones.
- Definir mecanismo de autorización de destinatarios y evidencias.
- Diseñar versionado transaccional de resultados e informes y estrategia de almacenamiento seguro de PDF.
- Definir notificación y escalamiento de resultados críticos, obligaciones regulatorias y controles de privacidad.
- Definir el catálogo de resoluciones clínicas finales justificadas y su representación en informes; las condiciones generales de completar están aprobadas en DT-16.
- Precisar procedimiento, autorización y consecuencias de cancelación excepcional, incluidas nuevas entregas de órdenes canceladas; no habilitar cancelación ordinaria de `completed`.
- Aprobar permisos técnicos, reglas de concurrencia e idempotencia y contratos de error.
- Precisar qué canales se implementarán efectivamente en Sprint 2 y cuáles quedan para etapas posteriores.

## 16. Mantenimiento de este documento

Actualizar la versión cuando se apruebe un cambio de regla de negocio. Registrar decisiones nuevas, motivo del cambio, impacto en migraciones/API/frontend y pruebas. Mantener la **correspondencia con el código real** (modelo, tabla, servicio, endpoint y test por concepto) y distinguir capacidades implementadas, aprobadas pendientes y propuestas técnicas.

La correspondencia técnica se mantiene en el [complemento BE-S2-01](sprint-2/BE-S2-01-contratos-ordenes.md), para evitar duplicar contratos. Una propuesta técnica no altera una decisión funcional sin aprobación explícita.

### Historial de cambios

| Versión | Fecha | Cambio y motivo | Impacto |
|---|---|---|---|
| 1.0 | 10 de octubre de 2026 | Diseño funcional inicial, D01–D13. | Base del ciclo clínico; nombres técnicos sujetos a auditoría. |
| 1.1 | 10 de octubre de 2026 | Incorporar DT-14–DT-17: creación compatible con exámenes opcionales, avance individual, finalización clínica y cancelación controlada. Precisar D05 para cabeceras vacías. | Futura ampliación del request de creación, validaciones/transacciones, configuración clínica, permisos, auditoría y pruebas; consumidores deberán adaptarse sólo a contratos ampliados aprobados. No se implementa código ni se generan migraciones en BE-S2-01. |
