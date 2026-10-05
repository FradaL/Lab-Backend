<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\Actions\LaboratoryOrders\RemoveExamFromLaboratoryOrder;
use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryOrder\AddExamToLaboratoryOrderRequest;
use App\Http\Requests\Api\V1\LaboratoryOrder\ListLaboratoryOrderExamsRequest;
use App\Http\Requests\Api\V1\LaboratoryOrder\ShowLaboratoryOrderRequest;
use App\Http\Requests\Api\V1\LaboratoryOrder\StoreLaboratoryOrderRequest;
use App\Http\Requests\Api\V1\LaboratoryOrder\UpdateLaboratoryOrderStatusRequest;
use App\Http\Resources\Api\V1\LaboratoryOrderExamResource;
use App\Http\Resources\Api\V1\LaboratoryOrderResource;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

final class LaboratoryOrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/laboratory-orders/{laboratoryOrder}/exams',
        operationId: 'laboratoryOrdersListExams',
        summary: 'Listar las solicitudes de examen de una orden',
        description: 'Devuelve todas las líneas históricas de la orden sin paginación, ordenadas por LaboratoryOrderExam.id ascendente. Preserva solicitudes repetidas y usa exclusivamente los snapshots persistidos de código, nombre, lista y precio. La consulta está disponible para cualquier estado de la orden.',
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
            new OA\Response(response: 200, description: 'Colección completa de solicitudes, posiblemente vacía.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryOrderExamCollectionResponse')),
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
            new OA\Response(response: 422, description: 'La URL contiene parámetros de consulta no permitidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function listExams(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $laboratoryOrder,
        ListLaboratoryOrderExamsRequest $listRequest,
    ): AnonymousResourceCollection {
        $laboratory = $currentLaboratory->get();
        $order = LaboratoryOrder::forLaboratory($laboratory)
            ->whereKey($laboratoryOrder)
            ->first();

        if ($order === null) {
            abort(404, 'Resource not found.');
        }

        $listRequest->validated($request);

        $orderExams = LaboratoryOrderExam::forLaboratory($laboratory)
            ->where('laboratory_order_id', $order->id)
            ->orderBy('id')
            ->get();

        return LaboratoryOrderExamResource::collection($orderExams);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{laboratoryOrder}/exams',
        operationId: 'laboratoryOrdersAddExam',
        summary: 'Agregar una solicitud de examen a una orden',
        description: 'Agrega una nueva línea independiente a una orden pendiente. La operación permite repetir el mismo examen, bloquea la orden para serializarse con cambios de estado y captura los valores vigentes del examen, lista y precio como snapshots.',
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
            content: new OA\JsonContent(ref: '#/components/schemas/AddLaboratoryOrderExamInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Solicitud de examen creada con snapshots autoritativos.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryOrderExamResponse')),
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
            new OA\Response(response: 422, description: 'El payload o las reglas operativas de la orden y sus catálogos no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function addExam(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $laboratoryOrder,
        AddExamToLaboratoryOrderRequest $examRequest,
        AddExamToLaboratoryOrder $addExam,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $exists = LaboratoryOrder::forLaboratory($laboratory)
            ->whereKey($laboratoryOrder)
            ->exists();

        if (! $exists) {
            abort(404, 'Resource not found.');
        }

        $laboratoryExamId = $examRequest->validated($request)['laboratory_exam_id'];
        $orderExam = $addExam->execute($laboratory, $laboratoryOrder, $laboratoryExamId);

        return LaboratoryOrderExamResource::make($orderExam)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}',
        operationId: 'laboratoryOrdersRemoveExam',
        summary: 'Eliminar una solicitud específica de examen de una orden',
        description: 'Elimina físicamente una línea individual mientras la orden está pendiente. laboratoryOrderExam identifica LaboratoryOrderExam.id, no el identificador del examen de catálogo; por ello, eliminar una repetición no afecta otras solicitudes del mismo examen. La operación se serializa con los cambios de estado mediante un bloqueo de la orden.',
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
            new OA\Parameter(
                name: 'laboratoryOrderExam',
                description: 'Identificador de la solicitud individual LaboratoryOrderExam; no es el ID del LaboratoryExam de catálogo.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Solicitud de examen eliminada físicamente.'),
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
            new OA\Response(response: 404, description: 'La orden o la solicitud de examen no existe dentro del laboratorio y orden actuales.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'La orden ya no está pendiente o el body contiene campos no permitidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function removeExam(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $laboratoryOrder,
        int $laboratoryOrderExam,
        RemoveExamFromLaboratoryOrder $removeExam,
    ): Response {
        $laboratory = $currentLaboratory->get();
        $exists = LaboratoryOrder::forLaboratory($laboratory)
            ->whereKey($laboratoryOrder)
            ->exists();

        if (! $exists) {
            abort(404, 'Resource not found.');
        }

        $errors = collect(array_keys($request->all()))
            ->mapWithKeys(fn (string $field): array => [$field => ['El campo no está permitido.']])
            ->all();

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $removeExam->execute($laboratory, $laboratoryOrder, $laboratoryOrderExam);

        return response()->noContent();
    }

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
