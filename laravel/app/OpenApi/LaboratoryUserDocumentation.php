<?php

namespace App\OpenApi;

use App\Authorization\RbacCatalog;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Laboratory users',
    description: 'Administración tenant-aware de identidades, membresías, roles e invitaciones.',
)]
#[OA\Schema(
    schema: 'LaboratoryUserMembership',
    required: ['id', 'status', 'is_default', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 8),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'is_default', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryUserValidationProfessional',
    required: ['id', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'status', type: 'string', enum: ['registered', 'authorized', 'revoked'], example: 'authorized'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryUser',
    required: ['id', 'name', 'email', 'membership', 'roles', 'validation_professional', 'can_register_validation_professional'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
        new OA\Property(property: 'name', type: 'string', example: 'Ana López'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
        new OA\Property(property: 'membership', ref: '#/components/schemas/LaboratoryUserMembership'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', enum: RbacCatalog::ROLES)),
        new OA\Property(property: 'validation_professional', oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryUserValidationProfessional'), new OA\Schema(type: 'null')]),
        new OA\Property(property: 'can_register_validation_professional', type: 'boolean', example: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryUserResponse',
    required: ['data'],
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LaboratoryUser')],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LaboratoryUserListResponse',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LaboratoryUser')),
        new OA\Property(property: 'links', type: 'object'),
        new OA\Property(property: 'meta', type: 'object'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'InviteLaboratoryUserInput',
    required: ['name', 'email', 'role'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Ana López'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'ana@example.com'),
        new OA\Property(property: 'role', type: 'string', enum: RbacCatalog::ROLES, example: 'laboratory_technician'),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'LaboratoryUserInvitationResponse',
    required: ['message', 'data'],
    properties: [
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(
            property: 'data',
            required: ['invitation_id', 'status', 'expires_at'],
            properties: [
                new OA\Property(property: 'invitation_id', type: 'integer', format: 'int64'),
                new OA\Property(property: 'status', type: 'string', enum: ['pending']),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AcceptLaboratoryUserInvitationInput',
    description: 'Solo para una identidad nueva. Una identidad existente debe autenticarse y puede enviar un objeto vacío.',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Ana López'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 12, writeOnly: true),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', minLength: 12, writeOnly: true),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateLaboratoryUserRoleInput',
    required: ['role'],
    properties: [new OA\Property(property: 'role', type: 'string', enum: RbacCatalog::ROLES, example: 'receptionist')],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'UpdateLaboratoryUserStatusInput',
    required: ['status'],
    properties: [new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive')],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'AssignableRoleListResponse',
    required: ['data'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(
                required: ['name', 'assignable'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', enum: RbacCatalog::ROLES),
                    new OA\Property(property: 'assignable', type: 'boolean', example: true),
                ],
                type: 'object',
            ),
        ),
    ],
    type: 'object',
)]
final class LaboratoryUserDocumentation
{
    // OpenAPI components for tenant-aware user administration.
}
