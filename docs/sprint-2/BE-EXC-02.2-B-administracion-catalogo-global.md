# BE-EXC-02.2-B — Administración del catálogo global de motivos clínicos

Fecha: **2026-10-25**. Alcance: administración del proveedor; sin adopción por laboratorio, solicitudes, decisiones o resoluciones clínicas. Fuente de autorización de implementación: solicitud del usuario «DONQER LAB — BE-EXC-02.2-B», adjunto `34a0f3ff-cf3a-4882-b205-87d0adc43351`. No constituye modificación de decisiones funcionales.

## Diagnóstico y fuentes

Revisión de AGENTS y convenciones locales, [DF-019 APROBADO 1.3](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md), DF-016, plan PROPUESTO 1.3, AUD-002, [diagnóstico BE-EXC-02.2](BE-EXC-02.2-gobierno-global-auditoria-saas.md), [BE-EXC-02.1](BE-EXC-02.1-identidad-versionado-motivos-clinicos.md) y [BE-EXC-02.2-A](BE-EXC-02.2-A-frontera-autorizacion-saas.md). La solicitud de diseño BE-EXC-01 está disponible en el adjunto `0c2eab9e-58d2-4cdb-b66f-0447f80d2c0c`; no se localizó un informe técnico BE-EXC-01 independiente en los repositorios. No se presume su contenido ni aprobación de los contratos que proponía.

En el punto de partida existen identidades/versiones inmutables globales y locales, ClinicalReasonVersionStore, autenticación sesión/Sanctum y facultades SaaS con auditoría separada de laboratory_id. No había endpoints de gobierno global del catálogo. La auditoría SaaS sólo tenía referencias de usuario/facultad: se amplía de forma aditiva, conservando eventos anteriores. El grupo de middleware llamado saas sigue siendo tenant; no se utiliza para estas rutas.

DF-019 mantiene APROBADO 1.3. No se editan documentos del repositorio de producto ni se atribuye implementación clínica a su aprobación. Los cambios preexistentes de BE-EXC-02.1/02.2-A y producto se conservan; las modificaciones sobre sus archivos necesarias para esta entrega se enumeran debajo.

## Arquitectura reutilizada y facultades

GlobalClinicalReasonManager es la frontera administrativa nueva. Reutiliza SaasAccessAuthority, EnsureSaasCapability, SaasAccessManager para concesiones/revocaciones, SaasAuditWriter, los modelos globales y ClinicalReasonVersionStore::appendGlobal. No hay otro esquema de permisos, cuentas, seeder de catálogo o motor de versionado.

| Facultad exacta | Operaciones |
| --- | --- |
| saas.clinical_reasons.view | Listado, detalle, versiones y eventos del catálogo. |
| saas.clinical_reasons.create | Nueva identidad y su versión inicial en una transacción. |
| saas.clinical_reasons.publish | Nueva definición de una identidad existente; conserva is_active. |
| saas.clinical_reasons.manage | Cambio explícito de is_active conservando el resto de la definición. |

No hay implicaciones entre facultades: manage no sustituye view/create/publish. saas.access.manage administra concesiones, sin acceso funcional al catálogo. El bootstrap existente sigue concediendo exclusivamente saas.access.manage. Para operar el catálogo, un administrador de accesos debe conceder las facultades necesarias a otro usuario existente activo mediante las rutas BE-EXC-02.2-A; la prohibición de autoconcesión se mantiene. No se ejecutó ese procedimiento sobre datos reales.

Autenticación auth:sanctum, user.active y middleware saas.capability con facultad específica en cada ruta. Sin membresía, suscripción, laboratorio activo o X-Laboratory-ID requeridos. Owner/administrator no dan autoridad global. El middleware limpia CurrentLaboratory y contexto/relaciones Teams. El servicio revalida cuenta y concesión vigentes bajo el mismo bloqueo de autorización SaaS que las revocaciones y cambios de estado de usuarios; no confía en el middleware ni en el objeto User cacheado.

