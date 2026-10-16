<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use App\UserAdministration\LaboratoryUserManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LaboratoryRoleController extends Controller
{
    #[OA\Get(
        path: '/api/v1/laboratory/roles',
        operationId: 'laboratoryRolesIndex',
        summary: 'Listar roles asignables',
        description: 'Devuelve solo roles que el actor puede asignar dentro del laboratorio activo. Requiere roles.view.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory users'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader')],
        responses: [
            new OA\Response(response: 200, description: 'Roles asignables.', content: new OA\JsonContent(ref: '#/components/schemas/AssignableRoleListResponse')),
            new OA\Response(response: 401, description: 'Sin sesión autenticada.'),
            new OA\Response(response: 403, description: 'Sin acceso o permiso.'),
        ],
    )]
    public function __invoke(Request $request, CurrentLaboratory $current, LaboratoryUserManager $manager): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (string $role): array => ['name' => $role, 'assignable' => true],
                $manager->assignableRoles($current->get(), $request->user()),
            ),
        ]);
    }
}
