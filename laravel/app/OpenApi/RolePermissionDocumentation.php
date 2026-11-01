<?php

namespace App\OpenApi;

use App\Authorization\RbacCatalog;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OperationalPermission', required: ['code', 'group', 'label'], type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string', enum: RbacCatalog::PERMISSIONS),
        new OA\Property(property: 'group', type: 'string', example: 'orders'),
        new OA\Property(property: 'label', type: 'string', example: 'orders manage discount'),
    ],
)]
#[OA\Schema(
    schema: 'RolePermissionMatrix', required: ['id', 'name', 'permissions', 'revision', 'protected', 'editable'], type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'name', type: 'string', enum: RbacCatalog::ROLES),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'revision', type: 'integer', minimum: 1),
        new OA\Property(property: 'protected', type: 'boolean'),
        new OA\Property(property: 'editable', type: 'boolean', description: 'El actor ya está autorizado por roles.permissions.manage; false para owner y administrator.'),
    ],
)]
#[OA\Schema(
    schema: 'ReplaceRolePermissions', required: ['expected_revision', 'permissions', 'reason'], type: 'object', additionalProperties: false,
    properties: [
        new OA\Property(property: 'expected_revision', type: 'integer', minimum: 1, example: 4),
        new OA\Property(property: 'permissions', type: 'array', uniqueItems: true, items: new OA\Items(type: 'string', enum: RbacCatalog::PERMISSIONS), example: ['orders.view', 'patients.view', 'roles.permissions.manage']),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, pattern: '\\S', example: 'Actualización de responsabilidades'),
    ],
)]
final class RolePermissionDocumentation
{
    #[OA\Get(
        path: '/api/v1/laboratory/permissions', operationId: 'operationalPermissionsIndex', tags: ['Role permissions'],
        summary: 'Consultar catálogo operativo vigente',
        description: 'Requiere roles.permissions.manage, membresía activa y suscripción. No contiene capacidades SaaS. Agrupación por primer segmento; etiqueta generada del código.',
        security: [['sanctumCookie' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader')],
        responses: [
            new OA\Response(response: 200, description: 'Catálogo operativo.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationalPermission'))])),
            new OA\Response(response: 400, description: 'Falta X-Laboratory-ID o su formato es inválido.'),
            new OA\Response(response: 401, description: 'Sin autenticación.'),
            new OA\Response(response: 403, description: 'Sin membresía, suscripción o permiso efectivo.'),
            new OA\Response(response: 404, description: 'Laboratorio inexistente.'),
        ],
    )]
    public function index(): void {}

    #[OA\Get(
        path: '/api/v1/laboratory/roles/{role}/permissions', operationId: 'rolePermissionsShow', tags: ['Role permissions'],
        summary: 'Consultar matriz y revisión del rol', description: 'Requiere roles.permissions.manage. Resuelve nombre o ID exclusivamente dentro del laboratorio activo. Permite consultar roles protegidos.',
        security: [['sanctumCookie' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'viewer')],
        responses: [
            new OA\Response(response: 200, description: 'Matriz actual.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RolePermissionMatrix')])),
            new OA\Response(response: 400, description: 'Contexto inválido o ausente.'),
            new OA\Response(response: 401, description: 'Sin autenticación.'),
            new OA\Response(response: 403, description: 'Sin acceso o permiso efectivo.'),
            new OA\Response(response: 404, description: 'Rol no encontrado en el laboratorio activo.'),
        ],
    )]
    public function show(): void {}

    #[OA\Put(
        path: '/api/v1/laboratory/roles/{role}/permissions', operationId: 'rolePermissionsReplace', tags: ['Role permissions'],
        summary: 'Reemplazar por completo los permisos de un rol configurable',
        description: 'Requiere solo roles.permissions.manage, sin restricción por rol del actor. Puede delegar o revocar ese mismo permiso. owner y administrator protegidos. Lista vacía retira todos los permisos del rol, conservando permisos efectivos por otros roles o asignación directa. Cada escritura aceptada incrementa revisión, incluso sin diferencias. Auditoría y pivotes participan en una transacción.',
        security: [['sanctumCookie' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'viewer')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReplaceRolePermissions')),
        responses: [
            new OA\Response(response: 200, description: 'Matriz y nueva revisión.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RolePermissionMatrix')])),
            new OA\Response(response: 400, description: 'Contexto inválido o ausente.'),
            new OA\Response(response: 401, description: 'Sin autenticación.'),
            new OA\Response(response: 403, description: 'Sin acceso, sin permiso efectivo o rol protegido.'),
            new OA\Response(response: 404, description: 'Rol ajeno o inexistente.'),
            new OA\Response(response: 409, description: 'role_permissions_revision_conflict: recargar matriz antes de reintentar.'),
            new OA\Response(response: 422, description: 'Revisión/motivo inválidos; permiso desconocido, SaaS o duplicado; campo no permitido; catálogo no aprovisionado.'),
            new OA\Response(response: 500, description: 'Fallo interno o de auditoría: se revierte la transacción.'),
        ],
    )]
    public function update(): void {}
}
