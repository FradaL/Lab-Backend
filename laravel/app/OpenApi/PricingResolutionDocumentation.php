<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResolvePriceListInput',
    required: ['commercial_client_id', 'effective_date'],
    properties: [
        new OA\Property(
            property: 'commercial_client_id',
            type: ['integer', 'null'],
            format: 'int64',
            minimum: 1,
            example: 123,
        ),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', example: '2026-10-03'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'PriceListResolutionCommercialClient',
    required: ['id', 'name', 'type'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 123),
        new OA\Property(property: 'name', type: 'string', example: 'Seguros XYZ'),
        new OA\Property(property: 'type', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'ResolvedPriceList',
    required: ['id', 'name', 'currency', 'is_default'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Convenio Seguros XYZ 2026'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'GTQ'),
        new OA\Property(property: 'is_default', type: 'boolean', example: false),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'EffectivePriceListAssignment',
    required: ['id', 'starts_at', 'ends_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 18),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date', example: '2026-01-01'),
        new OA\Property(property: 'ends_at', type: ['string', 'null'], format: 'date', example: '2026-12-31'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'PriceListResolution',
    required: ['context', 'commercial_client', 'effective_date', 'resolved', 'reason', 'price_list', 'assignment'],
    properties: [
        new OA\Property(property: 'context', type: 'string', enum: ['particular', 'commercial_client'], example: 'commercial_client'),
        new OA\Property(property: 'commercial_client', oneOf: [
            new OA\Schema(ref: '#/components/schemas/PriceListResolutionCommercialClient'),
            new OA\Schema(type: 'null'),
        ]),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', example: '2026-10-03'),
        new OA\Property(property: 'resolved', type: 'boolean', example: true),
        new OA\Property(
            property: 'reason',
            type: ['string', 'null'],
            enum: ['NO_DEFAULT_PRICE_LIST', 'NO_EFFECTIVE_PRICE_LIST', 'ASSIGNED_PRICE_LIST_INACTIVE', null],
            example: null,
        ),
        new OA\Property(property: 'price_list', oneOf: [
            new OA\Schema(ref: '#/components/schemas/ResolvedPriceList'),
            new OA\Schema(type: 'null'),
        ]),
        new OA\Property(property: 'assignment', oneOf: [
            new OA\Schema(ref: '#/components/schemas/EffectivePriceListAssignment'),
            new OA\Schema(type: 'null'),
        ]),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'PriceListResolutionResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/PriceListResolution'),
    ],
    type: 'object',
    additionalProperties: false,
)]
final class PricingResolutionDocumentation
{
    // OpenAPI components for effective price-list resolution.
}
