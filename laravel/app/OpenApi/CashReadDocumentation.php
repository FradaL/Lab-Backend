<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

// Read schemas transcribed from EB-001 OpenAPI 1.1.0; preserve its closed objects and conditions.
#[OA\Schema(schema: 'CashReadAggregateMoney', type: 'string', pattern: '^(0|[1-9][0-9]{0,15})\\.[0-9]{2}(?![\\s\\S])')]
#[OA\Schema(schema: 'CashReadCashMovement', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'id', type: 'integer', format: 'int64', minimum: 1),
    new OA\Property(property: 'financial_revision', type: 'integer', minimum: 1, maximum: 2147483647),
    new OA\Property(property: 'created_by', type: 'integer', format: 'int64', minimum: 1),
    new OA\Property(property: 'recorded_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'type', enum: ['payment', 'payment_void', 'refund']),
    new OA\Property(property: 'amount', ref: '#/components/schemas/CashReadPositiveMoney'),
    new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$'),
    new OA\Property(property: 'method', anyOf: [
        new OA\Schema(type: 'string', enum: ['cash', 'card', 'bank_transfer']),
        new OA\Schema(type: 'null'),
    ]),
    new OA\Property(property: 'reference', anyOf: [
        new OA\Schema(type: 'string', minLength: 1, maxLength: 150),
        new OA\Schema(type: 'null'),
    ]),
    new OA\Property(property: 'reason', anyOf: [
        new OA\Schema(type: 'string', minLength: 1, maxLength: 1000),
        new OA\Schema(type: 'null'),
    ]),
    new OA\Property(property: 'target_payment_id', anyOf: [
        new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
        new OA\Schema(type: 'null'),
    ]),
    new OA\Property(property: 'payment_status', anyOf: [
        new OA\Schema(enum: ['active', 'voided']),
        new OA\Schema(type: 'null'),
    ]),
], required: ['id', 'financial_revision', 'created_by', 'recorded_at', 'type', 'amount', 'currency', 'method', 'reference', 'reason', 'target_payment_id', 'payment_status'], oneOf: [
    new OA\Schema(properties: [
        new OA\Property(property: 'type', const: 'payment'),
        new OA\Property(property: 'method', type: 'string', enum: ['cash', 'card', 'bank_transfer']),
        new OA\Property(property: 'target_payment_id', type: 'null'),
        new OA\Property(property: 'reason', type: 'null'),
        new OA\Property(property: 'payment_status', enum: ['active', 'voided']),
    ], if: new OA\Schema(properties: [
        new OA\Property(property: 'method', enum: ['card', 'bank_transfer']),
    ]), then: new OA\Schema(properties: [
        new OA\Property(property: 'reference', type: 'string', minLength: 1, maxLength: 150, pattern: '\\S'),
    ])),
    new OA\Schema(properties: [
        new OA\Property(property: 'type', const: 'payment_void'),
        new OA\Property(property: 'method', type: 'null'),
        new OA\Property(property: 'reference', type: 'null'),
        new OA\Property(property: 'target_payment_id', type: 'integer', format: 'int64', minimum: 1),
        new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000, pattern: '\\S'),
        new OA\Property(property: 'payment_status', type: 'null'),
    ]),
    new OA\Schema(properties: [
        new OA\Property(property: 'type', const: 'refund'),
        new OA\Property(property: 'method', type: 'string', enum: ['cash', 'card', 'bank_transfer']),
        new OA\Property(property: 'target_payment_id', type: 'null'),
        new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000, pattern: '\\S'),
        new OA\Property(property: 'payment_status', type: 'null'),
    ], if: new OA\Schema(properties: [
        new OA\Property(property: 'method', enum: ['card', 'bank_transfer']),
    ]), then: new OA\Schema(properties: [
        new OA\Property(property: 'reference', type: 'string', minLength: 1, maxLength: 150, pattern: '\\S'),
    ])),
])]
#[OA\Schema(schema: 'CashReadCashSummary', oneOf: [
    new OA\Schema(type: 'object', additionalProperties: false, properties: [
        new OA\Property(property: 'laboratory_order_id', type: 'integer', format: 'int64', minimum: 1),
        new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$'),
        new OA\Property(property: 'total', ref: '#/components/schemas/CashReadMoney'),
        new OA\Property(property: 'available', const: true),
        new OA\Property(property: 'unavailable_reason', type: 'null'),
        new OA\Property(property: 'financial_revision', type: 'integer', minimum: 1, maximum: 2147483647),
        new OA\Property(property: 'delivery_policy', ref: '#/components/schemas/CashReadPolicySnapshot'),
        new OA\Property(property: 'valid_payments', ref: '#/components/schemas/CashReadAggregateMoney'),
        new OA\Property(property: 'refunds', ref: '#/components/schemas/CashReadAggregateMoney'),
        new OA\Property(property: 'net_payments', ref: '#/components/schemas/CashReadAggregateMoney'),
        new OA\Property(property: 'balance_due', ref: '#/components/schemas/CashReadAggregateMoney'),
        new OA\Property(property: 'surplus', ref: '#/components/schemas/CashReadAggregateMoney'),
        new OA\Property(property: 'credit', anyOf: [
            new OA\Schema(ref: '#/components/schemas/CashReadCreditSummary'),
            new OA\Schema(type: 'null'),
        ]),
    ], required: ['laboratory_order_id', 'currency', 'total', 'available', 'unavailable_reason', 'financial_revision', 'delivery_policy', 'valid_payments', 'refunds', 'net_payments', 'balance_due', 'surplus', 'credit']),
    new OA\Schema(type: 'object', additionalProperties: false, properties: [
        new OA\Property(property: 'laboratory_order_id', type: 'integer', format: 'int64', minimum: 1),
        new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$'),
        new OA\Property(property: 'total', ref: '#/components/schemas/CashReadMoney'),
        new OA\Property(property: 'available', const: false),
        new OA\Property(property: 'unavailable_reason', const: 'cash_context_unavailable'),
        new OA\Property(property: 'financial_revision', type: 'null'),
        new OA\Property(property: 'delivery_policy', type: 'null'),
        new OA\Property(property: 'valid_payments', type: 'null'),
        new OA\Property(property: 'refunds', type: 'null'),
        new OA\Property(property: 'net_payments', type: 'null'),
        new OA\Property(property: 'balance_due', type: 'null'),
        new OA\Property(property: 'surplus', type: 'null'),
        new OA\Property(property: 'credit', type: 'null'),
    ], required: ['laboratory_order_id', 'currency', 'total', 'available', 'unavailable_reason', 'financial_revision', 'delivery_policy', 'valid_payments', 'refunds', 'net_payments', 'balance_due', 'surplus', 'credit']),
])]
#[OA\Schema(schema: 'CashReadCreditSummary', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'authorization_id', type: 'integer', format: 'int64', minimum: 1),
    new OA\Property(property: 'amount', ref: '#/components/schemas/CashReadPositiveMoney'),
    new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000, pattern: '\\S'),
    new OA\Property(property: 'authorized_by', type: 'integer', format: 'int64', minimum: 1),
    new OA\Property(property: 'authorized_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'is_sufficient', type: 'boolean'),
], required: ['authorization_id', 'amount', 'reason', 'authorized_by', 'authorized_at', 'is_sufficient'])]
#[OA\Schema(schema: 'CashReadMoney', type: 'string', pattern: '^(0|[1-9][0-9]{0,9})\\.[0-9]{2}(?![\\s\\S])')]
#[OA\Schema(schema: 'CashReadMovementsResponse', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CashReadCashMovement')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/CashReadPageMeta'),
], required: ['data', 'meta'])]
#[OA\Schema(schema: 'CashReadPageMeta', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'current_page', type: 'integer', minimum: 1),
    new OA\Property(property: 'last_page', type: 'integer', minimum: 1),
    new OA\Property(property: 'per_page', type: 'integer', minimum: 1, maximum: 100),
    new OA\Property(property: 'total', type: 'integer', minimum: 0),
    new OA\Property(property: 'financial_revision', anyOf: [
        new OA\Schema(type: 'integer', minimum: 1, maximum: 2147483647),
        new OA\Schema(type: 'null'),
    ]),
], required: ['current_page', 'last_page', 'per_page', 'total', 'financial_revision'])]
#[OA\Schema(schema: 'CashReadPolicySnapshot', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'mode', type: 'string', enum: ['unrestricted', 'full_payment_required', 'payment_or_credit']),
    new OA\Property(property: 'source_revision', type: 'integer', minimum: 1, maximum: 2147483647),
    new OA\Property(property: 'source', const: 'configured'),
    new OA\Property(property: 'configured_by', type: 'integer', format: 'int64', minimum: 1),
    new OA\Property(property: 'configured_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'captured_at', type: 'string', format: 'date-time'),
], required: ['mode', 'source_revision', 'source', 'configured_by', 'configured_at', 'captured_at'])]
#[OA\Schema(schema: 'CashReadPositiveMoney', type: 'string', pattern: '^(0|[1-9][0-9]{0,9})\\.[0-9]{2}(?![\\s\\S])', not: new OA\Schema(const: '0.00'))]
#[OA\Schema(schema: 'CashReadSummaryResponse', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/CashReadCashSummary'),
], required: ['data'])]
#[OA\Get(
    path: '/api/v1/laboratory-orders/{order}/cash-summary', operationId: 'readOrderCashSummary', tags: ['Caja'],
    summary: 'Consultar pagos, saldo y excedente de una orden',
    description: 'BE-D02-06 / EB-001 1.1. Requiere payments.view efectivo. Lectura coherente bajo bloqueo compartido de orden; no crea movimientos, auditoría ni revisión. Solo page/per_page en movimientos; resumen sin parámetros. Históricos sin contexto: resumen disponible=false con importes financieros nulos; movimientos 409. Contexto nuevo inconsistente: 503. GET separados pueden tener revisiones distintas. Crédito por orden continúa en BE07; estos lectores conservan credit=null mientras no exista autorización. No habilita entrega ni implementa escrituras de anulación/devolución.',
    security: [['sanctumCookie' => []]], parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
    responses: [
        new OA\Response(response: 200, description: 'Lectura de la orden en la revisión indicada.', content: new OA\JsonContent(ref: '#/components/schemas/CashReadSummaryResponse')),
        new OA\Response(response: 400, description: 'Contexto de laboratorio inválido.'),
        new OA\Response(response: 401, description: 'Sesión requerida.'),
        new OA\Response(response: 403, description: 'Acceso o facultad insuficiente.'),
        new OA\Response(response: 404, description: 'Orden inexistente o ajena al laboratorio.'),
        new OA\Response(response: 409, description: 'cash_context_unavailable o cash_accumulator_overflow.'),
        new OA\Response(response: 422, description: 'Parámetros desconocidos o paginación inválida.'),
        new OA\Response(response: 503, description: 'cash_evidence_unavailable: contexto financiero inconsistente.'),
    ],
)]
#[OA\Get(
    path: '/api/v1/laboratory-orders/{order}/cash-movements', operationId: 'readOrderCashMovements', tags: ['Caja'],
    summary: 'Consultar movimientos paginados de una orden',
    description: 'BE-D02-06 / EB-001 1.1. Requiere payments.view efectivo. Lectura coherente bajo bloqueo compartido de orden; no crea movimientos, auditoría ni revisión. Solo page/per_page en movimientos; resumen sin parámetros. Históricos sin contexto: resumen disponible=false con importes financieros nulos; movimientos 409. Contexto nuevo inconsistente: 503. GET separados pueden tener revisiones distintas. Crédito por orden continúa en BE07; estos lectores conservan credit=null mientras no exista autorización. No habilita entrega ni implementa escrituras de anulación/devolución.',
    security: [['sanctumCookie' => []]], parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15))],
    responses: [
        new OA\Response(response: 200, description: 'Lectura de la orden en la revisión indicada.', content: new OA\JsonContent(ref: '#/components/schemas/CashReadMovementsResponse')),
        new OA\Response(response: 400, description: 'Contexto de laboratorio inválido.'),
        new OA\Response(response: 401, description: 'Sesión requerida.'),
        new OA\Response(response: 403, description: 'Acceso o facultad insuficiente.'),
        new OA\Response(response: 404, description: 'Orden inexistente o ajena al laboratorio.'),
        new OA\Response(response: 409, description: 'cash_context_unavailable o cash_accumulator_overflow.'),
        new OA\Response(response: 422, description: 'Parámetros desconocidos o paginación inválida.'),
        new OA\Response(response: 503, description: 'cash_evidence_unavailable: contexto financiero inconsistente.'),
    ],
)]
final class CashReadDocumentation {}