El historial `/platform/access/history` ahora filtra eventos con global_reason_id null: conserva los eventos de acceso y evita exponer operaciones del catálogo a quien sólo administra facultades. Sus eventos históricos no se modifican. El historial funcional requiere view en el recurso global.

## Persistencia y migración

Nueva migración: `laravel/database/migrations/2026_10_25_073106_extend_saas_audit_for_global_clinical_reasons.php`.

- Inserta las cuatro **definiciones** de facultad, sin conceder asignaciones.
- Añade a saas_audit_logs global_reason_id, global_reason_version_id y previous_global_reason_version_id, todos nullable para eventos previos/de accesos. No reescribe sus contenidos ni desactiva la inmutabilidad de auditoría.
- FK restrictiva a identidad y FK compuestas (reason_id,version_id), incluida la versión anterior: no se puede atribuir una versión a otra identidad. Añade la unicidad técnica (reason_id,id) requerida en global_clinical_reason_versions.
- Un evento de catálogo por versión, con índice parcial único; validación SQL de acción/facultad/actor/versión/procedencia y justificación. Creación referencia versión 1 y su creador; publicación/vigencia exigen predecesora inmediata. El evento de vigencia sólo puede acompañar un cambio de is_active y procedencia, no cambios de definición; publicar no puede cambiar is_active.
- Fechas de nuevas identidades/versiones globales generadas con statement_timestamp(), como la auditoría SaaS: una transacción que espera autorización no atribuye a la publicación una hora anterior al bloqueo. Fechas históricas intactas.

No se añaden tablas de catálogo, campos clínicos a BE08, adopciones, enlaces tenant o laboratorio ficticio. PostgreSQL es obligatorio para las operaciones del servicio.

La reversión se permite sólo sin historial de catálogo ni asignaciones de facultades clínicas; retira primero sus FK/triggers y definiciones. Si hay historia, falla y exige una restauración revisada explícitamente. La eliminación de las cuatro definiciones sin uso retira/reinstala temporalmente su trigger de inmutabilidad dentro de la transacción de migración PostgreSQL; no afecta auditoría. Las migraciones previas sólo pueden revertirse después de sus dependencias. No se ejecutaron migraciones en desarrollo/producción.

## Contratos HTTP

Prefijo `/api/v1/platform/clinical-reasons`. Toda respuesta es JSON; sin cabecera de laboratorio. Cada ruta está documentada en GlobalClinicalReasonDocumentation, incluida su facultad exacta (`x-saas-capability`), sesión, parámetros, schemas y errores.

| Método/ruta relativa | Facultad | Entrada y resultado |
| --- | --- | --- |
| GET / | view | page positivo opcional; data de identidades con latest_version, meta. |
| GET /{reason} | view | ID numérico; data de identidad y latest_version. |
| GET /{reason}/versions | view | page; versiones completas, por número descendente. |
| GET /{reason}/events | view | page; eventos administrativos del motivo, por ID descendente. |
| POST / | create | code y definition completa; 201 con identidad, versión 1 y referencias de auditoría. |
| POST /{reason}/versions | publish | expected_number y definition completa; 201 con versión sucesora. is_active debe coincidir con la versión actual. |
| POST /{reason}/activation | manage | expected_number, is_active boolean JSON y change_reason; 201 con nueva versión que conserva nombre/descripción/clase/evidencia. |

Paginación: 50 filas; meta.current_page/last_page/per_page/total. Identidades por ID ascendente. No hay filtros, selección/adopción, PATCH de versiones, borrado ni rutas alternativas de escritura. Las tres escrituras responden:

