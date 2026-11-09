<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CashVoidPaymentRequest', type: 'object', additionalProperties: false,
    required: ['expected_revision', 'reason'],
    properties: [
        new OA\Property(property: 'expected_revision', type: 'integer', minimum: 1, maximum: 2147483647),
        new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000, pattern: '\\S'),
    ],
)]
#[OA\Schema(
    schema: 'CashVoidPaymentMovement', type: 'object', additionalProperties: false,
    required: ['id', 'financial_revision', 'created_by', 'recorded_at', 'type', 'amount', 'currency', 'method', 'reference', 'reason', 'target_payment_id', 'payment_status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', minimum: 1),
        new OA\Property(property: 'financial_revision', type: 'integer', minimum: 1, maximum: 2147483647),
        new OA\Property(property: 'created_by', type: 'integer', format: 'int64', minimum: 1),
        new OA\Property(property: 'recorded_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'type', type: 'string', enum: ['payment_void']),
        new OA\Property(property: 'amount', type: 'string', example: '100.00'),
        new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$'),
        new OA\Property(property: 'method', type: 'null'),
        new OA\Property(property: 'reference', type: 'null'),
        new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000),
        new OA\Property(property: 'target_payment_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'payment_status', type: 'null'),
    ],
)]
#[OA\Schema(
    schema: 'CashVoidPaymentResponse', type: 'object', additionalProperties: false, required: ['data'],
    properties: [new OA\Property(property: 'data', type: 'object', additionalProperties: false,
        required: ['operation_id', 'operation', 'laboratory_order_id', 'financial_revision', 'resource'],
        properties: [
            new OA\Property(property: 'operation_id', type: 'string', minLength: 26, maxLength: 26),
            new OA\Property(property: 'operation', type: 'string', enum: ['void_payment']),
            new OA\Property(property: 'laboratory_order_id', type: 'integer', format: 'int64', minimum: 1),
            new OA\Property(property: 'financial_revision', type: 'integer', minimum: 1, maximum: 2147483647),
            new OA\Property(property: 'resource', ref: '#/components/schemas/CashVoidPaymentMovement'),
        ])],
)]
#[OA\Post(
    path: '/api/v1/laboratory-orders/{order}/payments/{payment}/void', operationId: 'voidOrderPayment', tags: ['Caja'],
    summary: 'Anular un cobro completo conservando su historia',
    description: 'EB-001 1.1 / BE-D02-11. Exige payments.void efectivo, motivo y revisión. Importe completo obtenido del cobro original. Reintento autorizado devuelve 201 y cuerpo original. Una sola anulación efectiva; no cambia el total ni genera devolución. Bloquea pagos netos negativos con cash_void_negative_net. No integra entrega de resultados.',
    security: [['sanctumCookie' => []]],
    parameters: [
        new OA\Parameter(name: 'X-Laboratory-ID', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'payment', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', pattern: '^[A-Za-z0-9_.-]{16,100}$')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CashVoidPaymentRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Anulación confirmada o respuesta original recuperada.', headers: [new OA\Header(header: 'Idempotency-Replayed', schema: new OA\Schema(type: 'boolean'))], content: new OA\JsonContent(ref: '#/components/schemas/CashVoidPaymentResponse')),
        new OA\Response(response: 400, description: 'Contexto de laboratorio inválido.'),
        new OA\Response(response: 401, description: 'Sesión requerida.'),
        new OA\Response(response: 403, description: 'Acceso o facultad insuficiente.'),
        new OA\Response(response: 404, description: 'Orden o cobro inexistente, ajeno al laboratorio o sin pertenencia a la orden.'),
        new OA\Response(response: 409, description: 'cash_context_unavailable, cash_revision_conflict, cash_idempotency_conflict, cash_payment_already_voided, cash_void_negative_net, cash_accumulator_overflow o cash_revision_exhausted.'),
        new OA\Response(response: 422, description: 'Campos desconocidos, revisión, motivo o clave inválidos.'),
        new OA\Response(response: 503, description: 'cash_evidence_unavailable: contexto financiero inconsistente.'),
    ],
)]
final class CashVoidDocumentation {}
