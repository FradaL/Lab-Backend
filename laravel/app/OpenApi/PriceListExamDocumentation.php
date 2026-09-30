<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Exam Prices',
    description: 'Precios de exámenes configurados en una lista del laboratorio activo.',
)]
#[OA\Schema(
    schema: 'PriceListExamLaboratoryExam',
    required: ['id', 'code', 'name', 'laboratory_area', 'sample_type'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 123),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM-001'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Hematología completa'),
        new OA\Property(property: 'laboratory_area', ref: '#/components/schemas/LaboratoryExamAreaSummary'),
        new OA\Property(property: 'sample_type', ref: '#/components/schemas/LaboratoryExamSampleTypeSummary'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PriceListExamItem',
    required: ['id', 'laboratory_exam', 'price', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 45),
        new OA\Property(property: 'laboratory_exam', ref: '#/components/schemas/PriceListExamLaboratoryExam'),
        new OA\Property(property: 'price', description: 'Importe decimal serializado siempre con dos decimales.', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '125.50'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-29T12:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-29T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PriceListExamPriceListMetadata',
    required: ['id', 'name', 'currency'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 75, example: 'Lista General'),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, pattern: '^[A-Z]{3}$', example: 'GTQ'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpsertPriceListExamInput',
    required: ['price'],
    properties: [
        new OA\Property(
            property: 'price',
            description: 'Decimal monetario entre 0.00 y 9999999999.99, sin notación científica y con máximo dos decimales. Acepta string decimal o número JSON.',
            oneOf: [
                new OA\Schema(type: 'string', pattern: '^\\d+(?:\\.\\d{1,2})?$', example: '75.00'),
                new OA\Schema(type: 'number', format: 'decimal', minimum: 0, maximum: 9999999999.99, example: 75.5),
            ],
        ),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'BulkUpsertPriceListExamItemInput',
    required: ['laboratory_exam_id', 'price'],
    properties: [
        new OA\Property(property: 'laboratory_exam_id', type: 'integer', format: 'int64', minimum: 1, example: 10),
        new OA\Property(
            property: 'price',
            description: 'Decimal monetario entre 0.00 y 9999999999.99, sin notación científica y con máximo dos decimales.',
            oneOf: [
                new OA\Schema(type: 'string', pattern: '^\\d+(?:\\.\\d{1,2})?$', example: '75.00'),
                new OA\Schema(type: 'number', format: 'decimal', minimum: 0, maximum: 9999999999.99, example: 75.5),
            ],
        ),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'BulkUpsertPriceListExamInput',
    required: ['items'],
    properties: [
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/BulkUpsertPriceListExamItemInput'),
            minItems: 1,
            maxItems: 100,
        ),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'BulkUpsertPriceListExamMeta',
    required: ['created', 'updated', 'unchanged'],
    properties: [
        new OA\Property(property: 'created', type: 'integer', minimum: 0, example: 1),
        new OA\Property(property: 'updated', type: 'integer', minimum: 0, example: 1),
        new OA\Property(property: 'unchanged', type: 'integer', minimum: 0, example: 1),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'BulkUpsertPriceListExamResponse',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PriceListExamItem')),
        new OA\Property(property: 'meta', ref: '#/components/schemas/BulkUpsertPriceListExamMeta'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpdatePriceListExamStatusInput',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'PriceListExamResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/PriceListExamItem'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PriceListExamListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PriceListExamItem')),
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
            required: ['current_page', 'last_page', 'per_page', 'total', 'price_list'],
            properties: [
                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                new OA\Property(property: 'total', type: 'integer', example: 1),
                new OA\Property(property: 'price_list', ref: '#/components/schemas/PriceListExamPriceListMetadata'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AvailablePriceListExamItem',
    required: ['id', 'code', 'name', 'price', 'laboratory_area', 'sample_type'],
    properties: [
        new OA\Property(property: 'id', description: 'Identificador del examen de laboratorio.', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM001'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Hemograma completo'),
        new OA\Property(property: 'price', description: 'Precio unitario configurado, serializado con dos decimales.', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '75.00'),
        new OA\Property(property: 'laboratory_area', ref: '#/components/schemas/LaboratoryExamAreaSummary'),
        new OA\Property(property: 'sample_type', ref: '#/components/schemas/LaboratoryExamSampleTypeSummary'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'AvailablePriceListExamMetadata',
    required: ['current_page', 'last_page', 'per_page', 'total', 'currency'],
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', minimum: 1, example: 1),
        new OA\Property(property: 'last_page', type: 'integer', minimum: 1, example: 1),
        new OA\Property(property: 'per_page', type: 'integer', minimum: 1, maximum: 100, example: 15),
        new OA\Property(property: 'total', type: 'integer', minimum: 0, example: 1),
        new OA\Property(property: 'currency', description: 'Moneda de la lista de precios.', type: 'string', minLength: 3, maxLength: 3, pattern: '^[A-Z]{3}$', example: 'GTQ'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AvailablePriceListExamListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AvailablePriceListExamItem')),
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
        new OA\Property(property: 'meta', ref: '#/components/schemas/AvailablePriceListExamMetadata'),
    ],
    type: 'object',
)]
final class PriceListExamDocumentation
{
    // OpenAPI components for Exam Price endpoints.
}
