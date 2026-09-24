<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Parameter(
    parameter: 'LaboratoryContextHeader',
    name: 'X-Laboratory-ID',
    description: 'Identificador del laboratorio bajo cuyo contexto se ejecuta la solicitud. El usuario autenticado debe tener una membresía activa en el laboratorio indicado.',
    in: 'header',
    required: true,
    schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
)]
final class LaboratoryContextDocumentation
{
    // Reusable OpenAPI header for future tenant-aware endpoints.
}

#[OA\Schema(
    schema: 'LaboratoryContextError',
    required: ['message', 'code'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Debe especificar el laboratorio.'),
        new OA\Property(
            property: 'code',
            type: 'string',
            enum: [
                'LABORATORY_CONTEXT_REQUIRED',
                'INVALID_LABORATORY_CONTEXT',
                'LABORATORY_NOT_FOUND',
                'LABORATORY_INACTIVE',
                'LABORATORY_ACCESS_DENIED',
            ],
            example: 'LABORATORY_CONTEXT_REQUIRED',
        ),
    ],
    type: 'object',
)]
#[OA\Response(
    response: 'LaboratoryContextRequired',
    description: 'No se proporcionó el contexto de laboratorio requerido.',
    content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryContextError'),
)]
#[OA\Response(
    response: 'InvalidLaboratoryContext',
    description: 'El identificador de laboratorio proporcionado no es válido.',
    content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryContextError'),
)]
#[OA\Response(
    response: 'LaboratoryNotFound',
    description: 'El laboratorio solicitado no existe.',
    content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryContextError'),
)]
#[OA\Response(
    response: 'LaboratoryInactive',
    description: 'El laboratorio solicitado no está activo.',
    content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryContextError'),
)]
#[OA\Response(
    response: 'LaboratoryAccessDenied',
    description: 'El usuario no tiene una membresía activa en el laboratorio solicitado.',
    content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryContextError'),
)]
final class LaboratoryContextErrorDocumentation
{
    // Reusable OpenAPI error components for future tenant-aware endpoints.
}
