<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Doctors',
    description: 'Médicos pertenecientes al laboratorio activo.',
)]
#[OA\Schema(
    schema: 'DoctorListItem',
    required: ['id', 'first_names', 'last_names', 'specialty', 'phone', 'email', 'license_number', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'first_names', type: 'string', example: 'Juan Carlos'),
        new OA\Property(property: 'last_names', type: 'string', example: 'Pérez López'),
        new OA\Property(property: 'specialty', type: ['string', 'null'], example: 'Cardiología'),
        new OA\Property(property: 'phone', type: ['string', 'null'], example: '+502 5555-5555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', example: 'juan.perez@example.com'),
        new OA\Property(property: 'license_number', type: ['string', 'null'], example: 'COL-12345'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T12:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DoctorListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DoctorListItem')),
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
    schema: 'StoreDoctorInput',
    required: ['first_names', 'last_names'],
    properties: [
        new OA\Property(property: 'first_names', type: 'string', maxLength: 125, example: 'Juan Carlos'),
        new OA\Property(property: 'last_names', type: 'string', maxLength: 125, example: 'Pérez López'),
        new OA\Property(property: 'specialty', type: ['string', 'null'], maxLength: 125, example: 'Cardiología'),
        new OA\Property(property: 'phone', type: ['string', 'null'], maxLength: 30, example: '+502 5555-5555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', maxLength: 150, example: 'juan.perez@example.com'),
        new OA\Property(property: 'license_number', type: ['string', 'null'], maxLength: 30, example: 'COL-12345'),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Atiende únicamente con cita previa.'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'DoctorResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/DoctorListItem'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DoctorDetail',
    required: ['id', 'first_names', 'last_names', 'specialty', 'phone', 'email', 'license_number', 'status', 'notes', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
        new OA\Property(property: 'first_names', type: 'string', example: 'Juan Carlos'),
        new OA\Property(property: 'last_names', type: 'string', example: 'Pérez López'),
        new OA\Property(property: 'specialty', type: ['string', 'null'], example: 'Cardiología'),
        new OA\Property(property: 'phone', type: ['string', 'null'], example: '+502 5555-5555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', example: 'juan@example.com'),
        new OA\Property(property: 'license_number', type: ['string', 'null'], example: 'COL-12345'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Médico referente.'),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T20:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time', example: '2026-09-23T20:00:00.000000Z'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DoctorDetailResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/DoctorDetail'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpdateDoctorInput',
    description: 'Actualización parcial. Debe incluir al menos uno de estos campos editables.',
    properties: [
        new OA\Property(property: 'first_names', type: 'string', maxLength: 125, example: 'Juan Carlos'),
        new OA\Property(property: 'last_names', type: 'string', maxLength: 125, example: 'Pérez López'),
        new OA\Property(property: 'specialty', type: ['string', 'null'], maxLength: 125, example: 'Medicina Interna'),
        new OA\Property(property: 'phone', type: ['string', 'null'], maxLength: 30, example: '+502 5555-5555'),
        new OA\Property(property: 'email', type: ['string', 'null'], format: 'email', maxLength: 150, example: 'juan@example.com'),
        new OA\Property(property: 'license_number', type: ['string', 'null'], maxLength: 30, example: 'COL-12345'),
        new OA\Property(property: 'notes', type: ['string', 'null'], example: 'Información actualizada.'),
    ],
    type: 'object',
    minProperties: 1,
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateDoctorStatusInput',
    description: 'Cambio explícito del único campo de lifecycle permitido para un médico.',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
    ],
    type: 'object',
    additionalProperties: false,
)]
final class DoctorDocumentation
{
    // OpenAPI components for Doctor endpoints.
}
