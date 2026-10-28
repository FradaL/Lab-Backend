<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ClinicalReasonReadBlocker', type: 'string', enum: ['impact_unclassified', 'clinical_review_pending', 'clinical_review_non_favorable', 'reason_inactive', 'clinical_reason_not_terminal'])]
#[OA\Schema(schema: 'ClinicalReasonSelectionStatus', type: 'object', required: ['can_select_for_request', 'request_blockers', 'pending_review_count', 'non_favorable_review_count', 'scope', 'informational'], properties: [
    new OA\Property(property: 'can_select_for_request', type: 'boolean', description: 'Sólo política de catálogo; no autoriza una solicitud sobre una línea ni valida suficiencia clínica.'),
    new OA\Property(property: 'request_blockers', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClinicalReasonReadBlocker')),
    new OA\Property(property: 'pending_review_count', type: 'integer'), new OA\Property(property: 'non_favorable_review_count', type: 'integer'),
    new OA\Property(property: 'scope', type: 'string', enum: ['catalog']), new OA\Property(property: 'informational', type: 'boolean', enum: [true]),
])]
#[OA\Schema(schema: 'ClinicalReasonSelectionContext', type: 'object', required: ['reason_reference', 'eligibility'], properties: [new OA\Property(property: 'reason_reference', allOf: [new OA\Schema(ref: '#/components/schemas/ClinicalExceptionReasonInput')], example: ['source' => 'local', 'reason_id' => 10, 'version_id' => 20]), new OA\Property(property: 'eligibility', ref: '#/components/schemas/ClinicalReasonSelectionStatus')])]
#[OA\Schema(schema: 'ClinicalReasonApprovalStatus', type: ['object', 'null'], properties: [
    new OA\Property(property: 'can_approve_from_catalog', type: 'boolean', description: 'Información sobre el motivo original de una pendiente; no reemplaza independencia, permisos, revisiones y controles BE08.'),
    new OA\Property(property: 'approval_blockers', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClinicalReasonReadBlocker')),
    new OA\Property(property: 'pending_review_count', type: 'integer'), new OA\Property(property: 'non_favorable_review_count', type: 'integer'),
    new OA\Property(property: 'scope', type: 'string', enum: ['original_request_catalog']), new OA\Property(property: 'informational', type: 'boolean', enum: [true]),
    new OA\Property(property: 'review_context', type: 'object', properties: [new OA\Property(property: 'source', type: 'string', enum: ['global', 'local']), new OA\Property(property: 'adoption_id', type: 'integer'), new OA\Property(property: 'reason_id', type: 'integer'), new OA\Property(property: 'exception_request_id', type: 'integer')]),
])]
#[OA\Schema(schema: 'ClinicalReasonSelectionVersion', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'number', type: 'integer'), new OA\Property(property: 'name', type: 'string', example: 'FICTICIO: motivo clínico'), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'classification', type: 'string', enum: ['incidence_only', 'terminal_candidate']), new OA\Property(property: 'evidence_requirements', ref: '#/components/schemas/LocalReasonEvidence'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'created_at', type: 'string', format: 'date-time')])]
#[OA\Schema(schema: 'ClinicalReasonSelectionItem', type: 'object', required: ['key', 'source', 'reason_id', 'reason_reference', 'publication_status', 'version', 'latest_version', 'eligibility', 'actions'], properties: [
    new OA\Property(property: 'key', type: 'string', example: 'local:10'), new OA\Property(property: 'source', type: 'string', enum: ['global', 'local']), new OA\Property(property: 'reason_id', type: 'integer'), new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'reason_reference', allOf: [new OA\Schema(ref: '#/components/schemas/ClinicalExceptionReasonInput')], example: ['source' => 'local', 'reason_id' => 10, 'version_id' => 20]), new OA\Property(property: 'publication_status', type: 'string', enum: ['published']),
    new OA\Property(property: 'version', ref: '#/components/schemas/ClinicalReasonSelectionVersion'),
    new OA\Property(property: 'latest_version', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'number', type: 'integer'), new OA\Property(property: 'is_active', type: 'boolean')]),
    new OA\Property(property: 'eligibility', ref: '#/components/schemas/ClinicalReasonSelectionStatus'),
    new OA\Property(property: 'actions', type: 'object', properties: [new OA\Property(property: 'can_select_for_request', type: 'boolean'), new OA\Property(property: 'can_publish_local', type: 'boolean'), new OA\Property(property: 'can_manage_local_activation', type: 'boolean'), new OA\Property(property: 'can_update_adoption', type: 'boolean'), new OA\Property(property: 'can_view_history', type: 'boolean', description: 'Historia local o de la adopción según origen; no concede global.view ni auditoría SaaS.'), new OA\Property(property: 'can_review_pending', type: 'boolean')]),
])]
#[OA\Schema(schema: 'ClinicalReasonSelectionPage', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClinicalReasonSelectionItem')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'laboratory_id', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer', enum: [50]), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'visible_sources', type: 'array', items: new OA\Items(type: 'string', enum: ['global', 'local'])), new OA\Property(property: 'eligibility_scope', type: 'string', enum: ['catalog']), new OA\Property(property: 'informational', type: 'boolean', enum: [true])])])]
class ClinicalReasonReadDocumentation
{
    #[OA\Get(path: '/api/v1/laboratory/clinical-reasons', operationId: 'clinicalReasonSelections', summary: 'Consultar motivos adoptados y locales con referencia lista para BE08',
        description: 'Requiere adoptions.view o local.view; cada origen se restringe al permiso correspondiente. Sin concesiones automáticas. Sólo versiones publicadas existentes. Filtrado y paginación SQL antes de serializar; no caché de elegibilidad. La política es informativa y BE08 la revalida al escribir. Revisiones locales de otras solicitudes no bloquean una solicitud nueva. Los booleanos de filtro son las cadenas true/false. No expone auditoría SaaS.',
        tags: ['Clinical Reason Selection'], security: [['sanctumCookie' => []]], x: ['tenant-permissions-any' => ['clinical_reasons.adoptions.view', 'clinical_reasons.local.view']],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'source', in: 'query', schema: new OA\Schema(type: 'string', enum: ['global', 'local'])),
            new OA\Parameter(name: 'search', in: 'query', description: 'Subcadena literal del nombre de la versión aplicable, sin distinguir mayúsculas; vacía equivale a omitir búsqueda, % y _ no son comodines.', schema: new OA\Schema(type: 'string', maxLength: 200)),
            new OA\Parameter(name: 'classification', in: 'query', schema: new OA\Schema(type: 'string', enum: ['incidence_only', 'terminal_candidate'])),
            new OA\Parameter(name: 'active', in: 'query', description: 'Activación de la versión aplicable; no equivale a elegibilidad.', schema: new OA\Schema(type: 'string', enum: ['true', 'false'])),
            new OA\Parameter(name: 'eligible', in: 'query', description: 'Elegibilidad de catálogo para solicitud nueva; no incluye el permiso clínico del actor ni contexto de línea.', schema: new OA\Schema(type: 'string', enum: ['true', 'false'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Motivos del ámbito y orígenes autorizados. reason_reference se copia íntegramente al POST BE08.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalReasonSelectionPage')),
            new OA\Response(response: 400, description: 'Falta X-Laboratory-ID'), new OA\Response(response: 401, description: 'Sesión requerida'),
            new OA\Response(response: 403, description: 'Permiso, cuenta, membresía, contexto o suscripción insuficientes; origen explícito no autorizado'), new OA\Response(response: 404, description: 'Laboratorio no disponible'),
            new OA\Response(response: 422, description: 'Filtro inválido o desconocido; page debe ser positivo')])]
    public function index(): void {}
}
