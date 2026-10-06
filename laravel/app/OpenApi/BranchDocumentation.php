<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Branches',
    description: 'Sucursales pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'ActiveBranch',
    required: ['id', 'code', 'name', 'is_main'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 20, example: 'MAIN'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Sucursal Principal'),
        new OA\Property(property: 'is_main', type: 'boolean', example: true),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'ActiveBranchCollection',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ActiveBranch')),
    ],
    type: 'object',
    additionalProperties: false,
)]
final class BranchDocumentation
{
    // OpenAPI components for the active Branch catalog.
}
