<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Price Lists',
    description: 'Listas de precios pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'PriceList',
    required: ['id', 'name', 'description', 'currency', 'is_default', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 75, example: 'Lista General'),
        new OA\Property(property: 'description', type: ['string', 'null'], maxLength: 255, example: 'Precios generales del laboratorio.'),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, pattern: '^[A-Z]{3}$', example: 'GTQ'),
        new OA\Property(property: 'is_default', type: 'boolean', example: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-27T12:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-27T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ActivePriceList',
    required: ['id', 'name', 'currency', 'is_default'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 75, example: 'Lista General'),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, pattern: '^[A-Z]{3}$', example: 'GTQ'),
        new OA\Property(property: 'is_default', type: 'boolean', example: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ActivePriceListCollection',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ActivePriceList')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CreatePriceListInput',
    required: ['name', 'currency'],
    properties: [
        new OA\Property(property: 'name', description: 'Se persiste sin espacios exteriores y conserva su casing.', type: 'string', maxLength: 75, example: 'Lista General'),
        new OA\Property(property: 'description', description: 'Los valores vacíos o sólo con espacios se persisten como null.', type: ['string', 'null'], maxLength: 255, example: 'Tarifa general del laboratorio'),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, pattern: '^[A-Z]{3}$', example: 'GTQ'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdatePriceListInput',
    properties: [
        new OA\Property(property: 'name', description: 'Se persiste sin espacios exteriores y conserva su casing.', type: 'string', maxLength: 75, example: 'Lista Preferencial'),
        new OA\Property(property: 'description', description: 'Los valores vacíos o sólo con espacios se persisten como null.', type: ['string', 'null'], maxLength: 255, example: 'Tarifa preferencial del laboratorio'),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, pattern: '^[A-Z]{3}$', example: 'USD'),
    ],
    type: 'object',
    minProperties: 1,
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdatePriceListStatusInput',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'PriceListResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/PriceList'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PriceListCollection',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PriceList')),
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
final class PriceListDocumentation
{
    // OpenAPI components for Price List endpoints.
}
