<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Commercial Client Price List Assignments',
    description: 'Programación de listas de precios para entidades comerciales y resolución de la lista efectiva.',
)]
#[OA\Schema(
    schema: 'CreateCommercialClientPriceListInput',
    required: ['price_list_id', 'starts_at'],
    properties: [
        new OA\Property(property: 'price_list_id', type: 'integer', format: 'int64', minimum: 1, example: 12),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date', example: '2026-01-01'),
        new OA\Property(property: 'ends_at', type: ['string', 'null'], format: 'date', example: '2026-12-31'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateCommercialClientPriceListInput',
    properties: [
        new OA\Property(property: 'price_list_id', type: 'integer', format: 'int64', minimum: 1, example: 12),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date', example: '2026-01-01'),
        new OA\Property(property: 'ends_at', type: ['string', 'null'], format: 'date', example: '2026-12-31'),
    ],
    type: 'object',
    minProperties: 1,
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateCommercialClientPriceListStatusInput',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'CommercialClientPriceList',
    required: ['id', 'commercial_client', 'price_list', 'starts_at', 'ends_at', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(
            property: 'commercial_client',
            required: ['id', 'name', 'type'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 8),
                new OA\Property(property: 'name', type: 'string', example: 'Seguros Ejemplo'),
                new OA\Property(property: 'type', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'price_list',
            required: ['id', 'name', 'currency'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Convenios 2026'),
                new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'GTQ'),
            ],
            type: 'object',
        ),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date', example: '2026-01-01'),
        new OA\Property(property: 'ends_at', type: ['string', 'null'], format: 'date', example: '2026-12-31'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-10-02T12:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-10-02T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CommercialClientPriceListResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/CommercialClientPriceList'),
    ],
    type: 'object',
)]
final class CommercialClientPriceListDocumentation
{
    // OpenAPI components for commercial client price-list assignments.
}
