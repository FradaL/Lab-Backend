<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\ShowDashboardRequest;
use App\Services\Dashboard\BuildDashboardOverview;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class DashboardController extends Controller
{
    #[OA\Get(
        path: '/api/v1/dashboard',
        operationId: 'dashboardShow',
        summary: 'Obtener el resumen operativo del dashboard',
        description: 'Devuelve las métricas del día del laboratorio actual, su comparación contra el día anterior y las cuatro actividades recientes compatibles con el dominio existente. Los límites diarios se calculan en la zona horaria del laboratorio.',
        security: [['sanctumCookie' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'date', description: 'Fecha operativa en la zona horaria del laboratorio. Por defecto es hoy.', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', description: 'Sucursal del laboratorio actual por la que se filtran métricas y actividad.', in: 'query', schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Resumen operativo del dashboard.', content: new OA\JsonContent(ref: '#/components/schemas/DashboardResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'El usuario no tiene permiso, acceso al laboratorio o acceso vigente al SaaS.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'),
                    new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError'),
                    new OA\Schema(ref: '#/components/schemas/ApiErrorResponse'),
                ]),
            ),
            new OA\Response(response: 404, ref: '#/components/responses/LaboratoryNotFound'),
            new OA\Response(response: 422, description: 'Los parámetros de consulta no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function __invoke(
        ShowDashboardRequest $request,
        CurrentLaboratory $currentLaboratory,
        BuildDashboardOverview $buildDashboardOverview,
    ): JsonResponse {
        return response()->json([
            'data' => $buildDashboardOverview->execute(
                $currentLaboratory->get(),
                $request->businessDate($currentLaboratory),
                $request->branchId(),
            ),
        ]);
    }
}
