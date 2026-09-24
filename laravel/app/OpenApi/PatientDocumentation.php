<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Patients',
    description: 'Pacientes pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'PatientListItem',
    required: ['id', 'first_names', 'last_names', 'birth_date', 'gender', 'phone', 'mobile', 'email', 'affiliation_number', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'first_names', type: 'string', example: 'Daniel'),
        new OA\Property(property: 'last_names', type: 'string', example: 'Lara'),
        new OA\Property(property: 'birth_date', type: ['string', 'null'], format: 'date', example: '1990-01-01'),
        new OA\Property(property: 'gender', type: ['string', 'null'], example: 'male'),
        new OA\Property(property: 'phone', type: ['string', 'null'], example: null),
        new OA\Property(property: 'mobile', type: ['string', 'null'], example: '55555555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', example: null),
        new OA\Property(property: 'affiliation_number', type: ['string', 'null'], example: null),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-22T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PatientListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PatientListItem')),
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
)]
#[OA\Schema(
    schema: 'PatientResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/PatientListItem'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'StorePatientInput',
    required: ['first_names', 'last_names'],
    properties: [
        new OA\Property(property: 'first_names', type: 'string', maxLength: 75, example: 'Daniel Alejandro'),
        new OA\Property(property: 'last_names', type: 'string', maxLength: 75, example: 'Lara López'),
        new OA\Property(property: 'birth_date', type: ['string', 'null'], format: 'date', example: '1995-08-21'),
        new OA\Property(property: 'gender', type: ['string', 'null'], maxLength: 20, example: 'male'),
        new OA\Property(property: 'phone', type: ['string', 'null'], maxLength: 30, example: '+502 2222-2222'),
        new OA\Property(property: 'mobile', type: ['string', 'null'], maxLength: 30, example: '+502 5555-5555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', maxLength: 150, example: 'daniel@example.com'),
        new OA\Property(property: 'address', type: ['string', 'null'], example: 'Ciudad de Guatemala'),
        new OA\Property(property: 'affiliation_number', type: ['string', 'null'], maxLength: 50, example: 'AFF-12345'),
        new OA\Property(property: 'weight', type: ['number', 'null'], maximum: 999.99, exclusiveMinimum: 0, example: 70.5),
        new OA\Property(property: 'height', type: ['number', 'null'], maximum: 999.99, exclusiveMinimum: 0, example: 165.0),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Paciente de primera consulta.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PatientDetail',
    required: ['id', 'first_names', 'last_names', 'birth_date', 'gender', 'phone', 'mobile', 'email', 'address', 'affiliation_number', 'weight', 'height', 'status', 'notes', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 15),
        new OA\Property(property: 'first_names', type: 'string', example: 'Daniel'),
        new OA\Property(property: 'last_names', type: 'string', example: 'Lara'),
        new OA\Property(property: 'birth_date', type: ['string', 'null'], format: 'date', example: '1995-08-21'),
        new OA\Property(property: 'gender', type: ['string', 'null'], example: 'male'),
        new OA\Property(property: 'phone', type: ['string', 'null'], example: '77777777'),
        new OA\Property(property: 'mobile', type: ['string', 'null'], example: '55555555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', example: 'daniel@example.com'),
        new OA\Property(property: 'address', type: ['string', 'null'], example: 'Retalhuleu'),
        new OA\Property(property: 'affiliation_number', type: ['string', 'null'], example: 'ABC-123'),
        new OA\Property(property: 'weight', type: ['string', 'null'], pattern: '^\d{1,3}\.\d{2}$', example: '75.50'),
        new OA\Property(property: 'height', type: ['string', 'null'], pattern: '^\d{1,3}\.\d{2}$', example: '165.00'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Observaciones del paciente'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T00:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T00:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PatientDetailResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/PatientDetail'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpdatePatientInput',
    description: 'Actualización parcial. Debe incluir al menos uno de estos campos editables.',
    properties: [
        new OA\Property(property: 'first_names', type: 'string', maxLength: 75, example: 'Daniel Alejandro'),
        new OA\Property(property: 'last_names', type: 'string', maxLength: 75, example: 'Lara López'),
        new OA\Property(property: 'birth_date', type: ['string', 'null'], format: 'date', example: '1995-08-21'),
        new OA\Property(property: 'gender', type: ['string', 'null'], maxLength: 20, example: 'male'),
        new OA\Property(property: 'phone', type: ['string', 'null'], maxLength: 30, example: '+502 2222-2222'),
        new OA\Property(property: 'mobile', type: ['string', 'null'], maxLength: 30, example: '+502 5555-5555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', maxLength: 150, example: 'daniel@example.com'),
        new OA\Property(property: 'address', type: ['string', 'null'], example: 'Retalhuleu'),
        new OA\Property(property: 'affiliation_number', type: ['string', 'null'], maxLength: 50, example: 'AFF-12345'),
        new OA\Property(property: 'weight', type: ['number', 'null'], maximum: 999.99, exclusiveMinimum: 0, example: 75.5),
        new OA\Property(property: 'height', type: ['number', 'null'], maximum: 999.99, exclusiveMinimum: 0, example: 165.0),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Información actualizada.'),
    ],
    type: 'object',
    minProperties: 1,
)]
#[OA\Schema(
    schema: 'UpdatePatientStatusInput',
    description: 'Cambio explícito del único campo de lifecycle permitido para un paciente.',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
final class PatientDocumentation
{
    // OpenAPI components for Patient endpoints.
}
