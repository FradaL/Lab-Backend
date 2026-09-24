<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SubscriptionAccessError',
    required: ['message', 'code'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'El laboratorio requiere una suscripción activa.'),
        new OA\Property(
            property: 'code',
            type: 'string',
            enum: [
                'SUBSCRIPTION_REQUIRED',
                'SUBSCRIPTION_NOT_STARTED',
                'SUBSCRIPTION_EXPIRED',
                'TRIAL_EXPIRED',
            ],
            example: 'SUBSCRIPTION_REQUIRED',
        ),
    ],
    type: 'object',
)]
#[OA\Response(
    response: 'SubscriptionRequired',
    description: 'El laboratorio no tiene una suscripción activa.',
    content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionAccessError'),
)]
#[OA\Response(
    response: 'SubscriptionNotStarted',
    description: 'La suscripción activa todavía no ha iniciado.',
    content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionAccessError'),
)]
#[OA\Response(
    response: 'SubscriptionExpired',
    description: 'La suscripción normal activa ha vencido.',
    content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionAccessError'),
)]
#[OA\Response(
    response: 'TrialExpired',
    description: 'La suscripción demo activa ha vencido.',
    content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionAccessError'),
)]
final class SubscriptionAccessDocumentation
{
    // Reusable OpenAPI components for subscription-protected endpoints.
}
