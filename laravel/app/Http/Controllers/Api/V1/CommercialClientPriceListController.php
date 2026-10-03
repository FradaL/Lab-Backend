<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CommercialClientPriceList\StoreCommercialClientPriceListRequest;
use App\Http\Requests\Api\V1\CommercialClientPriceList\UpdateCommercialClientPriceListRequest;
use App\Http\Requests\Api\V1\CommercialClientPriceList\UpdateCommercialClientPriceListStatusRequest;
use App\Http\Resources\Api\V1\CommercialClientPriceListResource;
use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\PriceList;
use App\Tenancy\CurrentLaboratory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CommercialClientPriceListController extends Controller
{
    #[OA\Post(
        path: '/api/v1/commercial-clients/{commercialClient}/price-list-assignments',
        operationId: 'commercialClientPriceListAssignmentsStore',
        summary: 'Programar lista de precios para una entidad comercial',
        description: 'Crea una asignación activa sin solapar otro período activo de la misma entidad comercial. La entidad comercial se resuelve dentro del laboratorio antes de validar el payload; una lista de precios inválida, inactiva o ajena al laboratorio produce un error de validación.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Client Price List Assignments'],
        parameters: [
            new OA\Parameter(
                name: 'commercialClient',
                description: 'Identificador de la entidad comercial dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCommercialClientPriceListInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Asignación creada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientPriceListResponse')),
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
            new OA\Response(response: 404, description: 'La entidad comercial no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El payload, el estado de los recursos o el período de asignación no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $commercialClient,
        StoreCommercialClientPriceListRequest $storeRequest,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $resolvedCommercialClient = CommercialClient::forLaboratory($laboratory)
            ->whereKey($commercialClient)
            ->first();

        if ($resolvedCommercialClient === null) {
            abort(404, 'Resource not found.');
        }

        if ($resolvedCommercialClient->status !== CommercialClient::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'commercial_client' => ['La entidad comercial debe estar activa.'],
            ]);
        }

        $attributes = $storeRequest->validated($request);
        $priceList = PriceList::forLaboratory($laboratory)
            ->whereKey($attributes['price_list_id'])
            ->first();

        if ($priceList === null) {
            throw ValidationException::withMessages([
                'price_list_id' => ['La lista de precios seleccionada no es válida.'],
            ]);
        }

        if ($priceList->status !== PriceList::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'price_list_id' => ['La lista de precios seleccionada debe estar activa.'],
            ]);
        }

        if ($this->activePeriodOverlaps(
            $resolvedCommercialClient,
            (int) $laboratory->getKey(),
            $attributes['starts_at'],
            $attributes['ends_at'],
        )) {
            $this->throwPeriodValidationException();
        }

        try {
            $assignment = $resolvedCommercialClient->priceListAssignments()->create([
                'laboratory_id' => $laboratory->getKey(),
                'price_list_id' => $priceList->getKey(),
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'status' => CommercialClientPriceList::STATUS_ACTIVE,
            ]);
        } catch (QueryException $exception) {
            $this->convertPeriodConstraintViolation($exception);
        }

        $assignment->setRelation('commercialClient', $resolvedCommercialClient);
        $assignment->setRelation('priceList', $priceList);

        return CommercialClientPriceListResource::make($assignment)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/commercial-clients/{commercialClient}/price-list-assignments/{assignment}',
        operationId: 'commercialClientPriceListAssignmentsUpdate',
        summary: 'Editar una asignación de lista de precios',
        description: 'Actualiza parcialmente la lista o el período de una asignación existente. La entidad comercial y la asignación se resuelven dentro del laboratorio antes de validar el payload. El estado de la asignación no se modifica.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Client Price List Assignments'],
        parameters: [
            new OA\Parameter(
                name: 'commercialClient',
                description: 'Identificador de la entidad comercial dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(
                name: 'assignment',
                description: 'Identificador de la asignación perteneciente a la entidad comercial.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCommercialClientPriceListInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Asignación actualizada correctamente o sin cambios efectivos.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientPriceListResponse')),
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
            new OA\Response(response: 404, description: 'La entidad comercial o la asignación no existe dentro del alcance solicitado.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El payload, la lista de precios o el período resultante no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $commercialClient,
        int $assignment,
        UpdateCommercialClientPriceListRequest $updateRequest,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $resolvedCommercialClient = CommercialClient::forLaboratory($laboratory)
            ->whereKey($commercialClient)
            ->first();

        if ($resolvedCommercialClient === null) {
            abort(404, 'Resource not found.');
        }

        $resolvedAssignment = CommercialClientPriceList::forLaboratory($laboratory)
            ->where('commercial_client_id', $resolvedCommercialClient->getKey())
            ->whereKey($assignment)
            ->first();

        if ($resolvedAssignment === null) {
            abort(404, 'Resource not found.');
        }

        $attributes = $updateRequest->validated($request, $resolvedAssignment);
        $currentValues = [
            'price_list_id' => (int) $resolvedAssignment->price_list_id,
            'starts_at' => $resolvedAssignment->starts_at->toDateString(),
            'ends_at' => $resolvedAssignment->ends_at?->toDateString(),
        ];
        $changes = [];

        foreach ($attributes as $field => $value) {
            if ($value !== $currentValues[$field]) {
                $changes[$field] = $value;
            }
        }

        $priceList = null;
        if (array_key_exists('price_list_id', $changes)) {
            $priceList = PriceList::forLaboratory($laboratory)
                ->whereKey($changes['price_list_id'])
                ->first();

            if ($priceList === null) {
                throw ValidationException::withMessages([
                    'price_list_id' => ['La lista de precios seleccionada no es válida.'],
                ]);
            }

            if ($priceList->status !== PriceList::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'price_list_id' => ['La lista de precios seleccionada debe estar activa.'],
                ]);
            }
        }

        $periodChanged = array_key_exists('starts_at', $changes) || array_key_exists('ends_at', $changes);
        if (
            $periodChanged
            && $resolvedAssignment->status === CommercialClientPriceList::STATUS_ACTIVE
            && $this->activePeriodOverlaps(
                $resolvedCommercialClient,
                (int) $laboratory->getKey(),
                $changes['starts_at'] ?? $currentValues['starts_at'],
                array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $currentValues['ends_at'],
                (int) $resolvedAssignment->getKey(),
            )
        ) {
            $this->throwPeriodValidationException();
        }

        if ($changes !== []) {
            try {
                $resolvedAssignment->fill($changes)->save();
            } catch (QueryException $exception) {
                $this->convertPeriodConstraintViolation($exception);
            }
        }

        $priceList ??= $resolvedAssignment->priceList()->firstOrFail();
        $resolvedAssignment->setRelation('commercialClient', $resolvedCommercialClient);
        $resolvedAssignment->setRelation('priceList', $priceList);

        return CommercialClientPriceListResource::make($resolvedAssignment)->response();
    }

    #[OA\Patch(
        path: '/api/v1/commercial-clients/{commercialClient}/price-list-assignments/{assignment}/status',
        operationId: 'commercialClientPriceListAssignmentsUpdateStatus',
        summary: 'Cambiar estado de una asignación de lista de precios',
        description: 'Cambia exclusivamente el estado de una asignación existente. Una reactivación conserva su lista de precios histórica, aunque actualmente esté inactiva, y sólo procede si el período no se solapa con otra asignación activa.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Client Price List Assignments'],
        parameters: [
            new OA\Parameter(
                name: 'commercialClient',
                description: 'Identificador de la entidad comercial dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(
                name: 'assignment',
                description: 'Identificador de la asignación perteneciente a la entidad comercial.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCommercialClientPriceListStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado correctamente o ya establecido.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientPriceListResponse')),
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
            new OA\Response(response: 404, description: 'La entidad comercial o la asignación no existe dentro del alcance solicitado.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado, el payload o la reactivación solicitada no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $commercialClient,
        int $assignment,
        UpdateCommercialClientPriceListStatusRequest $statusRequest,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $resolvedCommercialClient = CommercialClient::forLaboratory($laboratory)
            ->whereKey($commercialClient)
            ->first();

        if ($resolvedCommercialClient === null) {
            abort(404, 'Resource not found.');
        }

        $resolvedAssignment = CommercialClientPriceList::forLaboratory($laboratory)
            ->join('price_lists as response_price_lists', function (JoinClause $join): void {
                $join
                    ->on('response_price_lists.id', '=', 'commercial_client_price_lists.price_list_id')
                    ->on('response_price_lists.laboratory_id', '=', 'commercial_client_price_lists.laboratory_id');
            })
            ->where('commercial_client_price_lists.commercial_client_id', $resolvedCommercialClient->getKey())
            ->whereKey($assignment)
            ->select('commercial_client_price_lists.*')
            ->addSelect(
                'response_price_lists.id as response_price_list_id',
                'response_price_lists.name as response_price_list_name',
                'response_price_lists.currency as response_price_list_currency',
            )
            ->first();

        if ($resolvedAssignment === null) {
            abort(404, 'Resource not found.');
        }

        $requestedStatus = $statusRequest->validated($request)['status'];
        $priceList = new PriceList;
        $priceList->setRawAttributes([
            'id' => (int) $resolvedAssignment->getAttribute('response_price_list_id'),
            'name' => (string) $resolvedAssignment->getAttribute('response_price_list_name'),
            'currency' => (string) $resolvedAssignment->getAttribute('response_price_list_currency'),
        ], true);
        $resolvedAssignment->setRelation('commercialClient', $resolvedCommercialClient);
        $resolvedAssignment->setRelation('priceList', $priceList);

        if ($requestedStatus === $resolvedAssignment->status) {
            return CommercialClientPriceListResource::make($resolvedAssignment)->response();
        }

        if (
            $requestedStatus === CommercialClientPriceList::STATUS_ACTIVE
            && $this->activePeriodOverlaps(
                $resolvedCommercialClient,
                (int) $laboratory->getKey(),
                $resolvedAssignment->starts_at->toDateString(),
                $resolvedAssignment->ends_at?->toDateString(),
                (int) $resolvedAssignment->getKey(),
            )
        ) {
            $this->throwPeriodValidationException();
        }

        try {
            $resolvedAssignment->status = $requestedStatus;
            $resolvedAssignment->save();
        } catch (QueryException $exception) {
            $this->convertPeriodConstraintViolation($exception);
        }

        return CommercialClientPriceListResource::make($resolvedAssignment)->response();
    }

    private function activePeriodOverlaps(
        CommercialClient $commercialClient,
        int $laboratoryId,
        string $startsAt,
        ?string $endsAt,
        ?int $exceptAssignmentId = null,
    ): bool {
        $normalizedStart = CarbonImmutable::createFromFormat('!Y-m-d', $startsAt);
        $normalizedEnd = $endsAt === null
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', $endsAt);

        return $commercialClient->priceListAssignments()
            ->where('laboratory_id', $laboratoryId)
            ->where('status', CommercialClientPriceList::STATUS_ACTIVE)
            ->when(
                $exceptAssignmentId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($exceptAssignmentId),
            )
            ->where(function (Builder $query) use ($normalizedStart): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $normalizedStart);
            })
            ->when(
                $normalizedEnd !== null,
                fn (Builder $query): Builder => $query->where('starts_at', '<=', $normalizedEnd),
            )
            ->exists();
    }

    private function convertPeriodConstraintViolation(QueryException $exception): never
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $diagnostic = (string) ($exception->errorInfo[2] ?? '');
        $isOverlapViolation = $sqlState === '23P01'
            && str_contains($diagnostic, 'ccpl_no_active_period_overlap');
        $isExactDuplicate = $sqlState === '23505'
            && str_contains($diagnostic, 'ccpl_laboratory_client_list_starts_unique');
        $isSqliteExactDuplicate = $sqlState === '23000'
            && str_contains($diagnostic, 'commercial_client_price_lists.laboratory_id')
            && str_contains($diagnostic, 'commercial_client_price_lists.commercial_client_id')
            && str_contains($diagnostic, 'commercial_client_price_lists.price_list_id')
            && str_contains($diagnostic, 'commercial_client_price_lists.starts_at');

        if ($isOverlapViolation || $isExactDuplicate || $isSqliteExactDuplicate) {
            $this->throwPeriodValidationException();
        }

        throw $exception;
    }

    private function throwPeriodValidationException(): never
    {
        throw ValidationException::withMessages([
            'period' => ['El período se solapa con otra asignación activa.'],
        ]);
    }
}
