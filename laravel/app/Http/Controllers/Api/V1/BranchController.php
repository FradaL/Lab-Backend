<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Branch\ActiveBranchRequest;
use App\Http\Resources\Api\V1\ActiveBranchResource;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

final class BranchController extends Controller
{
    private const ACTIVE_STATUS = 'active';

    #[OA\Get(
        path: '/api/v1/branches/active',
        operationId: 'branchesActive',
        summary: 'Listar sucursales activas',
        description: 'Devuelve un catálogo ligero, completo, no paginado y ordenado de las sucursales activas del laboratorio actual.',
        security: [['sanctumCookie' => []]],
        tags: ['Branches'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Catálogo activo de sucursales.', content: new OA\JsonContent(ref: '#/components/schemas/ActiveBranchCollection')),
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
            new OA\Response(response: 404, description: 'El laboratorio solicitado no existe.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El endpoint no acepta parámetros de consulta.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function active(
        ActiveBranchRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $request->validated();

        $branches = $currentLaboratory->get()
            ->branches()
            ->select(['id', 'code', 'name', 'is_main'])
            ->where('status', self::ACTIVE_STATUS)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ActiveBranchResource::collection($branches);
    }
}