```json
{
  "data": {
    "reason": {"id": 1, "code": "motivo_ejemplo", "created_by": 2, "created_at": "..."},
    "version": {"id": 3, "reason_id": 1, "number": 2, "name": "...", "description": "...", "classification": "incidence_only", "evidence_requirements": {"schema_version": 1, "requirements": []}, "is_active": false, "change_reason": "Justificación administrativa", "created_by": 4, "created_at": "..."},
    "audit_id": 10,
    "operation_id": "UUID"
  }
}
```

ID y fechas del ejemplo son ilustrativos. latest_version sólo se carga en lecturas de identidades, no se exige en la identidad de la respuesta de escritura. Actor, IDs de versión, numeración y fecha proceden del backend/base.

### Validaciones y conflictos

code canónico `[a-z][a-z0-9_]{0,49}`, máximo 50 y único global. definition obligatoria: name no blanco hasta 200, description no blanca, classification incidence_only o terminal_candidate, evidence_requirements, is_active boolean JSON y change_reason no blanco. Es la misma estructura de BE-EXC-02.1; no se admite cambiar code/actor/fecha/número mediante la definición ni campos desconocidos en las escrituras.

Evidencia: objeto con exactamente schema_version entero 1 y requirements lista de identificadores canónicos únicos de hasta 100 caracteres. Una lista vacía es estructuralmente válida. No hay carga automática de EXC-M01–M07 ni clasificación de esos motivos por inferencia. La validación estructural del catálogo no acredita suficiencia clínica ni permite omitir los mínimos de DF-019: esos controles pertenecen a los consumidores clínicos posteriores.

expected_number es entero JSON >=1. Concesión create sólo genera versión 1; publish no puede inicializar una identidad vacía creada por código interno. Vigencia sin cambio retorna conflicto, sin versión/evento redundantes. Publicar una definición nueva no concede autoridad clínica ni resuelve exámenes.

| Código HTTP | Condición |
| --- | --- |
| 401 | Sin autenticación. |
| 403 | Sin facultad exacta o cuenta inactiva. Incluye roles tenant y administrador de accesos sin facultad funcional. |
| 404 | Motivo inexistente para actor autorizado. La autorización ocurre antes de buscar el recurso. |
| 409 | global_clinical_reason_code_conflict, clinical_reason_version_conflict o global_clinical_reason_activation_unchanged. |
| 422 | Entrada inválida, claves no documentadas, versión con tipo incorrecto o cambio de vigencia por publish. |
| 419 | Protección CSRF habitual de peticiones de sesión cuando corresponda. |

No se devuelven excepciones SQL internas como contrato de conflicto. Los duplicados concurrentes a través del servicio se serializan y obtienen el mismo 409. Errores inesperados conservan el manejo estándar; no se incluye payload clínico, credenciales ni tokens en los eventos.

## Versionado, vigencia y consumidores futuros

La identidad/código permanece estable. Todas las publicaciones usan el store existente, bloqueo FOR UPDATE del padre, comparación del número esperado y sucesora contigua. Restricciones SQL de BE-EXC-02.1 protegen también inmutabilidad y secuencia en accesos directos. No se modifica ni borra una versión. Se conserva íntegra la definición de todas las anteriores.

is_active pertenece a una definición versionada. La última versión define la vigencia administrativa global actual; versiones anteriores conservan su propio is_active histórico. Una activación/desactivación crea una definición sucesora, con nuevo actor/fecha/justificación. **Que una versión anterior diga is_active=true no acredita vigencia administrativa actual.** La operación no reescribe versiones locales ni solicitudes, ni implica adopción.

La infraestructura permite reconstruir cada cambio y su causa sin un nuevo estado clínico ni retroactividad. No se añade un booleano manual “restricción clínica relevante” ni se inventa un algoritmo clínico para decidirlo. La determinación exhaustiva de relevancia sigue pendiente para el diseño del consumidor; no se aplica propagación ni efectos clínicos en esta entrega.

Dependencias explícitas para BE-EXC-02.3 y solicitudes:

