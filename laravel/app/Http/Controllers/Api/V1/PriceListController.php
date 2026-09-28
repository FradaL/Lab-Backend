<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PriceList\ActivePriceListRequest;
use App\Http\Requests\Api\V1\PriceList\IndexPriceListRequest;
use App\Http\Requests\Api\V1\PriceList\SetDefaultPriceListRequest;
use App\Http\Requests\Api\V1\PriceList\ShowPriceListRequest;
use App\Http\Requests\Api\V1\PriceList\StorePriceListRequest;
use App\Http\Requests\Api\V1\PriceList\UpdatePriceListRequest;
use App\Http\Requests\Api\V1\PriceList\UpdatePriceListStatusRequest;
use App\Http\Resources\Api\V1\ActivePriceListResource;
use App\Http\Resources\Api\V1\PriceListResource;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PriceListController extends Controller
{
    #[OA\Get(
        path: '/api/v1/price-lists',
        operationId: 'priceListsIndex',
        summary: 'Listar listas de precios',
        description: 'Lista, busca, filtra, ordena y pagina exclusivamente las listas de precios del laboratorio validado por el pipeline SaaS. La búsqueda parcial case-insensitive abarca nombre y descripción. Por defecto ordena por nombre e id ascendente.',
        security: [['sanctumCookie' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en nombre y descripción. Los caracteres % y _ conservan su semántica wildcard de LIKE; el valor siempre se envía mediante bindings.', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'currency', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 3, minLength: 3, pattern: '^[A-Z]{3}$'), example: 'GTQ'),
            new OA\Parameter(name: 'is_default', description: 'Acepta únicamente true o false.', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'name', enum: ['name', 'currency', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de listas de precios.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListCollection')),
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
            new OA\Response(response: 422, description: 'Los parámetros de consulta no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function index(
        IndexPriceListRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $query = PriceList::forLaboratory($currentLaboratory->get());

        if (($search = $request->search()) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $pattern = '%'.$search.'%';

                $query
                    ->whereLike('name', $pattern)
                    ->orWhereLike('description', $pattern);
            });
        }

        if (($status = $request->status()) !== null) {
            $query->where('status', $status);
        }

        if (($currency = $request->currency()) !== null) {
            $query->where('currency', $currency);
        }

        if (($isDefault = $request->isDefault()) !== null) {
            $query->where('is_default', $isDefault);
        }

        $priceLists = $query
            ->orderBy($request->sort(), $request->direction())
            ->orderBy('id', $request->direction())
            ->paginate($request->perPage())
            ->withQueryString();

        return PriceListResource::collection($priceLists);
    }

    #[OA\Post(
        path: '/api/v1/price-lists',
        operationId: 'priceListsStore',
        summary: 'Crear lista de precios',
        description: 'Crea una lista activa y no predeterminada en el laboratorio validado por el pipeline SaaS. El nombre y la descripción se normalizan; ownership, status e is_default son controlados exclusivamente por el servidor y los defaults de persistencia.',
        security: [['sanctumCookie' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePriceListInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Lista de precios creada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListResponse')),
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
            new OA\Response(response: 422, description: 'El payload o el nombre de la lista no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StorePriceListRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();

        try {
            $priceList = $laboratory->priceLists()->create([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'currency' => $request->validated('currency'),
            ]);
        } catch (QueryException $exception) {
            $this->convertUniqueNameConstraintViolation($exception);
        }

        // The INSERT intentionally leaves these columns to database defaults.
        // Mirror those defaults in-memory so the response needs no refresh query.
        $priceList->setAttribute('status', PriceList::STATUS_ACTIVE);
        $priceList->setAttribute('is_default', false);

        return PriceListResource::make($priceList)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/price-lists/active',
        operationId: 'priceListsActive',
        summary: 'Listar listas de precios activas',
        description: 'Devuelve una colección ligera, completa y no paginada de las listas de precios activas del laboratorio actual, incluidas la predeterminada y las no predeterminadas.',
        security: [['sanctumCookie' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Catálogo activo de listas de precios.', content: new OA\JsonContent(ref: '#/components/schemas/ActivePriceListCollection')),
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
        ActivePriceListRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $request->validated();

        $priceLists = PriceList::forLaboratory($currentLaboratory->get())
            ->select(['id', 'name', 'currency', 'is_default'])
            ->where('status', PriceList::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ActivePriceListResource::collection($priceLists);
    }

    #[OA\Get(
        path: '/api/v1/price-lists/{priceList}',
        operationId: 'priceListsShow',
        summary: 'Consultar lista de precios',
        description: 'Devuelve el detalle administrativo de una lista de precios perteneciente al laboratorio validado por el pipeline SaaS. Un identificador inexistente o ajeno al laboratorio actual produce el mismo 404 neutral.',
        security: [['sanctumCookie' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'priceList', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle de la lista de precios.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListResponse')),
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
            new OA\Response(response: 404, description: 'La lista no existe o no pertenece al laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El endpoint no acepta parámetros de consulta.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function show(
        ShowPriceListRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
    ): PriceListResource {
        $request->validated();

        $resolvedPriceList = PriceList::forLaboratory($currentLaboratory->get())
            ->whereKey($priceList)
            ->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        return PriceListResource::make($resolvedPriceList);
    }

    #[OA\Patch(
        path: '/api/v1/price-lists/{priceList}',
        operationId: 'priceListsUpdate',
        summary: 'Actualizar lista de precios',
        description: 'Actualiza parcialmente los datos administrativos editables de una lista perteneciente al laboratorio actual. Debe enviarse al menos uno de name, description o currency.',
        security: [['sanctumCookie' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'priceList', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePriceListInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Lista de precios actualizada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListResponse')),
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
            new OA\Response(response: 404, description: 'La lista no existe o no pertenece al laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El payload o el nombre de la lista no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        UpdatePriceListRequest $updateRequest,
    ): PriceListResource {
        $laboratory = $currentLaboratory->get();
        $resolvedPriceList = PriceList::forLaboratory($laboratory)
            ->whereKey($priceList)
            ->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        $attributes = $updateRequest->validated($request, $laboratory, $resolvedPriceList);

        try {
            $resolvedPriceList->fill($attributes);
            $resolvedPriceList->save();
        } catch (QueryException $exception) {
            $this->convertUniqueNameConstraintViolation($exception);
        }

        return PriceListResource::make($resolvedPriceList);
    }

    #[OA\Patch(
        path: '/api/v1/price-lists/{priceList}/status',
        operationId: 'priceListsUpdateStatus',
        summary: 'Cambiar estado de lista de precios',
        description: 'Cambia únicamente el estado de una lista perteneciente al laboratorio actual. Una lista default activa no puede desactivarse; primero deberá seleccionarse otra default mediante el flujo dedicado.',
        security: [['sanctumCookie' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'priceList', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePriceListStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado correctamente o ya establecido.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListResponse')),
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
            new OA\Response(response: 404, description: 'La lista no se encontró dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El payload no es válido o se intentó desactivar la lista default activa.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        UpdatePriceListStatusRequest $statusRequest,
    ): PriceListResource {
        $resolvedPriceList = PriceList::forLaboratory($currentLaboratory->get())
            ->whereKey($priceList)
            ->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        $status = $statusRequest->validated($request)['status'];

        if (
            $resolvedPriceList->status === PriceList::STATUS_ACTIVE
            && $resolvedPriceList->is_default
            && $status === PriceList::STATUS_INACTIVE
        ) {
            throw ValidationException::withMessages([
                'status' => ['The default price list cannot be deactivated.'],
            ]);
        }

        $resolvedPriceList->status = $status;
        $resolvedPriceList->save();

        return PriceListResource::make($resolvedPriceList);
    }

    #[OA\Patch(
        path: '/api/v1/price-lists/{priceList}/default',
        operationId: 'priceListsSetDefault',
        summary: 'Seleccionar lista de precios predeterminada',
        description: 'Convierte una lista activa del laboratorio actual en la lista predeterminada. La sustitución de una default previa es transaccional y no cambia estados ni metadata.',
        security: [['sanctumCookie' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'priceList', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista predeterminada seleccionada o ya establecida.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListResponse')),
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
            new OA\Response(response: 404, description: 'La lista no se encontró dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El body/query no está vacío o la lista seleccionada está inactiva.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function setDefault(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        SetDefaultPriceListRequest $setDefaultRequest,
    ): PriceListResource {
        $laboratory = $currentLaboratory->get();
        $target = PriceList::forLaboratory($laboratory)
            ->whereKey($priceList)
            ->first();

        if ($target === null) {
            abort(404, 'Resource not found.');
        }

        $setDefaultRequest->validate($request);
        $this->ensurePriceListCanBecomeDefault($target);

        $target = DB::transaction(function () use ($laboratory, $priceList): PriceList {
            Laboratory::query()
                ->whereKey($laboratory->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTarget = PriceList::forLaboratory($laboratory)
                ->whereKey($priceList)
                ->first();

            if ($lockedTarget === null) {
                abort(404, 'Resource not found.');
            }

            $this->ensurePriceListCanBecomeDefault($lockedTarget);

            if ($lockedTarget->is_default) {
                return $lockedTarget;
            }

            $previousDefault = PriceList::forLaboratory($laboratory)
                ->where('is_default', true)
                ->where('id', '<>', $lockedTarget->getKey())
                ->first();

            if ($previousDefault !== null) {
                $previousDefault->is_default = false;
                $previousDefault->save();
            }

            $lockedTarget->is_default = true;
            $lockedTarget->save();

            return $lockedTarget;
        });

        return PriceListResource::make($target);
    }

    private function ensurePriceListCanBecomeDefault(PriceList $priceList): void
    {
        if ($priceList->status === PriceList::STATUS_INACTIVE) {
            throw ValidationException::withMessages([
                'status' => ['The inactive price list cannot be set as default.'],
            ]);
        }
    }

    private function convertUniqueNameConstraintViolation(QueryException $exception): never
    {
        if (! in_array($exception->getCode(), ['23505', '23000'], true)) {
            throw $exception;
        }

        $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if (
            str_contains($detail, 'price_lists_laboratory_name_unique')
            || str_contains($detail, 'price_lists.laboratory_id, price_lists.name')
        ) {
            throw ValidationException::withMessages([
                'name' => ['El nombre ya está en uso en este laboratorio.'],
            ]);
        }

        throw $exception;
    }
}
