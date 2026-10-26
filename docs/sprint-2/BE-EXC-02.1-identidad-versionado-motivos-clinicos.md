# BE-EXC-02.1 — Identidad y versionado de motivos clínicos

Fecha: **2026-10-25**. Entregable limitado a persistencia interna. Fuente funcional: [DF-019 APROBADO 1.3](../../../donqer-lab-product/03-decisiones/DF-019-excepciones-clinicas-v1.md). Fuente técnica: [plan PROPUESTO 1.3](../../../donqer-lab-product/06-especificaciones/plan-excepciones-clinicas-v1.md) e informe BE-EXC-01 disponible en la conversación; no se localizó un archivo independiente del informe en los repositorios. DF-016 conserva su alcance. Esta entrega no aprueba ni implementa los contratos públicos propuestos.

## Diagnóstico y compatibilidad

Antes de implementar se revisaron AGENTS, DF-019 completo, DF-016, AUD-002, plan, propuesta BE-EXC-01 y código/documentación BE05/08/09.1. BE08 guarda `clinical_exception_requests.reason` libre; su decisión independiente crea `authorized_exception`, y el cierre es explícito. El catálogo de motivos no existía en el árbol de partida. No se sustituye ese circuito.

`report_line_snapshot()` usa `to_jsonb()` sobre solicitudes, decisiones y resoluciones completas; los triggers comparan snapshots exactos y el formato está limitado a `donqer-clinical-1`. Por ello esta entrega crea únicamente tablas nuevas, sin añadir columnas/FK a esas filas ni cambiar su función de snapshot, renderer o contratos. No se detectó una incompatibilidad que requiera detener esta infraestructura independiente antes de migrar. La integración clínica posterior sí necesita diseño y regresión propios.

Donqer está en preproducción. No se crea compatibilidad comercial para clientes anteriores, no se convierten solicitudes ni se borran datos de desarrollo. Las migraciones y pruebas se ejecutan exclusivamente en PostgreSQL temporal aislado, nunca usando `.env` de desarrollo. Los cambios documentales previos del repositorio de producto quedan intactos.

## Esquema implementado

Migración: `laravel/database/migrations/2026_10_25_062239_create_clinical_reason_identities_and_versions.php`.

| Tabla | Responsabilidad |
| --- | --- |
| `global_clinical_reasons` | Identidad global administrable posteriormente por Donqer. |
| `global_clinical_reason_versions` | Definiciones globales completas, numeradas e inmutables. |
| `laboratory_clinical_reasons` | Identidad de motivo personalizado propio de un laboratorio. No representa selección de un motivo global. |
| `laboratory_clinical_reason_versions` | Definiciones locales inmutables con pertenencia comprobada por FK compuestas. |

Se eligen cuatro tablas, en vez de una identidad con `laboratory_id` nullable: separan ámbito global y propiedad tenant y evitan que una FK compuesta nullable omita controles de pertenencia. No hay tabla de selección/adopción ni copia automática de globales a laboratorios.

### Identidad

- `id`: bigint generado, PK; identidad técnica independiente del nombre.
- `code`: varchar(50), estable y canónico, expresión `[a-z][a-z0-9_]{0,49}`; único globalmente o por laboratorio según tabla.
- `created_by`: bigint, FK a usuario real, sin borrado en cascada.
- `created_at`: timestamptz(6), generado por PostgreSQL.
- Sólo locales: `laboratory_id` obligatorio, FK a laboratorio y FK compuesta de actor a `laboratory_user`.

Código, pertenencia y procedencia no se renombran ni se borran. Cambiar nombre visible crea otra versión de la misma identidad. Identidades globales y locales usan espacios independientes; dos laboratorios pueden usar el mismo código sin compartir identidad.

### Versión

- `id`, `reason_id`: bigint y FK a identidad.
- `number`: bigint positivo, único por motivo; secuencia contigua desde 1, serializada bajo bloqueo del padre.
- `name`: varchar(200), obligatorio y no blanco.
- `description`: text, obligatoria y no blanca.
- `classification`: `incidence_only` o `terminal_candidate`; nombres técnicos internos para las dos clases funcionales. La segunda sólo expresa candidatura condicionada, nunca aprobación o elegibilidad automática.
- `evidence_requirements`: JSONB de forma versionada, descrita debajo.
- `is_active`: boolean obligatorio, vigencia de esa definición; no muta sobre una versión existente.
- `change_reason`: text no blanco; justifica creación/revisión y prepara la conservación de causa requerida por I.A, sin implementar todavía su revisión clínica.
- `created_by`, `created_at`: usuario real y timestamptz(6) generado por backend/base.
- Sólo locales: `laboratory_id` obligatorio y FK compuestas a identidad y membresía del actor.