1. Guardar referencia a identidad global y versión adoptada/original, sin copiar automáticamente futuras definiciones sobre ella.
2. Consultar la última versión para vigencia actual y **todas** las versiones posteriores a la adoptada/evaluada, con sus eventos/justificaciones. Comparar sólo adoptada contra última puede perder una desactivación o reclasificación intermedia luego revertida.
3. Detectar diferencias de clasificación, requisitos, vigencia y definiciones; diseñar el criterio de relevancia clínica conforme a DF-019. Un criterio adicional no aprobado se elevará al PO antes de aplicarlo. No permitir que una adopción antigua sirva como prueba suficiente de elegibilidad vigente.
4. Aplicar I.A y EXC-13 al construir solicitudes/revisiones: desactivación impide nuevas solicitudes; las pendientes conservan su historia y requieren revisión explícita de la causa antes de aprobar. Clasificación/restricción relevante exige revisar dependencias, sin aprobación, rechazo o cierre automáticos.
5. Serializar en el consumidor la comprobación de dependencias globales y la decisión clínica para evitar que una publicación/revocación concurrente deje una aprobación con evidencia obsoleta. Debe definir bloqueos/revisión esperada compatibles con este servicio, sin invertir el orden de locks.

Estos puntos son requisitos de integración futura, **no comportamiento implementado en solicitudes/adopción**. No se declara que BE08 ya detecte restricciones globales: sus solicitudes genéricas siguen usando su contrato anterior. No se inventan reglas clínicas para cerrar esa brecha fuera de alcance.

## Auditoría y concurrencia

El escritor SaaS existente incorpora recordGlobalReason. Eventos: saas.clinical_reasons.created, saas.clinical_reasons.published y saas.clinical_reasons.activation_changed. Actor humano autenticado, facultad, identidad, versión, predecesora, operation_id UUID, acción, fecha servidor, justificación y succeeded. old_values/new_values contienen referencias/número/vigencia; las definiciones completas se reconstruyen por FK a versiones inmutables. Sin laboratorio ni pacientes.

Identidad, versión y evento se escriben en una misma transacción. Si falla el writer, su evento o una restricción, se revierte todo; una operación rechazada no deja identidad vacía ni evento exitoso. No se amplía la política de registro de todos los intentos denegados: la bitácora funcional sólo registra operaciones exitosas confirmadas, igual que BE-EXC-02.2-A.

Orden de bloqueo: transacción PostgreSQL → advisory lock SaaS `(182045,1)` → autorización fresca → identidad/versiones. El mismo lock ya protege grants, revocaciones y desactivación de cuentas; las operaciones concurrentes quedan ordenadas. Una revocación confirmada primero impide la siguiente publicación; una publicación que obtiene el lock primero confirma con su auditoría y después puede revocarse la facultad. Dos publicaciones con igual expected_number sólo confirman una; crear el mismo code sólo confirma una identidad. Toda vigencia exige nueva revisión y vuelve obsoleto el número anterior.

Coste: las operaciones administrativas SaaS, incluidas lecturas, se serializan globalmente. Es un mecanismo conservador reutilizado de A, no un diseño de alto volumen de lectura. El store interno continúa siendo una primitiva sin frontera SaaS ni auditoría automática: consumidores de producción deben pasar por el manager administrativo. El SQL no sustituye a la identidad autenticada de aplicación ni protege frente al propietario de base que desactive triggers.

## Archivos de esta entrega

Nuevos:

- app/ClinicalExams/GlobalClinicalReasonManager.php.
- app/Http/Controllers/Api/V1/GlobalClinicalReasonController.php.
- app/OpenApi/GlobalClinicalReasonDocumentation.php.
- database/migrations/2026_10_25_073106_extend_saas_audit_for_global_clinical_reasons.php.
- tests/Feature/ClinicalReasons/GlobalClinicalReasonAdministrationTest.php y GlobalClinicalReasonAdministrationConcurrencyTest.php.
- Este documento.

Modificados dentro de laravel:

