<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Donqer Lab API',
    description: 'API REST para la plataforma Donqer Lab.',
)]
#[OA\Server(
    url: '/',
    description: 'Servidor actual de la aplicación',
)]
#[OA\Tag(
    name: 'System',
    description: 'Estado y diagnóstico del sistema',
)]
#[OA\Tag(
    name: 'Authentication',
    description: 'Autenticación SPA mediante Laravel Sanctum, sesión, cookies HttpOnly y CSRF. Swagger UI envía credentials y agrega automáticamente X-XSRF-TOKEN desde la cookie obtenida mediante /sanctum/csrf-cookie.',
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctumCookie',
    type: 'apiKey',
    name: 'donqer-lab-session',
    in: 'cookie',
    description: 'Cookie HttpOnly de sesión para la autenticación SPA de Laravel Sanctum. Las solicitudes que modifican estado también requieren protección CSRF.',
)]
#[OA\Get(
    path: '/sanctum/csrf-cookie',
    operationId: 'sanctumCsrfCookie',
    summary: 'Inicializar la protección CSRF de la SPA',
    description: 'Endpoint proporcionado por Laravel Sanctum. Debe solicitarse con credentials/cookies antes del login cuando sea necesario inicializar la sesión CSRF. Sanctum establece la cookie XSRF-TOKEN y el navegador recibe o mantiene las cookies correspondientes según la configuración de Laravel. El token no se devuelve mediante JSON. Axios puede enviar automáticamente el valor de XSRF-TOKEN en el encabezado X-XSRF-TOKEN cuando está configurado con credentials.',
    tags: ['Authentication'],
    responses: [
        new OA\Response(
            response: 204,
            description: 'Cookies CSRF y de sesión inicializadas. La respuesta no contiene body.',
            headers: [
                new OA\Header(
                    header: 'Set-Cookie',
                    description: 'Incluye XSRF-TOKEN y las cookies correspondientes según la configuración de sesión de Laravel.',
                    schema: new OA\Schema(type: 'string'),
                ),
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'AuthenticatedUser',
    required: ['id', 'name', 'email'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Administrador Demo'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@donqerlab.test'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AvailableLaboratory',
    required: ['id', 'name', 'legal_name', 'timezone', 'currency', 'is_default'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Laboratorio Demo Donqer'),
        new OA\Property(property: 'legal_name', type: ['string', 'null'], example: 'Laboratorio Demo Donqer, S.A.'),
        new OA\Property(property: 'timezone', type: 'string', example: 'America/Guatemala'),
        new OA\Property(property: 'currency', type: 'string', example: 'GTQ'),
        new OA\Property(property: 'is_default', type: 'boolean', example: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AvailableLaboratoriesResponse',
    required: ['data'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AvailableLaboratory'),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LoginCredentials',
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@donqerlab.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', writeOnly: true, example: 'password'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LoginSuccessResponse',
    required: ['message', 'data'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Sesión iniciada correctamente.'),
        new OA\Property(
            property: 'data',
            required: ['user'],
            properties: [new OA\Property(property: 'user', ref: '#/components/schemas/AuthenticatedUser')],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CurrentUserResponse',
    required: ['data'],
    properties: [
        new OA\Property(
            property: 'data',
            required: ['user'],
            properties: [new OA\Property(property: 'user', ref: '#/components/schemas/AuthenticatedUser')],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'MessageResponse',
    required: ['message'],
    properties: [new OA\Property(property: 'message', type: 'string', example: 'Sesión cerrada correctamente.')],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ApiErrorResponse',
    required: ['message'],
    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ApiValidationErrorResponse',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Las credenciales proporcionadas son incorrectas.'),
        new OA\Property(
            property: 'errors',
            description: 'Errores por campo cuando falla la validación de entrada.',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
        ),
    ],
    type: 'object',
)]
final class ApiDocumentation
{
    // OpenAPI metadata discovered by swagger-php.
}
