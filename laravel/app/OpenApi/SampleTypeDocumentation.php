<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Sample Types',
    description: 'Tipos de muestra pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'SampleTypeListItem',
    required: ['id', 'name', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Sangre'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-24T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SampleTypeListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SampleTypeListItem')),
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
#[OA\Schema(
    schema: 'CreateSampleTypeInput',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Sangre'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'SampleTypeResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/SampleTypeListItem'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SampleTypeDetail',
    required: ['id', 'name', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Sangre'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-24T12:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-24T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SampleTypeDetailResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/SampleTypeDetail'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpdateSampleTypeInput',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Sangre total'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateSampleTypeStatusInput',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'SampleTypeOption',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Sangre'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SampleTypeOptionCollection',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SampleTypeOption')),
    ],
    type: 'object',
)]
final class SampleTypeDocumentation
{
    // OpenAPI components for Sample Type endpoints.
}
