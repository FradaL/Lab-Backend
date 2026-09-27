<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Laboratory Exams',
    description: 'Exámenes pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'LaboratoryExamAreaSummary',
    required: ['id', 'code', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 4),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM'),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Hematología'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryExamSampleTypeSummary',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 8),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Sangre'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryExamListItem',
    required: [
        'id',
        'code',
        'name',
        'description',
        'turnaround_time_minutes',
        'status',
        'laboratory_area',
        'sample_type',
        'created_at',
        'updated_at',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 123),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM-001'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Hematología completa'),
        new OA\Property(property: 'description', type: ['string', 'null'], example: 'Hemograma completo.'),
        new OA\Property(property: 'turnaround_time_minutes', type: ['integer', 'null'], minimum: 0, example: 120),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'laboratory_area', ref: '#/components/schemas/LaboratoryExamAreaSummary'),
        new OA\Property(property: 'sample_type', ref: '#/components/schemas/LaboratoryExamSampleTypeSummary'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-26T12:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-26T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryExamListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LaboratoryExamListItem')),
        new OA\Property(
            property: 'links',
            required: ['first', 'last', 'prev', 'next'],
            properties: [
                new OA\Property(property: 'first', type: 'string', format: 'uri'),
                new OA\Property(property: 'last', type: 'string', format: 'uri'),
                new OA\Property(property: 'prev', type: ['string', 'null'], format: 'uri'),
                new OA\Property(property: 'next', type: ['string', 'null'], format: 'uri'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'meta',
            required: ['current_page', 'last_page', 'per_page', 'total'],
            properties: [
                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                new OA\Property(property: 'total', type: 'integer', example: 1),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class LaboratoryExamDocumentation
{
    // OpenAPI components for Laboratory Exam endpoints.
}
