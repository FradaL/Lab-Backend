<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Commercial Clients',
    description: 'Entidades comerciales pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'CommercialClient',
    required: ['id', 'name', 'type', 'tax_id', 'phone', 'email', 'address', 'notes', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Entidad Comercial Uno'),
        new OA\Property(property: 'type', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
        new OA\Property(property: 'tax_id', type: ['string', 'null'], maxLength: 50, example: '1234567-8'),
        new OA\Property(property: 'phone', type: ['string', 'null'], maxLength: 30, example: '+502 2222-3333'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', maxLength: 150, example: 'contacto@example.com'),
        new OA\Property(property: 'address', type: ['string', 'null'], maxLength: 255, example: 'Ciudad de Guatemala'),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Observación administrativa.'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-10-02T12:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-10-02T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CreateCommercialClientInput',
    required: ['name', 'type'],
    properties: [
        new OA\Property(property: 'name', description: 'Se persiste sin espacios exteriores y conserva su casing.', type: 'string', maxLength: 150, example: 'Seguros Ejemplo'),
        new OA\Property(property: 'type', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
        new OA\Property(property: 'tax_id', type: ['string', 'null'], maxLength: 50, example: '1234567-8'),
        new OA\Property(property: 'phone', type: ['string', 'null'], maxLength: 30, example: '+502 2222-3333'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', maxLength: 150, example: 'contacto@example.com'),
        new OA\Property(property: 'address', type: ['string', 'null'], maxLength: 255, example: 'Ciudad de Guatemala'),
        new OA\Property(property: 'notes', description: 'Texto opcional sin un límite aplicativo adicional al tipo TEXT de persistencia.', type: ['string', 'null'], example: 'Observación administrativa.'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'CommercialClientResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/CommercialClient'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ActiveCommercialClient',
    required: ['id', 'name', 'type'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Seguros Ejemplo'),
        new OA\Property(property: 'type', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ActiveCommercialClientCollection',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ActiveCommercialClient')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpdateCommercialClientInput',
    properties: [
        new OA\Property(property: 'name', description: 'Se persiste sin espacios exteriores y conserva su casing.', type: 'string', maxLength: 150, example: 'Seguros Ejemplo'),
        new OA\Property(property: 'type', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
        new OA\Property(property: 'tax_id', type: ['string', 'null'], maxLength: 50, example: '1234567-8'),
        new OA\Property(property: 'phone', type: ['string', 'null'], maxLength: 30, example: '+502 2222-3333'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', maxLength: 150, example: 'contacto@example.com'),
        new OA\Property(property: 'address', type: ['string', 'null'], maxLength: 255, example: 'Ciudad de Guatemala'),
        new OA\Property(property: 'notes', description: 'Texto opcional sin un límite aplicativo adicional al tipo TEXT de persistencia.', type: ['string', 'null'], example: 'Observación administrativa.'),
    ],
    type: 'object',
    additionalProperties: false,
    minProperties: 1,
)]
#[OA\Schema(
    schema: 'UpdateCommercialClientStatusInput',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'CommercialClientCollection',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommercialClient')),
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
final class CommercialClientDocumentation
{
    // OpenAPI components for Commercial Client endpoints.
}
