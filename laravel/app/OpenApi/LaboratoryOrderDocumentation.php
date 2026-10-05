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
        new OA\Property(property: 'name', type: 'string', example: 'Seguro XYZ'),
        new OA\Property(property: 'type', type: 'string', enum: ['insurance', 'company', 'agreement', 'other'], example: 'insurance'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryOrderPriceList',
    required: ['id', 'name', 'currency'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Tarifa aseguradoras'),
        new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$', example: 'GTQ'),
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
    schema: 'LaboratoryOrder',
    required: ['id', 'code', 'ordered_at', 'status', 'notes', 'patient', 'doctor', 'commercial_client', 'price_list', 'branch', 'subtotal', 'discount', 'taxes', 'total', 'currency', 'created_by', 'created_at', 'updated_at'],
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
        new OA\Property(property: 'discount', type: 'string', pattern: '^\\d+\\.\\d{2}$', example: '0.00'),
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
