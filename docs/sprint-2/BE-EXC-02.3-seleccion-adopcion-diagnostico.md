# BE-EXC-02.3 — Selección y adopción: diagnóstico y decisiones necesarias

Fecha: **2026-10-25**. Estado: **AUDITORÍA PREVIA COMPLETADA; IMPLEMENTACIÓN PENDIENTE**. Este documento no declara completado BE-EXC-02.3. La propuesta técnica y las políticas nuevas siguientes no están aprobadas ni implementadas.

Fuente: solicitud «DONQER LAB — BE-EXC-02.3 — Selección y adopción individual de motivos clínicos globales por laboratorio», adjunto `4aa70de5-8ae9-4104-ba3a-7c03db6a89a9`. Se activan sus criterios explícitos de detención de los apartados 2, 5 y 6. Se documentan evidencia y alternativas antes de implementar la clasificación/revisión dependiente.

## Fuentes y método

Revisión estática de AGENTS y convenciones aplicables; [DF-019 APROBADO 1.3](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), DF-016, plan PROPUESTO 1.3, AUD-002, diagnóstico BE-EXC-02.2 y documentación/código de [BE-EXC-02.1](BE-EXC-02.1-identidad-versionado-motivos-clinicos.md), [BE-EXC-02.2-A](BE-EXC-02.2-A-frontera-autorizacion-saas.md) y [BE-EXC-02.2-B](BE-EXC-02.2-B-administracion-catalogo-global.md).

La solicitud de diseño BE-EXC-01 está disponible en el adjunto `0c2eab9e-58d2-4cdb-b66f-0447f80d2c0c` y la propuesta se concilia en el plan 1.3. No se localizó un informe/diagnóstico BE-EXC-01 independiente en los repositorios; no se presupone su contenido. El antiguo diagnóstico BE-EXC-02.2 se interpreta como corte histórico anterior a A/B.

No se consultó la base de desarrollo/producción, ni secretos. Esquema evaluado desde migraciones, modelos y servicios; no se acredita que esas migraciones estén aplicadas en un entorno desplegado. No se ejecutaron pruebas ni migraciones en esta auditoría.

## Evidencia verificable del estado actual

| Pregunta obligatoria | Evidencia en laravel | Conclusión |
| --- | --- | --- |
| Versiones globales | database/migrations/2026_10_25_062239_create_clinical_reason_identities_and_versions.php; Models/GlobalClinicalReason y GlobalClinicalReasonVersion; ClinicalExams/ClinicalReasonVersionStore::appendGlobal | Identidad estable, versiones numeradas completas e inmutables. SQL serializa por padre; el store compara número esperado. |
| Vigencia | global_clinical_reason_versions.is_active; GlobalClinicalReasonManager::activation | Cambiar vigencia crea versión sucesora. La última representa vigencia global actual; el true de una versión histórica no demuestra vigencia actual. |
| Clasificación del motivo | classification, validada por store y CHECK SQL | incidence_only y terminal_candidate. Describe el motivo; **no clasifica el impacto de una transición**. |
| Evidencia | evidence_requirements JSONB; clinical_reason_evidence_valid; store::validate | schema_version=1 y lista de identificadores únicos canónicos. Validación estructural, sin política clínica automática de comparación. |
| Diferencias | Versiones completas, number, change_reason; GlobalClinicalReasonManager::history/events; SaasAuditWriter::recordGlobalReason | Se pueden reconstruir cambios y causa. Auditoría referencia versión y predecesora. No hay evaluador de impacto clínico; cambiar texto no acredita restricción. |
| Marca restrictiva explícita | Columnas y CHECK de las migraciones 02.1/02.2-A/B; payloads de GlobalClinicalReasonManager y OpenAPI | No se representa ni acepta tal marca. created/published/activation_changed son operaciones administrativas, no declaraciones de impacto. |
| Bloqueo/revisión de adopción | Rutas, modelos, migraciones, servicios ClinicalExams y ClinicalFinalization | No hay persistencia/servicio de adopciones o revisiones de cambio global. BE08 usa reason libre, sin versión global/adopción a comprobar. No debe afirmarse que ya bloquea esas dependencias. |
| Identidades locales | Models/LaboratoryClinicalReason y LaboratoryClinicalReasonVersion; migración 02.1 | laboratory_id y FK compuestas a identidad/membresía. Son motivos personalizados, no selecciones de globales. BelongsToLaboratory aporta scope explícito; no es un filtro global automático. |
| Autoridad tenant | Authorization/RbacCatalog; ResolveLaboratoryContext; SetPermissionTeamContext; LaboratoryUserManager::authorizeActor | X-Laboratory-ID, membresía/cuenta activas, Teams y permisos efectivos. La revalidación de servicios existente es reutilizable como patrón. No hay permisos de adopción o revisión de catálogo. |
| Autoridad clínica | RbacCatalog::rolePermissions; ClinicalResolutionService::requestException/decideException | Permisos clínicos separados; owner/administrator no los reciben por su rol. Aprobar una excepción no define automáticamente autoridad para revisar un cambio de catálogo. |
| Auditoría tenant | Audit/AuditWriter::record, AuditEvent, Models/AuditLog | Exige laboratorio persistido, actor y evento con sujeto/metadatos. Es la infraestructura correcta para adopción. SaaS audit se reserva al gobierno global. |

