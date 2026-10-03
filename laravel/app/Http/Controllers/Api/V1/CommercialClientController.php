<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CommercialClient\ActiveCommercialClientRequest;
use App\Http\Requests\Api\V1\CommercialClient\IndexCommercialClientRequest;
use App\Http\Requests\Api\V1\CommercialClient\ShowCommercialClientRequest;
use App\Http\Requests\Api\V1\CommercialClient\StoreCommercialClientRequest;
use App\Http\Requests\Api\V1\CommercialClient\UpdateCommercialClientRequest;
use App\Http\Requests\Api\V1\CommercialClient\UpdateCommercialClientStatusRequest;
use App\Http\Resources\Api\V1\ActiveCommercialClientResource;
use App\Http\Resources\Api\V1\CommercialClientResource;
use App\Models\CommercialClient;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CommercialClientController extends Controller
{
    #[OA\Get(
        path: '/api/v1/commercial-clients',
        operationId: 'commercialClientsIndex',
        summary: 'Listar entidades comerciales',
        description: 'Lista, busca, filtra, ordena y pagina exclusivamente las entidades comerciales del laboratorio validado por el pipeline SaaS. La búsqueda parcial case-insensitive abarca nombre, identificador fiscal, teléfono y correo electrónico. Por defecto ordena por nombre e id ascendente.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Clients'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en nombre, identificador fiscal, teléfono y correo electrónico.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['insurance', 'company', 'agreement', 'other'])),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'name', enum: ['name', 'type', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de entidades comerciales.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientCollection')),
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
            new OA\Response(response: 422, description: 'Los parámetros de consulta no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function index(
        IndexCommercialClientRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $query = CommercialClient::forLaboratory($currentLaboratory->get());

        if (($search = $request->search()) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $pattern = '%'.$search.'%';

                $query
                    ->whereLike('name', $pattern)
                    ->orWhereLike('tax_id', $pattern)
                    ->orWhereLike('phone', $pattern)
                    ->orWhereLike('email', $pattern);
            });
        }

        if (($status = $request->status()) !== null) {
            $query->where('status', $status);
        }

        if (($type = $request->type()) !== null) {
            $query->where('type', $type);
        }

        $commercialClients = $query
            ->orderBy($request->sort(), $request->direction())
            ->orderBy('id', $request->direction())
            ->paginate($request->perPage())
            ->withQueryString();

        return CommercialClientResource::collection($commercialClients);
    }

    #[OA\Post(
        path: '/api/v1/commercial-clients',
        operationId: 'commercialClientsStore',
        summary: 'Crear entidad comercial',
        description: 'Crea una entidad comercial activa en el laboratorio validado por el pipeline SaaS. El ownership y el estado inicial son controlados exclusivamente por el servidor.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Clients'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCommercialClientInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Entidad comercial creada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientResponse')),
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
            new OA\Response(response: 422, description: 'El payload no es válido o el nombre ya está en uso en el laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StoreCommercialClientRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): JsonResponse {
        try {
            $commercialClient = $currentLaboratory->get()
                ->commercialClients()
                ->create($request->safe()->only([
                    'name',
                    'type',
                    'tax_id',
                    'phone',
                    'email',
                    'address',
                    'notes',
                ]));
        } catch (QueryException $exception) {
            $this->convertUniqueNameConstraintViolation($exception);
        }

        // The INSERT intentionally leaves status to its database default.
        // Mirror that default in-memory so the response needs no global refresh query.
        $commercialClient->setAttribute('status', CommercialClient::STATUS_ACTIVE);

        return CommercialClientResource::make($commercialClient)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/commercial-clients/active',
        operationId: 'commercialClientsActive',
        summary: 'Listar entidades comerciales activas',
        description: 'Devuelve una colección ligera, completa y no paginada de las entidades comerciales activas del laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Clients'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Catálogo activo de entidades comerciales.', content: new OA\JsonContent(ref: '#/components/schemas/ActiveCommercialClientCollection')),
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
            new OA\Response(response: 422, description: 'El endpoint no acepta parámetros de consulta.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function active(
        ActiveCommercialClientRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $request->validated();

        $commercialClients = CommercialClient::forLaboratory($currentLaboratory->get())
            ->select(['id', 'name', 'type'])
            ->where('status', CommercialClient::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ActiveCommercialClientResource::collection($commercialClients);
    }

    #[OA\Get(
        path: '/api/v1/commercial-clients/{commercialClient}',
        operationId: 'commercialClientsShow',
        summary: 'Consultar entidad comercial',
        description: 'Devuelve el detalle administrativo de una entidad comercial únicamente cuando pertenece al laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Clients'],
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
        responses: [
            new OA\Response(response: 200, description: 'Detalle de la entidad comercial.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientResponse')),
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
            new OA\Response(response: 404, description: 'La entidad no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El endpoint no acepta parámetros de consulta.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function show(
        ShowCommercialClientRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $commercialClient,
    ): CommercialClientResource {
        $request->validated();

        $commercialClient = CommercialClient::forLaboratory($currentLaboratory->get())
            ->whereKey($commercialClient)
            ->first();

        if ($commercialClient === null) {
            abort(404, 'Resource not found.');
        }

        return CommercialClientResource::make($commercialClient);
    }

    #[OA\Patch(
        path: '/api/v1/commercial-clients/{commercialClient}',
        operationId: 'commercialClientsUpdate',
        summary: 'Actualizar entidad comercial',
        description: 'Actualiza parcialmente los datos editables de una entidad comercial perteneciente al laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Clients'],
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
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCommercialClientInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Entidad comercial actualizada correctamente o sin cambios.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientResponse')),
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
            new OA\Response(response: 404, description: 'La entidad no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El payload no es válido o el nombre ya está en uso en el laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $commercialClient,
        UpdateCommercialClientRequest $updateRequest,
    ): CommercialClientResource {
        $laboratory = $currentLaboratory->get();
        $resolvedCommercialClient = CommercialClient::forLaboratory($laboratory)
            ->whereKey($commercialClient)
            ->first();

        if ($resolvedCommercialClient === null) {
            abort(404, 'Resource not found.');
        }

        $attributes = $updateRequest->validated($request, $laboratory, $resolvedCommercialClient);

        try {
            $resolvedCommercialClient->fill($attributes);
            $resolvedCommercialClient->save();
        } catch (QueryException $exception) {
            $this->convertUniqueNameConstraintViolation($exception);
        }

        return CommercialClientResource::make($resolvedCommercialClient);
    }

    #[OA\Patch(
        path: '/api/v1/commercial-clients/{commercialClient}/status',
        operationId: 'commercialClientsUpdateStatus',
        summary: 'Cambiar estado de entidad comercial',
        description: 'Cambia exclusivamente el estado de una entidad comercial perteneciente al laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Clients'],
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
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCommercialClientStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado correctamente o ya establecido.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialClientResponse')),
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
            new OA\Response(response: 404, description: 'La entidad no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado no es válido o el payload contiene otros campos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $commercialClient,
        UpdateCommercialClientStatusRequest $statusRequest,
    ): CommercialClientResource {
        $resolvedCommercialClient = CommercialClient::forLaboratory($currentLaboratory->get())
            ->whereKey($commercialClient)
            ->first();

        if ($resolvedCommercialClient === null) {
            abort(404, 'Resource not found.');
        }

        $resolvedCommercialClient->status = $statusRequest->validated($request)['status'];
        $resolvedCommercialClient->save();

        return CommercialClientResource::make($resolvedCommercialClient);
    }

    private function convertUniqueNameConstraintViolation(QueryException $exception): never
    {
        if (! in_array($exception->getCode(), ['23505', '23000'], true)) {
            throw $exception;
        }

        $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if (
            str_contains($detail, 'commercial_clients_laboratory_name_unique')
            || str_contains($detail, 'commercial_clients.laboratory_id, commercial_clients.name')
        ) {
            throw ValidationException::withMessages([
                'name' => ['El nombre ya está en uso en este laboratorio.'],
            ]);
        }

        throw $exception;
    }
}
