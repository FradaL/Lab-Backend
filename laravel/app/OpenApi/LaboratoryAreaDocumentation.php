<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Laboratory Areas',
    description: 'Áreas pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'LaboratoryAreaListItem',
    required: ['id', 'code', 'name', 'description', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM'),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Hematología'),
        new OA\Property(property: 'description', type: ['string', 'null'], maxLength: 255, example: 'Área de análisis hematológicos.'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryAreaListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LaboratoryAreaListItem')),
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
    schema: 'CreateLaboratoryAreaInput',
    required: ['code', 'name'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM'),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Hematología'),
        new OA\Property(property: 'description', type: ['string', 'null'], maxLength: 255, example: 'Área de análisis hematológicos.'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryAreaResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/LaboratoryAreaListItem'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryAreaDetail',
    required: ['id', 'code', 'name', 'description', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM'),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Hematología'),
        new OA\Property(property: 'description', type: ['string', 'null'], maxLength: 255, example: 'Área de análisis hematológicos.'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T12:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryAreaDetailResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/LaboratoryAreaDetail'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpdateLaboratoryAreaInput',
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM-CL'),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Hematología Clínica'),
        new OA\Property(property: 'description', type: ['string', 'null'], maxLength: 255, example: 'Área de hematología clínica.'),
    ],
    type: 'object',
    additionalProperties: false,
    minProperties: 1,
)]
#[OA\Schema(
    schema: 'UpdateLaboratoryAreaStatusInput',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryAreaOption',
    required: ['id', 'code', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'HEM'),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Hematología'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryAreaOptionCollection',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LaboratoryAreaOption')),
    ],
    type: 'object',
)]
final class LaboratoryAreaDocumentation
{
    // OpenAPI components for Laboratory Area endpoints.
}
