<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\MasterDataAuditEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryArea\ActiveLaboratoryAreaRequest;
use App\Http\Requests\Api\V1\LaboratoryArea\IndexLaboratoryAreaRequest;
use App\Http\Requests\Api\V1\LaboratoryArea\StoreLaboratoryAreaRequest;
use App\Http\Requests\Api\V1\LaboratoryArea\UpdateLaboratoryAreaRequest;
use App\Http\Requests\Api\V1\LaboratoryArea\UpdateLaboratoryAreaStatusRequest;
use App\Http\Resources\Api\V1\LaboratoryAreaDetailResource;
use App\Http\Resources\Api\V1\LaboratoryAreaOptionResource;
use App\Http\Resources\Api\V1\LaboratoryAreaResource;
use App\Models\LaboratoryArea;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class LaboratoryAreaController extends Controller
{
    /** @var list<string> */
    private const AUDITABLE_FIELDS = ['code', 'name', 'description'];

    #[OA\Get(
        path: '/api/v1/laboratory-areas',
        operationId: 'laboratoryAreasIndex',
        summary: 'Listar áreas de laboratorio',
        description: 'Lista, busca, filtra, ordena y pagina exclusivamente las áreas del laboratorio validado por el pipeline SaaS. La búsqueda parcial case-insensitive abarca código y nombre. El criterio solicitado siempre usa id ascendente como desempate estable.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Areas'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en código y nombre.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 100), example: 'hema'),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'name', enum: ['code', 'name', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de áreas de laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryAreaListResponse')),
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
        IndexLaboratoryAreaRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $query = LaboratoryArea::forLaboratory($currentLaboratory->get());

        if (($search = $request->search()) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $pattern = '%'.mb_strtolower($search).'%';

                $query
                    ->whereLike('code', $pattern)
                    ->orWhereLike('name', $pattern);
            });
        }

        if (($status = $request->status()) !== null) {
            $query->where('status', $status);
        }

        $areas = $query
            ->orderBy($request->sort(), $request->direction())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return LaboratoryAreaResource::collection($areas);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-areas',
        operationId: 'laboratoryAreasStore',
        summary: 'Crear área de laboratorio',
        description: 'Crea un área activa en el laboratorio validado por el pipeline SaaS. El ownership y el estado inicial son controlados exclusivamente por el servidor.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Areas'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateLaboratoryAreaInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Área de laboratorio creada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryAreaResponse')),
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
            new OA\Response(response: 422, description: 'Los datos del área no son válidos o entran en conflicto con su catálogo.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StoreLaboratoryAreaRequest $request,
        CurrentLaboratory $currentLaboratory,
        AuditWriter $auditWriter,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();

        try {
            $area = DB::transaction(function () use ($request, $laboratory, $auditWriter): LaboratoryArea {
                $area = $laboratory->laboratoryAreas()->create($request->validated());
                $newValues = $area->only(self::AUDITABLE_FIELDS);
                $newValues['status'] = LaboratoryArea::STATUS_ACTIVE;

                $auditWriter->record($laboratory, $request->user(), new AuditEvent(
                    MasterDataAuditEvents::LABORATORY_AREA_CREATED,
                    MasterDataAuditEvents::SUBJECT_LABORATORY_AREA,
                    $area->getKey(),
                    newValues: $newValues,
                ));

                return $area;
            })->refresh();
        } catch (QueryException $exception) {
            $this->convertUniqueConstraintViolation($exception);
        }

        return LaboratoryAreaResource::make($area)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-areas/active',
        operationId: 'laboratoryAreasActive',
        summary: 'Listar opciones activas de áreas de laboratorio',
        description: 'Devuelve una colección ligera, no paginada y ordenada de todas las áreas activas del laboratorio actual.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Areas'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Opciones activas de áreas de laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryAreaOptionCollection')),
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
        ActiveLaboratoryAreaRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $request->validated();

        $areas = LaboratoryArea::forLaboratory($currentLaboratory->get())
            ->where('status', LaboratoryArea::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return LaboratoryAreaOptionResource::collection($areas);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-areas/{area}',
        operationId: 'laboratoryAreasShow',
        summary: 'Consultar área de laboratorio',
        description: 'Devuelve el detalle del área únicamente cuando pertenece al laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Areas'],
        parameters: [
            new OA\Parameter(
                name: 'area',
                description: 'Identificador del área de laboratorio.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del área de laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryAreaDetailResponse')),
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
            new OA\Response(response: 404, description: 'Laboratory area not found.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function show(
        CurrentLaboratory $currentLaboratory,
        int $area,
    ): LaboratoryAreaDetailResource {
        return LaboratoryAreaDetailResource::make(
            $this->findLaboratoryArea($currentLaboratory, $area),
        );
    }

    #[OA\Patch(
        path: '/api/v1/laboratory-areas/{area}',
        operationId: 'laboratoryAreasUpdate',
        summary: 'Actualizar área de laboratorio',
        description: 'Actualiza parcialmente código, nombre o descripción del área dentro del laboratorio actual. Ownership y estado no son editables.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Areas'],
        parameters: [
            new OA\Parameter(
                name: 'area',
                description: 'Identificador del área de laboratorio.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLaboratoryAreaInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Área de laboratorio actualizada correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryAreaDetailResponse')),
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
            new OA\Response(response: 404, description: 'Laboratory area not found.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Los campos enviados no son válidos o entran en conflicto con el catálogo.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        UpdateLaboratoryAreaRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $area,
        AuditWriter $auditWriter,
    ): LaboratoryAreaDetailResource {
        $area = $this->findLaboratoryArea($currentLaboratory, $area);
        $laboratory = $currentLaboratory->get();

        try {
            DB::transaction(function () use ($request, $laboratory, $area, $auditWriter): void {
                $area->fill($request->validated());
                [$oldValues, $newValues] = $this->auditDelta($area);

                if ($oldValues === []) {
                    return;
                }

                $area->save();
                $auditWriter->record($laboratory, $request->user(), new AuditEvent(
                    MasterDataAuditEvents::LABORATORY_AREA_UPDATED,
                    MasterDataAuditEvents::SUBJECT_LABORATORY_AREA,
                    $area->getKey(),
                    $oldValues,
                    $newValues,
                ));
            });
        } catch (QueryException $exception) {
            $this->convertUniqueConstraintViolation($exception);
        }

        return LaboratoryAreaDetailResource::make($area->refresh());
    }

    #[OA\Patch(
        path: '/api/v1/laboratory-areas/{area}/status',
        operationId: 'laboratoryAreasUpdateStatus',
        summary: 'Cambiar estado del área de laboratorio',
        description: 'Cambia únicamente el estado del área dentro del laboratorio actual. Los únicos estados válidos son active e inactive.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Areas'],
        parameters: [
            new OA\Parameter(
                name: 'area',
                description: 'Identificador del área de laboratorio.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLaboratoryAreaStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado del área actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryAreaDetailResponse')),
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
            new OA\Response(response: 404, description: 'Laboratory area not found.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado no es válido o el payload contiene otros campos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        UpdateLaboratoryAreaStatusRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $area,
        AuditWriter $auditWriter,
    ): LaboratoryAreaDetailResource {
        $area = $this->findLaboratoryArea($currentLaboratory, $area);
        $laboratory = $currentLaboratory->get();

        DB::transaction(function () use ($request, $laboratory, $area, $auditWriter): void {
            $oldStatus = $area->status;
            $area->status = $request->validated('status');

            if (! $area->isDirty('status')) {
                return;
            }

            $area->save();
            $auditWriter->record($laboratory, $request->user(), new AuditEvent(
                MasterDataAuditEvents::LABORATORY_AREA_STATUS_CHANGED,
                MasterDataAuditEvents::SUBJECT_LABORATORY_AREA,
                $area->getKey(),
                ['status' => $oldStatus],
                ['status' => $area->status],
            ));
        });

        return LaboratoryAreaDetailResource::make($area->refresh());
    }

    private function findLaboratoryArea(
        CurrentLaboratory $currentLaboratory,
        int $area,
    ): LaboratoryArea {
        $resolvedArea = LaboratoryArea::forLaboratory($currentLaboratory->get())
            ->find($area);

        if ($resolvedArea === null) {
            abort(404, 'Resource not found.');
        }

        return $resolvedArea;
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function auditDelta(LaboratoryArea $area): array
    {
        $oldValues = [];
        $newValues = [];

        foreach (self::AUDITABLE_FIELDS as $field) {
            if ($area->isDirty($field)) {
                $oldValues[$field] = $area->getOriginal($field);
                $newValues[$field] = $area->getAttribute($field);
            }
        }

        return [$oldValues, $newValues];
    }

    private function convertUniqueConstraintViolation(QueryException $exception): never
    {
        if (! in_array($exception->getCode(), ['23505', '23000'], true)) {
            throw $exception;
        }

        $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if (
            str_contains($detail, 'laboratory_areas_laboratory_id_code_unique')
            || str_contains($detail, 'laboratory_areas.laboratory_id, laboratory_areas.code')
        ) {
            throw ValidationException::withMessages([
                'code' => ['El código ya está en uso en este laboratorio.'],
            ]);
        }

        if (
            str_contains($detail, 'laboratory_areas_laboratory_id_name_unique')
            || str_contains($detail, 'laboratory_areas.laboratory_id, laboratory_areas.name')
        ) {
            throw ValidationException::withMessages([
                'name' => ['El nombre ya está en uso en este laboratorio.'],
            ]);
        }

        throw $exception;
    }
}
