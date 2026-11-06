<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CashRefundRequest', type: 'object', additionalProperties: false,
    required: ['expected_revision', 'amount', 'method', 'reason'],
    properties: [
        new OA\Property(property: 'expected_revision', type: 'integer', minimum: 1, maximum: 2147483647),
        new OA\Property(property: 'amount', type: 'string', pattern: '^(?:0\\.(?:0[1-9]|[1-9][0-9])|[1-9][0-9]{0,9}\\.[0-9]{2})$', example: '100.00'),
        new OA\Property(property: 'method', type: 'string', enum: ['cash', 'card', 'bank_transfer']),
        new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000, pattern: '\\S'),
        new OA\Property(property: 'reference', description: 'Obligatoria y no nula con tarjeta o transferencia. Opcional en efectivo. Se valida longitud antes de recortar espacios exteriores.', type: ['string', 'null'], minLength: 1, maxLength: 150, pattern: '\\S'),
    ],
    oneOf: [
        new OA\Schema(properties: [new OA\Property(property: 'method', type: 'string', enum: ['cash'])]),
        new OA\Schema(required: ['reference'], properties: [
            new OA\Property(property: 'method', type: 'string', enum: ['card', 'bank_transfer']),
            new OA\Property(property: 'reference', type: 'string', minLength: 1, maxLength: 150, pattern: '\\S'),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'CashRefundMovement', type: 'object', additionalProperties: false,
    required: ['id', 'financial_revision', 'created_by', 'recorded_at', 'type', 'amount', 'currency', 'method', 'reference', 'reason', 'target_payment_id', 'payment_status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', minimum: 1),
        new OA\Property(property: 'financial_revision', type: 'integer', minimum: 1, maximum: 2147483647),
        new OA\Property(property: 'created_by', type: 'integer', format: 'int64', minimum: 1),
        new OA\Property(property: 'recorded_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'type', type: 'string', enum: ['refund']),
        new OA\Property(property: 'amount', type: 'string', example: '100.00'),
        new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$'),
        new OA\Property(property: 'method', type: 'string', enum: ['cash', 'card', 'bank_transfer']),
        new OA\Property(property: 'reference', type: ['string', 'null']),
        new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000, pattern: '\\S'),
        new OA\Property(property: 'target_payment_id', type: 'null'),
        new OA\Property(property: 'payment_status', type: 'null'),
    ],
)]
#[OA\Schema(
    schema: 'CashRefundResponse', type: 'object', additionalProperties: false, required: ['data'],
    properties: [new OA\Property(property: 'data', type: 'object', additionalProperties: false,
        required: ['operation_id', 'operation', 'laboratory_order_id', 'financial_revision', 'resource'],
        properties: [
            new OA\Property(property: 'operation_id', type: 'string', minLength: 26, maxLength: 26),
            new OA\Property(property: 'operation', type: 'string', enum: ['record_refund']),
            new OA\Property(property: 'laboratory_order_id', type: 'integer', format: 'int64', minimum: 1),
            new OA\Property(property: 'financial_revision', type: 'integer', minimum: 1, maximum: 2147483647),
            new OA\Property(property: 'resource', ref: '#/components/schemas/CashRefundMovement'),
        ])],
)]
#[OA\Post(
    path: '/api/v1/laboratory-orders/{order}/refunds', operationId: 'recordOrderRefund', tags: ['Caja'],
    summary: 'Registrar una devolución manual sin duplicarla por reintentos',
    description: 'EB-001 1.1 / BE-D02-12. Exige payments.refund efectivo; no concede permisos automáticamente. Monto positivo hasta el excedente vigente, comprobado bajo bloqueo de orden. No ejecuta transferencias bancarias. Un replay autorizado conserva HTTP 201 y el cuerpo original aunque avance la revisión. La misma clave con otro contenido, actor u orden devuelve cash_idempotency_conflict. No modifica ni borra cobros y no habilita por sí mismo la entrega.',
    security: [['sanctumCookie' => []]],
    parameters: [
        new OA\Parameter(name: 'X-Laboratory-ID', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', pattern: '^[A-Za-z0-9_.-]{16,100}$')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CashRefundRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Devolución confirmada o respuesta original recuperada.', headers: [new OA\Header(header: 'Idempotency-Replayed', schema: new OA\Schema(type: 'boolean'))], content: new OA\JsonContent(ref: '#/components/schemas/CashRefundResponse')),
        new OA\Response(response: 400, description: 'Contexto de laboratorio inválido.'),
        new OA\Response(response: 401, description: 'Sesión requerida.'),
        new OA\Response(response: 403, description: 'Acceso o facultad insuficiente.'),
        new OA\Response(response: 404, description: 'Orden inexistente o ajena al laboratorio.'),
        new OA\Response(response: 409, description: 'cash_context_unavailable, cash_revision_conflict, cash_idempotency_conflict, cash_accumulator_overflow o cash_revision_exhausted.'),
        new OA\Response(response: 422, description: 'cash_refund_exceeds_surplus o validación de campos, revisión, monto, medio, referencia, motivo y clave.'),
        new OA\Response(response: 503, description: 'cash_evidence_unavailable: contexto financiero inconsistente.'),
    ],
)]
final class CashRefundDocumentation {}
