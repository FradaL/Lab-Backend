<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\MasterDataAuditEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryExam\ActiveLaboratoryExamRequest;
use App\Http\Requests\Api\V1\LaboratoryExam\IndexLaboratoryExamRequest;
use App\Http\Requests\Api\V1\LaboratoryExam\StoreLaboratoryExamRequest;
use App\Http\Requests\Api\V1\LaboratoryExam\UpdateLaboratoryExamRequest;
use App\Http\Requests\Api\V1\LaboratoryExam\UpdateLaboratoryExamStatusRequest;
use App\Http\Resources\Api\V1\ActiveLaboratoryExamResource;
use App\Http\Resources\Api\V1\LaboratoryExamResource;
use App\Models\LaboratoryExam;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class LaboratoryExamController extends Controller
{
    /** @var list<string> */
    private const AUDITABLE_FIELDS = [
        'laboratory_area_id',
        'sample_type_id',
        'code',
        'name',
        'description',
        'turnaround_time_minutes',
    ];

    #[OA\Get(
        path: '/api/v1/laboratory-exams',
        operationId: 'laboratoryExamsIndex',
        summary: 'Listar exámenes de laboratorio',
        description: 'Lista, busca, filtra, ordena y pagina exclusivamente los exámenes del laboratorio validado por el pipeline SaaS. La búsqueda parcial case-insensitive abarca código y nombre. El criterio solicitado siempre usa id en la misma dirección como desempate estable.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en código y nombre.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150), example: 'hema'),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'laboratory_area_id', in: 'query', schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'sample_type_id', in: 'query', schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'name', enum: ['code', 'name', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de exámenes de laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryExamListResponse')),
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
        IndexLaboratoryExamRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $query = LaboratoryExam::forLaboratory($currentLaboratory->get())
            ->with([
                'laboratoryArea:id,code,name',
                'sampleType:id,name',
            ]);

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

        if (($laboratoryAreaId = $request->laboratoryAreaId()) !== null) {
            $query->where('laboratory_area_id', $laboratoryAreaId);
        }

        if (($sampleTypeId = $request->sampleTypeId()) !== null) {
            $query->where('sample_type_id', $sampleTypeId);
        }

        $exams = $query
            ->orderBy($request->sort(), $request->direction())
            ->orderBy('id', $request->direction())
            ->paginate($request->perPage())
            ->withQueryString();

        return LaboratoryExamResource::collection($exams);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-exams',
        operationId: 'laboratoryExamsStore',
        summary: 'Crear examen de laboratorio',
        description: 'Crea un examen activo en el laboratorio validado por el pipeline SaaS. Area y tipo de muestra deben estar activos y pertenecer al laboratorio actual. Ownership y estado inicial son controlados exclusivamente por el servidor.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateLaboratoryExamInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Examen creado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryExamResponse')),
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
            new OA\Response(response: 422, description: 'El payload, las relaciones o el código no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StoreLaboratoryExamRequest $request,
        CurrentLaboratory $currentLaboratory,
        AuditWriter $auditWriter,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();

        try {
            $createdExam = DB::transaction(function () use ($request, $laboratory, $auditWriter): LaboratoryExam {
                $createdExam = $laboratory->laboratoryExams()->create([
                    'laboratory_area_id' => $request->validated('laboratory_area_id'),
                    'sample_type_id' => $request->validated('sample_type_id'),
                    'code' => $request->validated('code'),
                    'name' => $request->validated('name'),
                    'description' => $request->validated('description'),
                    'turnaround_time_minutes' => $request->validated('turnaround_time_minutes'),
                ]);

                $newValues = $createdExam->only(self::AUDITABLE_FIELDS);
                $newValues['status'] = LaboratoryExam::STATUS_ACTIVE;
                $auditWriter->record($laboratory, $request->user(), new AuditEvent(
                    MasterDataAuditEvents::LABORATORY_EXAM_CREATED,
                    MasterDataAuditEvents::SUBJECT_LABORATORY_EXAM,
                    $createdExam->getKey(),
                    newValues: $newValues,
                ));

                return $createdExam;
            });
        } catch (QueryException $exception) {
            $this->convertUniqueCodeConstraintViolation($exception);
        }

        $exam = $laboratory->laboratoryExams()
            ->with([
                'laboratoryArea:id,code,name',
                'sampleType:id,name',
            ])
            ->findOrFail($createdExam->getKey());

        return LaboratoryExamResource::make($exam)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-exams/active',
        operationId: 'laboratoryExamsActive',
        summary: 'Listar opciones activas de exámenes de laboratorio',
        description: 'Devuelve una colección ligera, no paginada y ordenada de los exámenes activos del laboratorio actual. El estado del área y tipo de muestra relacionados no afecta su inclusión.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Opciones activas de exámenes de laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/ActiveLaboratoryExamCollection')),
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
        ActiveLaboratoryExamRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $request->validated();

        $exams = LaboratoryExam::forLaboratory($currentLaboratory->get())
            ->select(['id', 'laboratory_area_id', 'sample_type_id', 'code', 'name'])
            ->where('status', LaboratoryExam::STATUS_ACTIVE)
            ->with([
                'laboratoryArea:id,code,name',
                'sampleType:id,name',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ActiveLaboratoryExamResource::collection($exams);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-exams/{laboratoryExam}',
        operationId: 'laboratoryExamsShow',
        summary: 'Consultar examen de laboratorio',
        description: 'Devuelve el detalle del examen únicamente cuando pertenece al laboratorio validado por el pipeline SaaS. Los exámenes y catálogos relacionados inactivos continúan visibles.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Exams'],
        parameters: [
            new OA\Parameter(
                name: 'laboratoryExam',
                description: 'Identificador del examen dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del examen de laboratorio.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryExamResponse')),
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
            new OA\Response(
                response: 404,
                description: 'El examen no existe dentro del laboratorio actual.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'),
            ),
        ],
    )]
    public function show(
        CurrentLaboratory $currentLaboratory,
        int $laboratoryExam,
    ): LaboratoryExamResource {
        $exam = LaboratoryExam::forLaboratory($currentLaboratory->get())
            ->with([
                'laboratoryArea:id,code,name',
                'sampleType:id,name',
            ])
            ->whereKey($laboratoryExam)
            ->first();

        if ($exam === null) {
            abort(404, 'Resource not found.');
        }

        return LaboratoryExamResource::make($exam);
    }

    private function convertUniqueCodeConstraintViolation(QueryException $exception): never
    {
        if (! in_array($exception->getCode(), ['23505', '23000'], true)) {
            throw $exception;
        }

        $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if (
            str_contains($detail, 'laboratory_exams_laboratory_code_unique')
            || str_contains($detail, 'laboratory_exams.laboratory_id, laboratory_exams.code')
        ) {
            throw ValidationException::withMessages([
                'code' => ['El código ya está en uso en este laboratorio.'],
            ]);
        }

        throw $exception;
    }

    #[OA\Patch(
        path: '/api/v1/laboratory-exams/{laboratoryExam}',
        operationId: 'laboratoryExamsUpdate',
        summary: 'Actualizar examen de laboratorio',
        description: 'Actualiza parcialmente los campos editables del examen dentro del laboratorio actual. Debe enviarse al menos un campo permitido; cualquier Area o tipo de muestra seleccionado explícitamente debe estar activo.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Exams'],
        parameters: [
            new OA\Parameter(
                name: 'laboratoryExam',
                description: 'Identificador del examen dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'PATCH parcial estricto con al menos uno de los seis campos editables.',
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLaboratoryExamInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Examen actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryExamResponse')),
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
            new OA\Response(response: 404, description: 'El examen no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El payload o las relaciones seleccionadas no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $laboratoryExam,
        UpdateLaboratoryExamRequest $updateRequest,
        AuditWriter $auditWriter,
    ): LaboratoryExamResource {
        $laboratory = $currentLaboratory->get();
        $exam = LaboratoryExam::forLaboratory($laboratory)
            ->whereKey($laboratoryExam)
            ->first();

        if ($exam === null) {
            abort(404, 'Resource not found.');
        }

        $attributes = $updateRequest->validated($request, $laboratory, $exam);

        try {
            DB::transaction(function () use ($request, $laboratory, $exam, $attributes, $auditWriter): void {
                $exam->fill($attributes);
                [$oldValues, $newValues] = $this->auditDelta($exam);

                if ($oldValues === []) {
                    return;
                }

                $exam->save();
                $auditWriter->record($laboratory, $request->user(), new AuditEvent(
                    MasterDataAuditEvents::LABORATORY_EXAM_UPDATED,
                    MasterDataAuditEvents::SUBJECT_LABORATORY_EXAM,
                    $exam->getKey(),
                    $oldValues,
                    $newValues,
                ));
            });
        } catch (QueryException $exception) {
            $this->convertUniqueCodeConstraintViolation($exception);
        }

        $exam->load([
            'laboratoryArea:id,code,name',
            'sampleType:id,name',
        ]);

        return LaboratoryExamResource::make($exam);
    }

    #[OA\Patch(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/status',
        operationId: 'laboratoryExamsUpdateStatus',
        summary: 'Cambiar estado de examen de laboratorio',
        description: 'Cambia únicamente el estado del examen dentro del laboratorio actual. El estado es independiente del estado de su área y tipo de muestra.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory Exams'],
        parameters: [
            new OA\Parameter(
                name: 'laboratoryExam',
                description: 'Identificador del examen dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLaboratoryExamStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado del examen actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryExamResponse')),
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
            new OA\Response(response: 404, description: 'El examen no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado no es válido o el payload contiene otros campos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $laboratoryExam,
        UpdateLaboratoryExamStatusRequest $statusRequest,
        AuditWriter $auditWriter,
    ): LaboratoryExamResource {
        $laboratory = $currentLaboratory->get();
        $exam = LaboratoryExam::forLaboratory($laboratory)
            ->whereKey($laboratoryExam)
            ->first();

        if ($exam === null) {
            abort(404, 'Resource not found.');
        }

        DB::transaction(function () use ($request, $statusRequest, $laboratory, $exam, $auditWriter): void {
            $oldStatus = $exam->status;
            $exam->status = $statusRequest->validated($request)['status'];

            if (! $exam->isDirty('status')) {
                return;
            }

            $exam->save();
            $auditWriter->record($laboratory, $request->user(), new AuditEvent(
                MasterDataAuditEvents::LABORATORY_EXAM_STATUS_CHANGED,
                MasterDataAuditEvents::SUBJECT_LABORATORY_EXAM,
                $exam->getKey(),
                ['status' => $oldStatus],
                ['status' => $exam->status],
            ));
        });
        $exam->load([
            'laboratoryArea:id,code,name',
            'sampleType:id,name',
        ]);

        return LaboratoryExamResource::make($exam);
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function auditDelta(LaboratoryExam $exam): array
    {
        $oldValues = [];
        $newValues = [];

        foreach (self::AUDITABLE_FIELDS as $field) {
            if ($exam->isDirty($field)) {
                $oldValues[$field] = $exam->getOriginal($field);
                $newValues[$field] = $exam->getAttribute($field);
            }
        }

        return [$oldValues, $newValues];
    }
}