Las consultas actuales del catálogo están en `/api/v1/platform/clinical-reasons`: listado, detalle, versiones, eventos y tres escrituras. Exigen facultades SaaS exactas. No se deben conceder a un owner para suplir una lectura tenant. No hay endpoint tenant de adopción localizado. La documentación B ya identifica como pendiente el criterio de relevancia y la integración con consumidores clínicos.

## Criterios de detención activados

La solicitud exige: **«Si no existe información suficiente para determinar objetivamente cuándo una actualización global es clínicamente restrictiva, no inventar una clasificación automática»** y **«Si BE-EXC-02.2-B no dispone de esa clasificación, detener esta parte»**. Ambas condiciones se cumplen.

Además exige: **«Si todavía no existe un contrato aprobado para determinar quién realiza la revisión clínica y qué acciones quedan bloqueadas mientras está pendiente, no inventarlo»**. No se encontró ese contrato. DF-019 aprueba separación de autoridad, revisión explícita y conservación histórica, pero no permite atribuir a owner, publisher o aprobador BE08 una nueva facultad por inferencia.

Se detiene la implementación de clasificación de impactos y revisión dependiente. La arquitectura de selección/lectura puede diseñarse independientemente; queda propuesta abajo. No se habilita adopción o uso clínico sin resolver cómo distingue una versión disponible de otra pendiente de revisión. Esto no declara que toda lectura aislada requiera una nueva decisión funcional: evita presentar como completo un circuito al que le falta una garantía esencial.

## Alternativas para declarar el impacto global — PROPUESTO

| Alternativa | Diseño | Consecuencias |
| --- | --- | --- |
| A — Declaraciones inmutables separadas, recomendada | Registro asociado a identidad y transición de versiones; impacto explícito no restrictivo/restrictivo, autor, fecha, causa y auditoría SaaS. Si se corrige, otra declaración referencia la anterior; nunca UPDATE histórico. Sin declaración significa sin clasificar. | Permite clasificar transiciones ya publicadas sin alterar versiones/auditoría existentes. Requiere aprobar autoridad, alcance de afectación y tratamiento de ausencia/correcciones. Reutiliza audit SaaS. |
| B — Metadatos obligatorios al publicar futuras versiones | Declaración explícita de impacto incluida en la publicación, persistida de forma inmutable. Registros anteriores permanecen sin clasificar, sin backfill heurístico. | Exige ampliar el contrato global B. Para clasificar publicaciones anteriores o corregir impacto sin cambiar contenido sigue necesitando evidencia complementaria. |

No se propone clasificar por diferencias de nombre/descripción, tratar todas las actualizaciones como restrictivas o traducir ausencia de marca a “segura”. is_active=false conserva la regla aprobada de desactivación; por sí solo no resuelve toda la clasificación de impactos ni define el contrato de revisión pendiente.

**Propuesta concreta A para aprobación:** una declaración vigente por transición, con historia de sucesoras y comparación de revisión esperada; nueva facultad explícita `saas.clinical_reasons.classify_change`, independiente de access.manage/view/create/publish/manage y sin concesiones automáticas. El PO debe ratificar esa autoridad. La declaración restrictive afecta a adopciones anteriores a su versión destino; se examinan todas las transiciones posteriores a la versión adoptada, incluyendo las intermedias luego revertidas. Adoptar una versión posterior no borra una revisión ya exigida. El alcance sobre primeras adopciones de versiones antiguas o posteriores, así como correcciones que reduzcan impacto, debe quedar ratificado antes de habilitar esas vías.

Esto ampliaría el gobierno global B, fuera del alcance ordinario de esta tarea; requiere autorización explícita. No se crea esa ampliación unilateralmente.

