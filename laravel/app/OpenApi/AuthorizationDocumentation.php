<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CurrentAuthorizationLaboratory',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Laboratorio Demo Donqer'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CurrentAuthorization',
    required: ['laboratory', 'roles', 'permissions'],
    properties: [
        new OA\Property(property: 'laboratory', ref: '#/components/schemas/CurrentAuthorizationLaboratory'),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'receptionist'),
        ),
        new OA\Property(
            property: 'permissions',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'orders.view'),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CurrentAuthorizationResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/CurrentAuthorization'),
    ],
    type: 'object',
)]
final class AuthorizationDocumentation
{
    // OpenAPI schemas for the effective tenant authorization context.
}
