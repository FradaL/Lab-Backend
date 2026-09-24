<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class HealthCheckController extends Controller
{
    #[OA\Get(
        path: '/api/v1/health',
        operationId: 'healthCheck',
        summary: 'Comprobar el estado de la API',
        description: 'Confirma que la API de Donqer Lab está disponible.',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'La API está disponible.',
                content: new OA\JsonContent(
                    required: ['status'],
                    properties: [
                        new OA\Property(
                            property: 'status',
                            type: 'string',
                            example: 'ok',
                        ),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ]);
    }
}