## Autoridad y efectos de revisión — PROPUESTO para aprobación

Propuesta independiente de la clasificación global:

- Resolver una revisión exige autoridad clínica tenant explícita, mediante un permiso específico como `clinical_reasons.reviews.resolve`; ser owner, administrar accesos o publicar globales no lo confiere. Falta ratificar quién puede recibirlo y cómo se provisiona. No se equipara automáticamente a orders.clinical_exceptions.approve.
- Cada revisión conserva laboratorio, adopción, versión origen, versión global que impone revisión, declaración de impacto y su causa, actor/fecha/justificación y resultado. Decisiones sucesoras inmutables, sin pérdida de revisiones previas ni aprobación de excepción implícita.
- Propuesta de bloqueo mientras falte clasificación o revisión exigible: impedir usar el motivo en nuevas solicitudes estructuradas y aprobar solicitudes dependientes. Permitir lectura e historia; la adopción administrativa posterior no levantaría por sí sola el bloqueo clínico. No bloquear por inferencia procesamiento/resultados ajenos a ese motivo.
- DF-019 ya prohíbe solicitudes nuevas con motivo desactivado. Revisión favorable no elimina esa prohibición ni otras restricciones clínicas, reposición viable o aprobación independiente. No resuelve ni cierra exámenes/órdenes.

Estos permisos, resultados y bloqueos **no se crean ni aprueban mediante este documento**. El PO debe definir/ratificar la política. La integración en solicitudes corresponde al futuro contrato estructurado: no se modifica BE08 ni se declara efectiva allí una protección inexistente.

## Arquitectura de adopción preparada — PROPUESTO, no implementada

Separar relación seleccionada de definiciones personalizadas. Reutilizar versiones globales por referencia; no clonar globales en laboratory_clinical_reasons ni fabricar versiones locales para representar una selección.

Persistencia candidata:

1. Identidad de adopción por `(laboratory_id, global_reason_id)`, única para impedir selecciones duplicadas. FK restrictivas; no eliminación de historia.
2. Revisiones inmutables de adopción: referencia a identidad/laboratorio, versión global adoptada mediante FK compuesta identidad/versión, número de revisión esperado/sucesor, actor perteneciente al laboratorio, hora servidor y justificación. Proyección del estado administrativo actual derivada de la última revisión; sus estados concretos quedan por especificar, separados de revisión clínica.
3. Impactos globales y revisiones clínicas separados de la definición adoptada, sujetos a las decisiones anteriores. No sobrescribir adopción ante publicación o desactivación global.

Cada adopción/actualización sería una acción explícita individual del owner conforme a I.E, con permiso tenant, contexto y membresía vigentes revalidados en servicio. Sin tarea de sincronización masiva, auto-adopción, propagación a personalizados o facultad SaaS concedida al laboratorio.

Transacción con autorización fresca, unicidad SQL, bloqueo de identidad de adopción y comparación de revisión para carreras. Integrar el orden de bloqueo global ya existente `(182045,1)` antes de filas tenant si se necesita coordinar catálogo/publicación/autorización SaaS; no invocar el servicio administrativo global para realizar lecturas tenant ni exigir su facultad. El diseño final debe comprobar revocaciones tenant/publicación concurrentes con conexiones PostgreSQL independientes.

Auditoría mediante AuditWriter/AuditEvent y audit_logs del laboratorio, en la misma transacción que la selección/revisión administrativa. Sujeto adopción y referencias explícitas a motivo, versión, revisión anterior/nueva, justificación y resultado exitoso; fecha/actor servidor. Las denegaciones no dejan selección o evento exitoso. Gobierno/impactos globales usarían auditoría SaaS, sin sustituir eventos tenant.

## Contratos candidatos — PROPUESTO, sin rutas/OpenAPI publicados

Prefijos candidatos `/api/v1/laboratory/clinical-reasons/global` y `/api/v1/laboratory/clinical-reason-adoptions`. No constituyen contrato implementado.

| Operación | Ruta candidata | Permiso tenant candidato |
| --- | --- | --- |
| Catálogo global disponible | GET global | clinical_reasons.global.view |
| Detalle e historia global | GET global/{reason}; GET global/{reason}/versions | clinical_reasons.global.view |
| Selecciones actuales | GET adoptions | clinical_reasons.adoptions.view |
| Primera adopción individual | POST adoptions, reason_id/version_id/justificación | clinical_reasons.adoptions.create, además de owner |
| Actualización explícita | POST adoptions/{adoption}/versions, versión destino y revisión esperada | clinical_reasons.adoptions.update, además de owner |
| Historia de selección | GET adoptions/{adoption}/versions | clinical_reasons.adoptions.history |
| Actualizaciones disponibles | GET adoptions/{adoption}/updates | clinical_reasons.adoptions.view |
| Revisiones pendientes | GET adoptions/{adoption}/reviews | Permiso de consulta por ratificar; no implica resolver |