No se añade puntero mutable `current_version_id`: `latestVersion()` deriva la última definición por `number`. Esto no selecciona ni adopta una versión global para un laboratorio. El consumidor futuro deberá distinguir última versión global y versión explícitamente adoptada.

`change_reason` y la forma del JSON son elecciones técnicas del almacenamiento. No son permisos nuevos, requisitos clínicos adicionales ni contratos públicos aprobados.

### Requisitos de evidencia

```json
{
  "schema_version": 1,
  "requirements": ["problem_description", "replacement_assessment"]
}
```

El ejemplo es estructural. Se valida objeto con esas dos claves, versión numérica 1 y lista de identificadores canónicos únicos de hasta 100 caracteres. No se permite lista de objetos/booleanos, claves desconocidas o identificadores vacíos. Una lista vacía es estructuralmente válida: este almacenamiento no determina qué requisitos clínicos corresponden a cada motivo. La política obligatoria M01–M07 debe aplicarse en los siguientes entregables; no se puede usar esa lista para omitir restricciones funcionales.

No se cargan los siete motivos, no se ejecutan seeders y no se infiere una clasificación concreta a partir de texto libre. El posterior catálogo base debe respetar las condiciones completas de DF-019, incluida solicitud M03 sin evaluación adicional de reposición.

## Modelos y servicio interno

- `GlobalClinicalReason` / `LaboratoryClinicalReason`: `versions()`, `latestVersion()`, `createdBy()`; el local reutiliza `BelongsToLaboratory`.
- `GlobalClinicalReasonVersion` / `LaboratoryClinicalReasonVersion`: `reason()`, `createdBy()`, casts de JSON, booleano, número y fecha; el local también tiene scope/relación de laboratorio.
- `ImmutableClinicalReasonRecord`: Eloquent deniega actualización/borrado; SQL conserva la protección al usar query builder o consultas directas.
- `ClinicalReasonVersionStore::appendGlobal()` / `appendLocal()`: validan definición, bloquean identidad con `FOR UPDATE`, comparan número esperado, calculan sucesora e insertan en transacción. Refrescan la fila para devolver fecha/defaults y JSONB realmente persistidos.

`expectedNumber=0` permite la primera versión; una revisión obsoleta produce el conflicto interno `clinical_reason_version_conflict`. No es un nuevo error de API pública. No se acepta inyección de actor, número, laboratorio o campos desconocidos en la definición.

El store es una primitiva de persistencia, **no una frontera de autorización**. No concede autoridad global ni permisos owner; sus llamadores futuros deberán verificar actor activo y facultades efectivas bajo bloqueo. El método local busca exclusivamente la identidad del laboratorio recibido; las FK rechazan actores o identidades de otro tenant. Como los scopes no son filtros globales automáticos, los consumidores deberán seleccionar siempre el contexto explícito.

No hay CRUD administrativo completo ni endpoints; los modelos sólo permiten inserciones de identidades/versiones. Tampoco hay activación por actualización: la definición posterior puede reflejar nueva vigencia conservando la anterior.

## Restricciones PostgreSQL

- Unicidad de código por ámbito y de `(reason_id, number)`.
- Identidades locales únicas `(laboratory_id,id)` y versiones `(laboratory_id,reason_id,id)` para futuras referencias seguras.
- FK compuestas laboratorio/identidad y laboratorio/actor; actor global con FK a users. `RESTRICT` conserva referencias.
- CHECK de códigos, textos, número, clasificación y forma de requisitos; función inmutable `clinical_reason_evidence_valid` valida el JSON sin depender de Laravel.
- Trigger de inserción `serialize_clinical_reason_version`: bloquea identidad y exige el siguiente número incluso ante SQL directo.
- Triggers `protect_*`: rechazan UPDATE/DELETE de identidades y versiones, incluidos cambios de laboratorio/actor o reclasificación in situ.
- Funciones `CREATE OR REPLACE` para repetición de `migrate:fresh`, que no elimina funciones del esquema.
- `down()` sólo retira las estructuras si están vacías; rechaza historia existente y exige estrategia explícita de respaldo/restauración. No toca tablas BE08/informes.

