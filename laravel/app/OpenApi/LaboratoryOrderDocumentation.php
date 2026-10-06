<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Laboratory Orders',
    description: 'Creación de órdenes de laboratorio.',
)]
#[OA\Schema(
    schema: 'AddLaboratoryOrderExamInput',
    required: ['laboratory_exam_id'],
    properties: [
        new OA\Property(property: 'laboratory_exam_id', type: 'integer', format: 'int64', minimum: 1, example: 15),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrderExamSnapshot',
    required: ['id', 'code', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 15),
        new OA\Property(property: 'code', description: 'Código capturado al agregar la línea.', type: 'string', example: 'GLU'),
        new OA\Property(property: 'name', description: 'Nombre capturado al agregar la línea.', type: 'string', example: 'Glucosa'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrderExamPriceListSnapshot',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 5),
        new OA\Property(property: 'name', description: 'Nombre de la lista capturado al agregar la línea.', type: 'string', example: 'Precio particular'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrderExam',
    required: ['id', 'exam', 'price_list', 'unit_price', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 501),
        new OA\Property(property: 'exam', ref: '#/components/schemas/LaboratoryOrderExamSnapshot'),
        new OA\Property(property: 'price_list', ref: '#/components/schemas/LaboratoryOrderExamPriceListSnapshot'),
        new OA\Property(property: 'unit_price', description: 'Precio capturado al agregar la línea.', type: 'string', pattern: '^\d+\.\d{2}$', example: '35.00'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrderExamResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/LaboratoryOrderExam'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrderExamCollectionResponse',
    required: ['data'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/LaboratoryOrderExam'),
        ),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'CreateLaboratoryOrderInput',
    required: ['branch_id', 'patient_id', 'doctor_id', 'commercial_client_id', 'price_list_id', 'ordered_at', 'notes'],
    properties: [
        new OA\Property(property: 'branch_id', type: 'integer', format: 'int64', minimum: 1, example: 1),
        new OA\Property(property: 'patient_id', type: 'integer', format: 'int64', minimum: 1, example: 10),
        new OA\Property(property: 'doctor_id', type: ['integer', 'null'], format: 'int64', minimum: 1, example: 4),
        new OA\Property(property: 'commercial_client_id', type: ['integer', 'null'], format: 'int64', minimum: 1, example: 8),
        new OA\Property(property: 'price_list_id', type: 'integer', format: 'int64', minimum: 1, example: 3),
        new OA\Property(property: 'ordered_at', description: 'Fecha y hora local persistida sin conversión de zona horaria.', type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$', example: '2026-10-03 14:30:00'),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Observaciones opcionales'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateLaboratoryOrderStatusInput',
    required: ['status'],
    properties: [
        new OA\Property(
            property: 'status',
            description: 'Estado objetivo explícito. Sólo se permiten las transiciones pending→in_process/cancelled e in_process→completed/cancelled; el mismo estado es idempotente y completed/cancelled son terminales.',
            type: 'string',
            enum: ['pending', 'in_process', 'completed', 'cancelled'],
            example: 'in_process',
        ),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateLaboratoryOrderDiscountInput',
    required: ['type', 'value'],
    properties: [
        new OA\Property(
            property: 'type',
            type: 'string',
            enum: ['percentage', 'amount'],
            example: 'percentage',
        ),
        new OA\Property(
            property: 'value',
            description: 'Decimal positivo con máximo dos posiciones; porcentaje hasta 100.00 y monto dentro de NUMERIC(12,2).',
            oneOf: [
                new OA\Schema(type: 'string', pattern: '^\\d+(?:\\.\\d{1,2})?$', example: '10.00'),
                new OA\Schema(type: 'integer', minimum: 1, example: 10),
            ],
        ),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrderPatient',
    required: ['id', 'first_names', 'last_names'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'first_names', type: 'string', example: 'Ana'),
        new OA\Property(property: 'last_names', type: 'string', example: 'López'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderDoctor',
    required: ['id', 'first_names', 'last_names'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 4),
        new OA\Property(property: 'first_names', type: 'string', example: 'Carlos'),
        new OA\Property(property: 'last_names', type: 'string', example: 'Pérez'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderCommercialClient',
    required: ['id', 'name', 'type'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 8),
        new OA\Property(property: 'name', description: 'Nombre histórico capturado al crear la orden.', type: 'string', example: 'Seguro XYZ'),
        new OA\Property(property: 'type', description: 'Tipo histórico capturado al crear la orden.', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderPriceList',
    required: ['id', 'name', 'currency'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'name', description: 'Nombre histórico capturado al crear la orden.', type: 'string', example: 'Tarifa aseguradoras'),
        new OA\Property(property: 'currency', description: 'Moneda histórica capturada al crear la orden.', type: 'string', pattern: '^[A-Z]{3}$', example: 'GTQ'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderBranch',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Sucursal Central'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderCreator',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Administrador Demo'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderListItem',
    required: ['id', 'code', 'ordered_at', 'status', 'branch', 'patient', 'doctor', 'commercial_client', 'exam_count', 'currency', 'total', 'created_by'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 123),
        new OA\Property(property: 'code', type: 'string', maxLength: 45, pattern: '^ORD-[0-9A-HJKMNP-TV-Z]{26}$', example: 'ORD-01K6PW1VC7QFM4R6FY0WYQ0B6W'),
        new OA\Property(property: 'ordered_at', type: 'string', example: '2026-10-03 14:30:00'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_process', 'completed', 'cancelled'], example: 'pending'),
        new OA\Property(property: 'branch', ref: '#/components/schemas/LaboratoryOrderBranch'),
        new OA\Property(property: 'patient', ref: '#/components/schemas/LaboratoryOrderPatient'),
        new OA\Property(property: 'doctor', oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryOrderDoctor'), new OA\Schema(type: 'null')]),
        new OA\Property(property: 'commercial_client', oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryOrderCommercialClient'), new OA\Schema(type: 'null')]),
        new OA\Property(property: 'exam_count', description: 'Cantidad de líneas físicas LaboratoryOrderExam, incluidas las solicitudes repetidas.', type: 'integer', minimum: 0, example: 3),
        new OA\Property(property: 'currency', description: 'Moneda histórica capturada al crear la orden.', type: 'string', pattern: '^[A-Z]{3}$', example: 'GTQ'),
        new OA\Property(property: 'total', description: 'Total económico persistido de la orden; no se recalcula durante el listado.', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '175.00'),
        new OA\Property(property: 'created_by', ref: '#/components/schemas/LaboratoryOrderCreator'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrderListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LaboratoryOrderListItem')),
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
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryOrder',
    required: ['id', 'code', 'ordered_at', 'status', 'notes', 'patient', 'doctor', 'commercial_client', 'price_list', 'branch', 'subtotal', 'discount_type', 'discount_value', 'discount', 'taxes', 'total', 'currency', 'created_by', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 123),
        new OA\Property(property: 'code', type: 'string', maxLength: 45, pattern: '^ORD-[0-9A-HJKMNP-TV-Z]{26}$', example: 'ORD-01K6PW1VC7QFM4R6FY0WYQ0B6W'),
        new OA\Property(property: 'ordered_at', type: 'string', example: '2026-10-03 14:30:00'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_process', 'completed', 'cancelled'], example: 'pending'),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Observaciones opcionales'),
        new OA\Property(property: 'patient', ref: '#/components/schemas/LaboratoryOrderPatient'),
        new OA\Property(property: 'doctor', oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryOrderDoctor'), new OA\Schema(type: 'null')]),
        new OA\Property(property: 'commercial_client', oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryOrderCommercialClient'), new OA\Schema(type: 'null')]),
        new OA\Property(property: 'price_list', ref: '#/components/schemas/LaboratoryOrderPriceList'),
        new OA\Property(property: 'branch', ref: '#/components/schemas/LaboratoryOrderBranch'),
        new OA\Property(property: 'subtotal', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '0.00'),
        new OA\Property(property: 'discount_type', description: 'Forma persistida en que se definió el descuento global; null indica que no hay intención de descuento conocida.', type: ['string', 'null'], enum: ['percentage', 'amount', null], example: 'percentage'),
        new OA\Property(property: 'discount_value', description: 'Valor original persistido: porcentaje expresado como 10.00 para 10%, o monto en la moneda de la orden.', type: ['string', 'null'], pattern: '^\\d+\\.\\d{2}$', example: '10.00'),
        new OA\Property(property: 'discount', description: 'Monto monetario resultante del descuento aplicado.', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '20.00'),
        new OA\Property(property: 'taxes', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '0.00'),
        new OA\Property(property: 'total', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '0.00'),
        new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$', example: 'GTQ'),
        new OA\Property(property: 'created_by', ref: '#/components/schemas/LaboratoryOrderCreator'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/LaboratoryOrder'),
    ],
    type: 'object',
)]
final class LaboratoryOrderDocumentation
{
    // OpenAPI schemas discovered by swagger-php.
}
