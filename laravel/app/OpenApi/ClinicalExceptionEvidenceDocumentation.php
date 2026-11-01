<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ClinicalIncidence', type: 'object', required: ['status', 'processing_started', 'evidence_ids', 'intention_ids'], properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['none', 'pending', 'resolved'], description: 'Separate clinical situation; pending may be displayed as Incompleto, clarifying processing_started. No realizado requires an authorized resolution.'),
    new OA\Property(property: 'processing_started', type: 'boolean'),
    new OA\Property(property: 'evidence_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'intention_ids', type: 'array', items: new OA\Items(type: 'integer')),
])]
#[OA\Schema(schema: 'ClinicalExceptionEligibility', type: ['object', 'null'], description: 'Current advisory eligibility for a pending request and authenticated viewer. GET never reserves approval; POST revalidates all dependencies under transaction locks.', properties: [
    new OA\Property(property: 'eligible', type: 'boolean'),
    new OA\Property(property: 'blocking_reasons', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'missing_evidence', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'requires_review', type: 'boolean'),
    new OA\Property(property: 'obsolete_assessment_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'pending_intention_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'evidence_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'assessment_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'dependency_token', type: 'string', minLength: 64, maxLength: 64),
    new OA\Property(property: 'review_id', type: ['integer', 'null']),
])]
#[OA\Schema(schema: 'ClinicalExceptionEvidenceReviewInput', type: 'object', required: ['clinical_revision', 'request_revision', 'dependency_token', 'justification'], additionalProperties: false, properties: [
    new OA\Property(property: 'clinical_revision', type: 'integer', minimum: 0),
    new OA\Property(property: 'request_revision', type: 'integer', minimum: 0),
    new OA\Property(property: 'dependency_token', type: 'string', minLength: 64, maxLength: 64, description: 'Token from the current exception clinical_eligibility; not the BE03 assessment token.'),
    new OA\Property(property: 'justification', type: 'string', maxLength: 2000),
])]
final class ClinicalExceptionEvidenceDocumentation
{
    #[OA\Post(path: '/api/v1/laboratory-orders/{order}/exams/{line}/exceptions/{exception}/review', operationId: 'be04reviewClinicalExceptionEvidence', summary: 'Review changed clinical dependencies; requires orders.clinical_replacements.evaluate', tags: ['Clinical Finalization'], security: [['sanctumCookie' => []]], parameters: [
        new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'exception', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExceptionEvidenceReviewInput')), responses: [
        new OA\Response(response: 200, description: 'Immutable review stored. No approval, resolution, processing, finalization or catalogue review is implied.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'clinical_revision', type: 'integer'), new OA\Property(property: 'evidence_review_id', type: 'integer'), new OA\Property(property: 'clinical_eligibility', ref: '#/components/schemas/ClinicalExceptionEligibility')])])),
        new OA\Response(response: 401, description: 'Authentication required'),
        new OA\Response(response: 403, description: 'Explicit clinical evaluation permission and active tenant membership required'),
        new OA\Response(response: 404, description: 'Order, line or request outside current tenant scope'),
        new OA\Response(response: 409, description: 'clinical_exception_dependencies_conflict, clinical_exception_revision_conflict, clinical_viability_obsolete or current clinical blockers; no history inserted'),
        new OA\Response(response: 422, description: 'Invalid input, missing/blank justification, unknown fields or noninteger revisions'),
    ])]
    public function review(): void {}
}
