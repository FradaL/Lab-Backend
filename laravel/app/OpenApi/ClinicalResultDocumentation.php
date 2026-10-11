<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ClinicalResultsInput', type: 'object', required: ['revision', 'results'], additionalProperties: false,
    properties: [new OA\Property(property: 'revision', type: 'integer', minimum: 0, description: 'Optimistic revision of the entire line. Initial revision is zero.'),
        new OA\Property(property: 'comment', type: 'string', nullable: true, maxLength: 1000),
        new OA\Property(property: 'results', type: 'array', minItems: 1, maxItems: 100,
            items: new OA\Items(type: 'object', required: ['parameter_code', 'value'], additionalProperties: false,
                properties: [new OA\Property(property: 'parameter_code', type: 'string', maxLength: 60),
                    new OA\Property(property: 'value', nullable: true, description: 'Precise numeric decimal string or JSON integer (fractional JSON numbers are rejected); text; one exact published option code. Null clears an existing preliminary value. Omitted parameters remain unchanged.', oneOf: [new OA\Schema(type: 'string'), new OA\Schema(type: 'integer')])]))])]
#[OA\Schema(schema: 'ClinicalResultProgress', type: 'object', required: ['revision', 'status', 'clinical_status', 'required_count', 'captured_count', 'pending_count', 'complete', 'pending_parameters', 'validation_errors'], properties: [
    new OA\Property(property: 'revision', type: 'integer', minimum: 0),
    new OA\Property(property: 'status', type: 'string', enum: ['empty', 'partial', 'complete']),
    new OA\Property(property: 'clinical_status', type: 'string', enum: ['preliminary']),
    new OA\Property(property: 'required_count', type: 'integer'), new OA\Property(property: 'captured_count', type: 'integer'), new OA\Property(property: 'pending_count', type: 'integer'),
    new OA\Property(property: 'complete', type: 'boolean', description: 'Technical completeness only; never clinical validation.'),
    new OA\Property(property: 'pending_parameters', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'validation_errors', type: 'array', items: new OA\Items(type: 'object'), description: 'Invalid values are rejected atomically; persisted typed results satisfy the fixed published constraints.'),
])]
#[OA\Schema(schema: 'ClinicalResultsResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
    new OA\Property(property: 'laboratory_order_id', type: 'integer'), new OA\Property(property: 'laboratory_order_exam_id', type: 'integer'),
    new OA\Property(property: 'laboratory_exam_id', type: 'integer'), new OA\Property(property: 'exam_code', type: 'string'), new OA\Property(property: 'exam_name', type: 'string'),
    new OA\Property(property: 'clinical_version_id', type: ['integer', 'null']),
    new OA\Property(property: 'clinical_version_number', type: ['integer', 'null']), new OA\Property(property: 'configuration_hash', type: ['string', 'null']),
    new OA\Property(property: 'processing_started_at', type: ['string', 'null'], format: 'date-time'),
    new OA\Property(property: 'capture_available', type: 'boolean'), new OA\Property(property: 'blocking_reasons', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'progress', ref: '#/components/schemas/ClinicalResultProgress'),
    new OA\Property(property: 'permissions', type: 'object', properties: [new OA\Property(property: 'can_view', type: 'boolean'), new OA\Property(property: 'can_capture', type: 'boolean'), new OA\Property(property: 'can_edit', type: 'boolean'), new OA\Property(property: 'can_view_history', type: 'boolean')]),
    new OA\Property(property: 'parameters', type: 'array', description: 'Ordered fixed published parameters. Omitted from PUT responses without results.view.', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'configuration', ref: '#/components/schemas/ClinicalExamParameter'), new OA\Property(property: 'required', type: 'boolean'),
        new OA\Property(property: 'value', type: ['string', 'null'], description: 'Numeric values use canonical decimal strings.'),
        new OA\Property(property: 'unit', type: ['string', 'null']), new OA\Property(property: 'option_label', type: ['string', 'null']),
        new OA\Property(property: 'captured_by', type: ['integer', 'null']), new OA\Property(property: 'captured_at', type: ['string', 'null'], format: 'date-time'),
    ])),
])])]
#[OA\Schema(schema: 'ClinicalResultsHistoryResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'actor_id', type: 'integer'), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'before', ref: '#/components/schemas/ClinicalResultChange'), new OA\Property(property: 'after', ref: '#/components/schemas/ClinicalResultChange'),
        new OA\Property(property: 'context', type: 'object', properties: [new OA\Property(property: 'laboratory_order_id', type: 'integer'), new OA\Property(property: 'clinical_version_id', type: 'integer'), new OA\Property(property: 'comment', type: ['string', 'null'])]),
    ])), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer', enum: [50]), new OA\Property(property: 'total', type: 'integer')]),
])]
#[OA\Schema(schema: 'ClinicalResultChange', type: 'object', properties: [new OA\Property(property: 'revision', type: 'integer'),
    new OA\Property(property: 'results', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'parameter_code', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['numeric', 'text', 'qualitative', 'predefined_options']),
        new OA\Property(property: 'value', type: ['string', 'null']), new OA\Property(property: 'unit', type: ['string', 'null']), new OA\Property(property: 'option_label', type: ['string', 'null']),
    ])),
])]
final class ClinicalResultDocumentation {}
