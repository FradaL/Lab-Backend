<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LaboratoryResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class AvailableLaboratoryController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/laboratories',
        operationId: 'authLaboratories',
        summary: 'Listar laboratorios disponibles',
        description: 'Devuelve los laboratorios activos donde el usuario autenticado tiene una membresía activa. Este endpoint no requiere X-Laboratory-ID porque permite seleccionar el contexto.',
        security: [['sanctumCookie' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Laboratorios disponibles para el usuario.', content: new OA\JsonContent(ref: '#/components/schemas/AvailableLaboratoriesResponse')),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $laboratories = $user->laboratories()
            ->where('laboratories.is_active', true)
            ->wherePivot('is_active', true)
            ->orderBy('laboratories.name')
            ->get();

        return LaboratoryResource::collection($laboratories);
    }
}
