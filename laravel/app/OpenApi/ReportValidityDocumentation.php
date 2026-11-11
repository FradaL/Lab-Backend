<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ReportValidityInput', type: 'object', additionalProperties: false,
    required: ['state', 'expected_revision', 'reason'], properties: [
        new OA\Property(property: 'state', type: 'string', enum: ['released', 'suspended', 'invalidated', 'superseded']),
        new OA\Property(property: 'expected_revision', type: 'integer', minimum: 0),
        new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000),
        new OA\Property(property: 'replacement_report_id', description: 'Required only when superseding; newer released report for the same order covering all replaced lines.', type: 'integer', minimum: 1),
    ])]
#[OA\Schema(schema: 'ReportValidityEvent', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'report_id', type: 'integer'),
    new OA\Property(property: 'revision', type: 'integer'), new OA\Property(property: 'state', type: 'string'),
    new OA\Property(property: 'replacement_report_id', type: ['integer', 'null']),
    new OA\Property(property: 'reason', type: 'string'), new OA\Property(property: 'actor_id', type: 'integer'),
    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
])]
final class ReportValidityDocumentation
{
    #[OA\Post(path: '/api/v1/laboratory-orders/{order}/reports/{report}/validity-events', operationId: 'changeReportValidity',
        summary: 'Requires reports.issue; release, suspend, invalidate or supersede an exact report', tags: ['Clinical Reports'],
        security: [['sanctumCookie' => []]], parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 16, maxLength: 100)),
        ], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReportValidityInput')),
        responses: [new OA\Response(response: 201, description: 'Immutable validity event; release requires current clinical and intact document evidence', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReportValidityEvent')])),
            new OA\Response(response: 403, description: 'Permission or tenant denied'), new OA\Response(response: 404, description: 'Local report unavailable'),
            new OA\Response(response: 409, description: 'Stale revision, invalid transition, or clinical/documentary conflict'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function change(): void {}

    #[OA\Get(path: '/api/v1/laboratory-orders/{order}/reports/{report}/validity-events', operationId: 'reportValidityHistory',
        summary: 'Requires reports.view_history; immutable validity history', tags: ['Clinical Reports'], security: [['sanctumCookie' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated report validity events'),
            new OA\Response(response: 403, description: 'Permission or tenant denied'), new OA\Response(response: 404, description: 'Local report unavailable')])]
    public function history(): void {}
}
