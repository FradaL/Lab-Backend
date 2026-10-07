<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CurrentAuthorizationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/authorization',
        operationId: 'currentAuthorization',
        summary: 'Obtener la autorización efectiva',
        description: 'Devuelve los roles y permisos efectivos del usuario autenticado dentro del laboratorio seleccionado mediante X-Laboratory-ID. El frontend debe usar permissions para construir la UX; las rutas protegidas del backend continúan siendo la autoridad de seguridad.',
        security: [['sanctumCookie' => []]],
        tags: ['Authentication'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader')],
        responses: [
            new OA\Response(response: 200, description: 'Contexto efectivo de autorización.', content: new OA\JsonContent(ref: '#/components/schemas/CurrentAuthorizationResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'),
                    new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError'),
                ]),
            ),
            new OA\Response(response: 404, ref: '#/components/responses/LaboratoryNotFound'),
        ],
    )]
    public function __invoke(Request $request, CurrentLaboratory $currentLaboratory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $laboratory = $currentLaboratory->get();

        return response()->json([
            'data' => [
                'laboratory' => [
                    'id' => $laboratory->id,
                    'name' => $laboratory->name,
                ],
                'roles' => $user->getRoleNames()
                    ->sort()
                    ->values()
                    ->all(),
                'permissions' => $user->getAllPermissions()
                    ->pluck('name')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