- app/Audit/SaasAuditWriter.php y app/Models/SaasAuditLog.php: referencias/evento de catálogo en infraestructura existente.
- app/UserAdministration/SaasAccessManager.php: separación del historial de accesos respecto del historial funcional.
- routes/api.php: siete rutas globales.
- tests/Feature/SaasAccess/SaasAccessTest.php: distingue definiciones disponibles de facultades realmente concedidas por bootstrap; utiliza una facultad inexistente para comprobar validación.
- tests/Feature/ClinicalReasons/ClinicalReasonPersistenceTest.php: rollback en orden inverso de dependencias antes de revertir la migración de identidades.
- Doce pruebas existentes de CommercialClients, LaboratoryOrders y Pricing: sólo conteos de inventario por siete operaciones nuevas, 150 operaciones OpenAPI y 158 rutas en el bootstrap de tests. Permisos tenant sin cambios.

No cambia ClinicalReasonVersionStore, tablas/contratos clínicos BE08, snapshots, informes ni frontend. No se implementan BE-EXC-02.3–02.6.

## Pruebas y estado

Entorno aislado Compose `donqer-be-exc-022b`, deploy/testing/compose.yaml: PostgreSQL 17 en tmpfs, red interna sin puertos, aplicación/configuración de testing en lectura y TestDatabaseGuard. Nunca se utilizó la base de desarrollo/producción.

Las pruebas nuevas cubren autenticación en siete rutas, owner/administrator denegados, matriz de facultades en rutas/servicios, operadores sin laboratorio, revocación/cuenta inactiva, creación/publicación/vigencia/reactivación, invariancia de historia, falta de propagación tenant, validación/inyección de campos, conflictos, FK/inmutabilidad SQL, rollback por fallo de auditoría en cada escritura y OpenAPI. Cinco carreras independientes acreditan espera real de PostgreSQL mediante pg_stat_activity: creación duplicada, publicación duplicada, vigencia antes de publicación, revocación antes de publicación y publicación antes de revocación.

Regresión dirigida ejecutada en PostgreSQL real: **285 pruebas, 1.750 aserciones, sin fallos**, incluyendo los 35 casos nuevos (cinco de concurrencia). Directorios ejecutados: tests/Feature/ClinicalReasons, tests/Feature/SaasAccess, tests/Feature/Api/V1/Auth, tests/Feature/Authorization, tests/Feature/Saas y tests/Feature/ClinicalFinalization. Incluye BE-EXC-02.1, BE-EXC-02.2-A y BE-S2-08.

Inventarios de contratos/rutas/OpenAPI: **190 pruebas, 1.710 aserciones, sin fallos**, ejecutadas en tests/Feature con el filtro `(contract|openapi|route)`; verifican 150 operaciones OpenAPI y 158 rutas en el bootstrap de tests. Pint sin incidencias; git diff --check correcto. No se ejecutó la suite completa. Las ejecuciones dirigidas y de contratos pueden solaparse: no se suman como pruebas únicas.

## Riesgos y decisiones pendientes

- Integración BE-EXC-02.3 y solicitudes: falta criterio técnico exhaustivo de relevancia clínica y control de dependencias vigente en el momento de solicitar/revisar/aprobar. Se conserva toda la evidencia necesaria; no se finge implementar ese control mediante adopción automática ni se inventan consecuencias clínicas nuevas.
- No se crea/carga catálogo base; la definición administrativa de cada motivo debe respetar EXC-M01–M07. La estructura de evidencia no certifica política clínica suficiente.
- Operación/despliegue: migración nueva y concesiones explícitas pendientes de ejecución autorizada; no hay nuevos usuarios ni facultades funcionales implícitas. Preproducción no autoriza descartar historia.
- Concurrencia: serialización global y orden de locks documentados. Un consumidor futuro con bloqueos previos de filas debe integrar ese orden antes de llamar al servicio.

Sin cambios en DF-019, Trello, commits o push.
