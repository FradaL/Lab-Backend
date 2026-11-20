<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'CashPaymentAttemptUnknownResponse', type: 'object', additionalProperties: false, required: ['data'], properties: [
    new OA\Property(property: 'data', type: 'object', additionalProperties: false, required: ['status'], properties: [
        new OA\Property(property: 'status', const: 'unknown'),
    ]),
])]
#[OA\Schema(schema: 'CashPaymentAttemptConfirmedResponse', type: 'object', additionalProperties: false, required: ['data'], properties: [
    new OA\Property(property: 'data', type: 'object', additionalProperties: false, required: ['status', 'operation_id', 'http_status', 'response'], properties: [
        new OA\Property(property: 'status', const: 'confirmed'),
        new OA\Property(property: 'operation_id', type: 'string', minLength: 26, maxLength: 26),
        new OA\Property(property: 'http_status', const: 201),
        new OA\Property(property: 'response', ref: '#/components/schemas/CashPaymentResponse'),
    ]),
])]
#[OA\Get(
    path: '/api/v1/laboratory-orders/{order}/payment-attempts/{idempotencyKey}', operationId: 'readOrderPaymentAttempt', tags: ['Caja'],
    summary: 'Recuperar de forma segura un intento de cobro incierto',
    description: 'BE-D02-04. Requiere payments.view y payments.create efectivos. Es una lectura sin escrituras: solo confirma la operación del mismo laboratorio, orden, actor y clave. La ausencia de evidencia devuelve 202 unknown y es inconclusa; el cliente no debe generar una clave nueva ni asumir que puede volver a cobrar.',
    security: [['sanctumCookie' => []]], parameters: [
        new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'idempotencyKey', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[A-Za-z0-9_.-]{16,100}$')),
    ], responses: [
        new OA\Response(response: 200, description: 'La operación fue confirmada y devuelve el cuerpo original del POST.', content: new OA\JsonContent(ref: '#/components/schemas/CashPaymentAttemptConfirmedResponse')),
        new OA\Response(response: 202, description: 'No hay evidencia concluyente de la operación; no equivale a un cobro fallido.', content: new OA\JsonContent(ref: '#/components/schemas/CashPaymentAttemptUnknownResponse')),
        new OA\Response(response: 400, description: 'Contexto de laboratorio inválido.'),
        new OA\Response(response: 401, description: 'Sesión requerida.'),
        new OA\Response(response: 403, description: 'Falta payments.view o payments.create efectivo.'),
        new OA\Response(response: 404, description: 'Orden inexistente o ajena al laboratorio.'),
        new OA\Response(response: 422, description: 'Clave de idempotencia inválida.'),
    ],
)]
final class CashAttemptRecoveryDocumentation {}