Todos bajo auth:sanctum y el grupo tenant existente, con X-Laboratory-ID y controles de usuario/membresía/suscripción; pertenencia explícita de la adopción en todas las consultas. Facultades SaaS no autorizan estas operaciones. Consultar diferencias ofrece hechos; no decide riesgo por texto. Actualización disponible se deriva de números de versión, separada de clasificación pendiente y revisión clínica.

Errores previstos: 401 sin autenticación; 400 contexto ausente/inválido según middleware existente; 403 sin membresía/permiso; 404 adopción de otro laboratorio o recurso inexistente; 409 selección duplicada, revisión obsoleta o bloqueo pendiente según política aprobada; 422 payload inválido. Paginación y schemas exactos se cerrarían al implementar. OpenAPI debe documentar únicamente contratos realmente habilitados.

No se introducen endpoints de decisión clínica en esta propuesta de lectura/adopción. Sólo se implementarán después de ratificar su autoridad y alcance; no se inventan estados o permisos de revisión con apariencia de aprobación funcional.

## Plan de verificación después de autorización

PostgreSQL temporal aislado, nunca .env de desarrollo. Casos: selección individual A/B, ausencia de incorporación automática, identidad/versiones globales intactas, publicación posterior sin sustitución, actualización explícita e historia, laboratorios A/B con denegación cruzada, owner sin poder clínico, membresía/cuenta/permiso revocados, fallo de auditoría con rollback, selección/actualización duplicadas concurrentes, publicación concurrente y diferencias intermedias.

Después de ratificar impactos/revisión: ausencia de marca no equivale a no restrictivo; clasificación explícita auditable, cambio intermedio restrictivo luego revertido, revisión pendiente conservada tras adopción posterior, bloqueo y revisión sólo por autoridad clínica, revisión favorable sin aprobación/cierre, corrección de impacto sin pérdida de historia. Probar también el tratamiento que se apruebe para primeras adopciones de versiones antiguas y datos previos sin clasificar.

Regresión requerida: ClinicalReasons (02.1/02.2-B), SaasAccess (02.2-A), autenticación/Teams/autorización tenant y ClinicalFinalization (BE08), además de inventarios de rutas/OpenAPI. **Ninguna de estas pruebas se ejecutó en esta auditoría**; los resultados anteriores A/B no se atribuyen a BE-EXC-02.3.

## Dependencias, riesgos y estado final

BE-EXC-02.4 mantiene motivos personalizados en sus tablas propias. BE-EXC-02.5 deberá distinguir origen global adoptado/local y ofrecer información de disponibilidad/revisión aprobada; no debe interpretar latestVersion como adopción ni is_active histórico como elegibilidad actual. El consumidor de solicitudes deberá validar dependencias y bloqueos al solicitar/revisar/aprobar; fuera de este alcance, sin cambios en BE08 o snapshots.

Riesgos principales: convertir falta de clasificación en autorización clínica; omitir cambios intermedios; conceder revisión por ser owner; actualizar una selección para eludir revisión; usar scopes tenant como si fueran automáticos; mezclar auditorías o fingir que BE08 conoce adopciones. El diseño evita asumir resueltos esos puntos.

Único archivo creado: este diagnóstico/propuesta. **Cero cambios de código, migraciones, modelos, permisos, endpoints u OpenAPI de ejecución.** Cero pruebas ejecutadas; no hay validación PostgreSQL nueva que declarar. Se conservan cambios previos, DF-019 APROBADO 1.3, BE08, informes y snapshots. Sin datos migrados, Trello, commits o push.

Para continuar la parte esencial hacen falta dos ratificaciones del PO: **(1)** mecanismo/autoridad de clasificación explícita, tratamiento sin clasificar, alcance y correcciones; **(2)** autoridad clínica de revisión, provisión y operaciones bloqueadas. La recomendación es A con permiso SaaS separado, y revisión tenant clínica explícita con bloqueo de uso/aprobación dependiente hasta revisión favorable, sin que adoptar elimine la obligación. Todo sigue PROPUESTO.
