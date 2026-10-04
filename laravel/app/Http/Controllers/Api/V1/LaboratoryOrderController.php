<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryOrder\ShowLaboratoryOrderRequest;
use App\Http\Requests\Api\V1\LaboratoryOrder\StoreLaboratoryOrderRequest;
use App\Http\Requests\Api\V1\LaboratoryOrder\UpdateLaboratoryOrderStatusRequest;
use App\Http\Resources\Api\V1\LaboratoryOrderResource;
use App\Models\LaboratoryOrder;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class LaboratoryOrderController extends Controller
{
    #[OA\Patch(
        path: '/api/v1/laboratory-orders/{laboratoryOrder}/status',
        operationId: 'laboratoryOrdersUpdateStatus',
        summary: 'Cambiar estado global de una orden de laboratorio',
        description: 'Ejecuta el workflow explícito de la orden. Permite pending a in_process o cancelled, e in_process a completed o cancelled. Repetir el estado actual es un no-op idempotente. Completed y cancelled son terminales. La transición se serializa mediante un row lock tenant-aware.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Orders'],
        parameters: [
            new OA\Parameter(
                name: 'laboratoryOrder',
                description: 'Identificador de la orden dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLaboratoryOrderStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Transición aplicada o estado ya establecido.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryOrderResponse')),
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
            new OA\Response(response: 404, description: 'La orden no existe o no pertenece al laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El payload no es válido o la transición solicitada no está permitida.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $laboratoryOrder,
        UpdateLaboratoryOrderStatusRequest $statusRequest,
        TransitionLaboratoryOrderStatus $transitionStatus,
    ): LaboratoryOrderResource {
        $laboratory = $currentLaboratory->get();
        $exists = LaboratoryOrder::forLaboratory($laboratory)
            ->whereKey($laboratoryOrder)
            ->exists();

        if (! $exists) {
            abort(404, 'Resource not found.');
        }

        $targetStatus = $statusRequest->validated($request)['status'];
        $order = $transitionStatus->execute($laboratory, $laboratoryOrder, $targetStatus);

        return LaboratoryOrderResource::make($order);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-orders/{laboratoryOrder}',
        operationId: 'laboratoryOrdersShow',
        summary: 'Consultar orden de laboratorio',
        description: 'Devuelve una orden únicamente cuando pertenece al laboratorio validado por el pipeline SaaS. Un identificador inexistente o perteneciente a otro laboratorio produce el mismo 404 neutral. Las referencias históricas se muestran aunque sus catálogos estén inactivos.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Orders'],
        parameters: [
            new OA\Parameter(
                name: 'laboratoryOrder',
                description: 'Identificador de la orden dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle de la orden de laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryOrderResponse')),
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
            new OA\Response(response: 404, description: 'La orden no existe o no pertenece al laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El endpoint no acepta parámetros de consulta.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function show(
        ShowLaboratoryOrderRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $laboratoryOrder,
    ): LaboratoryOrderResource {
        $request->validated();

        $order = LaboratoryOrder::forLaboratory($currentLaboratory->get())
            ->with([
                'patient',
                'doctor',
                'commercialClient',
                'priceList',
                'branch',
                'createdBy',
            ])
            ->whereKey($laboratoryOrder)
            ->first();

        if ($order === null) {
            abort(404, 'Resource not found.');
        }

        return LaboratoryOrderResource::make($order);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders',
        operationId: 'laboratoryOrdersStore',
        summary: 'Crear orden de laboratorio',
        description: 'Crea una orden pendiente en el laboratorio actual. La lista de precios es una selección explícita; ordered_at se interpreta como fecha y hora local conforme al contrato Y-m-d H:i:s y se persiste sin conversión de zona horaria.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Orders'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateLaboratoryOrderInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Orden creada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryOrderResponse')),
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
            new OA\Response(response: 422, description: 'El payload, las referencias o sus estados operativos no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StoreLaboratoryOrderRequest $request,
        CurrentLaboratory $currentLaboratory,
        CreateLaboratoryOrder $createLaboratoryOrder,
    ): JsonResponse {
        /** @var User $creator */
        $creator = $request->user();

        $order = $createLaboratoryOrder->execute(
            $currentLaboratory->get(),
            $creator,
            $request->validated(),
        );

        return LaboratoryOrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }
}
