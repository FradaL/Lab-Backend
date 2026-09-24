<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SampleType\ActiveSampleTypeRequest;
use App\Http\Requests\Api\V1\SampleType\IndexSampleTypeRequest;
use App\Http\Requests\Api\V1\SampleType\ShowSampleTypeRequest;
use App\Http\Requests\Api\V1\SampleType\StoreSampleTypeRequest;
use App\Http\Requests\Api\V1\SampleType\UpdateSampleTypeRequest;
use App\Http\Requests\Api\V1\SampleType\UpdateSampleTypeStatusRequest;
use App\Http\Resources\Api\V1\SampleTypeDetailResource;
use App\Http\Resources\Api\V1\SampleTypeOptionResource;
use App\Http\Resources\Api\V1\SampleTypeResource;
use App\Models\SampleType;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class SampleTypeController extends Controller
{
    #[OA\Get(
        path: '/api/v1/sample-types',
        operationId: 'sampleTypesIndex',
        summary: 'Listar tipos de muestra',
        description: 'Lista, busca, filtra, ordena y pagina exclusivamente los tipos de muestra del laboratorio validado por el pipeline SaaS. La búsqueda parcial case-insensitive abarca únicamente el nombre. El criterio solicitado siempre usa id ascendente como desempate estable.',
        security: [['sanctumCookie' => []]],
        tags: ['Sample Types'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en el nombre.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 100), example: 'sang'),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'name', enum: ['name', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de tipos de muestra.', content: new OA\JsonContent(ref: '#/components/schemas/SampleTypeListResponse')),
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
        IndexSampleTypeRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $query = SampleType::forLaboratory($currentLaboratory->get());

        if (($search = $request->search()) !== null) {
            $query->whereLike('name', '%'.mb_strtolower($search).'%');
        }

        if (($status = $request->status()) !== null) {
            $query->where('status', $status);
        }

        $sampleTypes = $query
            ->orderBy($request->sort(), $request->direction())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return SampleTypeResource::collection($sampleTypes);
    }

    #[OA\Post(
        path: '/api/v1/sample-types',
        operationId: 'sampleTypesStore',
        summary: 'Crear tipo de muestra',
        description: 'Crea un tipo de muestra activo en el laboratorio validado por el pipeline SaaS. El ownership y el estado inicial son controlados exclusivamente por el servidor.',
        security: [['sanctumCookie' => []]],
        tags: ['Sample Types'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateSampleTypeInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tipo de muestra creado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/SampleTypeResponse')),
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
            new OA\Response(response: 422, description: 'El nombre no es válido o ya existe en el catálogo del laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StoreSampleTypeRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): JsonResponse {
        try {
            $sampleType = $currentLaboratory->get()
                ->sampleTypes()
                ->create([
                    'name' => $request->validated('name'),
                ])
                ->refresh();
        } catch (QueryException $exception) {
            $this->convertUniqueConstraintViolation($exception);
        }

        return SampleTypeResource::make($sampleType)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/sample-types/active',
        operationId: 'sampleTypesActive',
        summary: 'Listar opciones activas de tipos de muestra',
        description: 'Devuelve una colección ligera, no paginada y ordenada de todos los tipos de muestra activos del laboratorio actual.',
        security: [['sanctumCookie' => []]],
        tags: ['Sample Types'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Opciones activas de tipos de muestra.', content: new OA\JsonContent(ref: '#/components/schemas/SampleTypeOptionCollection')),
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
        ActiveSampleTypeRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $request->validated();

        $sampleTypes = SampleType::forLaboratory($currentLaboratory->get())
            ->where('status', SampleType::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return SampleTypeOptionResource::collection($sampleTypes);
    }

    #[OA\Get(
        path: '/api/v1/sample-types/{sampleType}',
        operationId: 'sampleTypesShow',
        summary: 'Consultar tipo de muestra',
        description: 'Devuelve el detalle del tipo de muestra únicamente cuando pertenece al laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Sample Types'],
        parameters: [
            new OA\Parameter(
                name: 'sampleType',
                description: 'Identificador del tipo de muestra.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del tipo de muestra.', content: new OA\JsonContent(ref: '#/components/schemas/SampleTypeDetailResponse')),
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
            new OA\Response(response: 404, description: 'Resource not found.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El endpoint no acepta parámetros de consulta.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function show(
        ShowSampleTypeRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $sampleType,
    ): SampleTypeDetailResource {
        $request->validated();

        return SampleTypeDetailResource::make(
            $this->findSampleType($currentLaboratory, $sampleType),
        );
    }

    #[OA\Patch(
        path: '/api/v1/sample-types/{sampleType}',
        operationId: 'sampleTypesUpdate',
        summary: 'Actualizar tipo de muestra',
        description: 'Actualiza el nombre de un tipo de muestra dentro del laboratorio actual. Actualmente name es el único campo editable y debe enviarse.',
        security: [['sanctumCookie' => []]],
        tags: ['Sample Types'],
        parameters: [
            new OA\Parameter(
                name: 'sampleType',
                description: 'Identificador del tipo de muestra.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Debe contener name, el único campo editable.',
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateSampleTypeInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tipo de muestra actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/SampleTypeDetailResponse')),
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
            new OA\Response(response: 404, description: 'Resource not found.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El nombre no es válido, el payload contiene otros campos o existe un duplicado.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        UpdateSampleTypeRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $sampleType,
    ): SampleTypeDetailResource {
        $sampleType = $this->findSampleType($currentLaboratory, $sampleType);
        $sampleType->name = $request->validated('name');

        if ($sampleType->isDirty()) {
            try {
                $sampleType->save();
            } catch (QueryException $exception) {
                $this->convertUniqueConstraintViolation($exception);
            }
        }

        return SampleTypeDetailResource::make($sampleType);
    }

    #[OA\Patch(
        path: '/api/v1/sample-types/{sampleType}/status',
        operationId: 'sampleTypesUpdateStatus',
        summary: 'Cambiar estado del tipo de muestra',
        description: 'Cambia únicamente el estado del tipo de muestra dentro del laboratorio actual. Los valores válidos exactos son active e inactive.',
        security: [['sanctumCookie' => []]],
        tags: ['Sample Types'],
        parameters: [
            new OA\Parameter(
                name: 'sampleType',
                description: 'Identificador del tipo de muestra.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateSampleTypeStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado del tipo de muestra actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/SampleTypeDetailResponse')),
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
            new OA\Response(response: 404, description: 'Resource not found.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado no es válido o el payload contiene otros campos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        UpdateSampleTypeStatusRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $sampleType,
    ): SampleTypeDetailResource {
        $sampleType = $this->findSampleType($currentLaboratory, $sampleType);
        $sampleType->status = $request->validated('status');

        if ($sampleType->isDirty('status')) {
            $sampleType->save();
        }

        return SampleTypeDetailResource::make($sampleType);
    }

    private function findSampleType(
        CurrentLaboratory $currentLaboratory,
        int $sampleType,
    ): SampleType {
        $sampleType = SampleType::forLaboratory($currentLaboratory->get())
            ->whereKey($sampleType)
            ->first();

        if ($sampleType === null) {
            abort(404, 'Resource not found.');
        }

        return $sampleType;
    }

    private function convertUniqueConstraintViolation(QueryException $exception): never
    {
        if (! in_array($exception->getCode(), ['23505', '23000'], true)) {
            throw $exception;
        }

        $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if (
            str_contains($detail, 'sample_types_laboratory_id_name_unique')
            || str_contains($detail, 'sample_types.laboratory_id, sample_types.name')
        ) {
            throw ValidationException::withMessages([
                'name' => ['El nombre ya está en uso en este laboratorio.'],
            ]);
        }

        throw $exception;
    }
}
