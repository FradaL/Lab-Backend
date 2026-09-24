<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'authLogin',
        summary: 'Iniciar sesión',
        description: 'Autentica a la SPA mediante la sesión de Laravel y una cookie HttpOnly. Antes de llamar este endpoint, el cliente debe obtener la cookie CSRF con GET /sanctum/csrf-cookie y enviar cookies/credentials.',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LoginCredentials'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sesión iniciada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LoginSuccessResponse')),
            new OA\Response(response: 419, description: 'Token CSRF ausente, inválido o expirado.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Credenciales incorrectas o datos de entrada inválidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Se excedió el límite de intentos de inicio de sesión.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt([
            ...$request->credentials(),
            'is_active' => true,
        ])) {
            return response()->json([
                'message' => 'Las credenciales proporcionadas son incorrectas.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Sesión iniciada correctamente.',
            'data' => [
                'user' => UserResource::make($request->user()),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'authLogout',
        summary: 'Cerrar sesión',
        description: 'Cierra la sesión autenticada por cookie, invalida la sesión actual y regenera el token CSRF. El cliente debe enviar cookies/credentials.',
        security: [['sanctumCookie' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 419, description: 'Token CSRF ausente, inválido o expirado.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/auth/me',
        operationId: 'authMe',
        summary: 'Obtener el usuario autenticado',
        description: 'Devuelve exclusivamente los datos públicos necesarios del usuario asociado a la sesión de Sanctum.',
        security: [['sanctumCookie' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Usuario autenticado.', content: new OA\JsonContent(ref: '#/components/schemas/CurrentUserResponse')),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => UserResource::make($request->user()),
            ],
        ]);
    }
}