Las restricciones avanzadas requieren PostgreSQL. No se promete equivalencia SQLite ni autorización contra un administrador capaz de desactivar triggers. La procedencia actor/fecha es parte de la evidencia inmutable; la auditoría completa de gobierno global/local pertenece a BE-EXC-02.2. `AuditWriter` actual exige laboratorio: no se ha creado un tenant ficticio ni debilitado ese contrato.

## Verificación

Entorno: Compose `donqer-be-exc-021`, configuración `deploy/testing/compose.yaml`, PostgreSQL 17 en tmpfs, red interna sin puertos y código/configuración de testing montados en lectura. `TestDatabaseGuard` comprueba configuración e identidad real de servidor antes de operar.

Pruebas nuevas en `tests/Feature/ClinicalReasons`:

- Identidad estable, versiones nuevas y conservación exacta de anteriores.
- Nombre/clasificación/vigencia nuevos sin modificar identidad ni evidencias previas.
- Unicidad de códigos/versiones y rechazo de números saltados.
- SQL directo: inmutabilidad, pertenencia, FK de actores y documentos inválidos.
- Scopes tenant, búsqueda local ajena, relaciones Eloquent y conflicto de versión esperada.
- Rollback vacío/reaplicación y rechazo de down con historia.
- Cuatro carreras: global/local y llamador servicio/SQL. Procesos/conexiones independientes; `pg_stat_activity` acredita espera real. Un escritor gana y el otro no duplica la versión.

Resultados finales ejecutados: **PHPUnit 12.5.35, PHP 8.4.25 y PostgreSQL 17.11**.

| Batería | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| ClinicalReasons (incluye cuatro carreras reales) | 42 | 120 | Sin errores, fallos u omisiones |
| ClinicalFinalization + ClinicalReports + PhysicalSamples | 155 | 1.024 | Sin errores, fallos u omisiones |
| Total dirigido | 197 | 1.144 | Satisfactorio |

Duración: nueva batería final 7,287 s; regresión 4 min 31,505 s. Se ejecutaron las suites aplicables, no la suite completa del repositorio. Pint pasó para los diez archivos PHP nuevos; sintaxis PHP y comprobación de whitespace satisfactorias. Los únicos cambios backend son once archivos nuevos, incluido este documento; BE08, rutas, RBAC y snapshots existentes no se modifican.

Logs: `/tmp/be-exc-021-final.log` y `/tmp/be-exc-021-regression.log`. Las ejecuciones iniciales detectaron funciones no reemplazables al repetir migrate:fresh y una comparación de valores Eloquent previos al refresco de defaults/JSONB. Ambos puntos se corrigieron; las cifras de la tabla pertenecen a las ejecuciones satisfactorias finales.

Comandos reproducibles, exclusivamente para entorno temporal:

```bash
docker compose -p donqer-be-exc-021 -f deploy/testing/compose.yaml up -d postgres-testing
docker compose -p donqer-be-exc-021 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalReasons
docker compose -p donqer-be-exc-021 -f deploy/testing/compose.yaml run --rm tests php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ClinicalFinalization tests/Feature/ClinicalReports tests/Feature/PhysicalSamples
docker compose -p donqer-be-exc-021 -f deploy/testing/compose.yaml down --remove-orphans
```

El proyecto temporal se retira al terminar; no se migran ni se ejecutan seeders en desarrollo/producción.

## Dependencias para BE-EXC-02.2 y contratos pendientes

El siguiente entregable debe definir autorización y auditoría SaaS independientes, reutilizar estas identidades/versiones y asegurar escritura+auditoría atómicas. No exponer el store directamente desde HTTP. Revisar el mecanismo de identidad global operadora y permisos; no se presupone un rol SaaS implementado.

Después se diseñarán selección individual/adopción explícita, diferencias global/local, CRUD local, lectura API y su RBAC; estas tablas locales son sólo motivos propios. La integración con BE-EXC-03/04 requiere motivo/versión original, evidencia mínima, detección de cambios relevantes, revisiones y bloqueos de aprobación, sin modificar el circuito BE08 por esta entrega. Payloads/rutas, permisos, selección y política de impacto global siguen pendientes de revisión técnica.

BE-S2-08 conserva `reason`, aprobación/rechazo, revisiones y cierre. No se añaden columnas a snapshots ni nuevos estados de procesamiento; no se crean solicitudes estructuradas, viabilidad, revisiones clínicas, frontend o informes. No se inicia BE-EXC-02.2, no se modifica Trello y no se realizan commits/push.
