<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PriceListExam\AvailablePriceListExamRequest;
use App\Http\Requests\Api\V1\PriceListExam\BulkUpsertPriceListExamRequest;
use App\Http\Requests\Api\V1\PriceListExam\IndexPriceListExamRequest;
use App\Http\Requests\Api\V1\PriceListExam\UpdatePriceListExamStatusRequest;
use App\Http\Requests\Api\V1\PriceListExam\UpsertPriceListExamRequest;
use App\Http\Resources\Api\V1\AvailablePriceListExamCollection;
use App\Http\Resources\Api\V1\PriceListExamCollection;
use App\Http\Resources\Api\V1\PriceListExamResource;
use App\Models\LaboratoryExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PriceListExamController extends Controller
{
    #[OA\Get(
        path: '/api/v1/price-lists/{priceList}/exams',
        operationId: 'priceListExamsIndex',
        summary: 'Listar precios de exámenes de una lista',
        description: 'Lista únicamente los exámenes configurados en la lista de precios del laboratorio activo. Permite búsqueda parcial case-insensitive por código o nombre del examen, filtros, orden estable y paginación.',
        security: [['sanctumCookie' => []]],
        tags: ['Exam Prices'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Identificador de la lista de precios dentro del laboratorio actual.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en código y nombre del examen.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150), example: 'hema'),
            new OA\Parameter(name: 'status', description: 'Estado de la configuración del precio.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'laboratory_area_id', in: 'query', schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'sample_type_id', in: 'query', schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'exam_name', enum: ['exam_code', 'exam_name', 'price', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de precios configurados.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListExamListResponse')),
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
            new OA\Response(response: 404, description: 'La lista de precios no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Los parámetros de consulta no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function index(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        IndexPriceListExamRequest $indexRequest,
    ): PriceListExamCollection {
        $laboratory = $currentLaboratory->get();
        $resolvedPriceList = PriceList::forLaboratory($laboratory)
            ->whereKey($priceList)
            ->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        $filters = $indexRequest->validated($request);
        $direction = (string) ($filters['direction'] ?? 'asc');
        $sort = (string) ($filters['sort'] ?? 'exam_name');

        $query = PriceListExam::forLaboratory($laboratory)
            ->where('price_list_exams.price_list_id', $resolvedPriceList->id)
            ->join('laboratory_exams', function (JoinClause $join): void {
                $join
                    ->on('laboratory_exams.id', '=', 'price_list_exams.laboratory_exam_id')
                    ->on('laboratory_exams.laboratory_id', '=', 'price_list_exams.laboratory_id');
            })
            ->select('price_list_exams.*')
            ->with([
                'laboratoryExam:id,laboratory_id,laboratory_area_id,sample_type_id,code,name',
                'laboratoryExam.laboratoryArea:id,code,name',
                'laboratoryExam.sampleType:id,name',
            ]);

        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $pattern = '%'.$search.'%';

                $query
                    ->whereLike('laboratory_exams.code', $pattern)
                    ->orWhereLike('laboratory_exams.name', $pattern);
            });
        }

        if (isset($filters['status'])) {
            $query->where('price_list_exams.status', $filters['status']);
        }

        if (isset($filters['laboratory_area_id'])) {
            $query->where('laboratory_exams.laboratory_area_id', $filters['laboratory_area_id']);
        }

        if (isset($filters['sample_type_id'])) {
            $query->where('laboratory_exams.sample_type_id', $filters['sample_type_id']);
        }

        $sortColumn = match ($sort) {
            'exam_code' => 'laboratory_exams.code',
            'price' => 'price_list_exams.price',
            'created_at' => 'price_list_exams.created_at',
            default => 'laboratory_exams.name',
        };

        $prices = $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('price_list_exams.laboratory_exam_id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return new PriceListExamCollection($prices, $resolvedPriceList);
    }

    #[OA\Get(
        path: '/api/v1/price-lists/{priceList}/available-exams',
        operationId: 'priceListAvailableExams',
        summary: 'Buscar exámenes comercialmente disponibles',
        description: 'Catálogo de Recepción para una lista activa. Devuelve únicamente configuraciones de precio activas cuyos exámenes también están activos, incluyendo precios de 0.00.',
        security: [['sanctumCookie' => []]],
        tags: ['Exam Prices'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Identificador de la lista de precios activa dentro del laboratorio actual.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en código y nombre del examen.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150), example: 'hemo'),
            new OA\Parameter(name: 'laboratory_area_id', in: 'query', schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'sample_type_id', in: 'query', schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'name', enum: ['code', 'name', 'price'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Catálogo comercial paginado.', content: new OA\JsonContent(ref: '#/components/schemas/AvailablePriceListExamListResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'), new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError')])),
            new OA\Response(response: 404, description: 'La lista no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Los filtros no son válidos o la lista está inactiva.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function availableExams(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        AvailablePriceListExamRequest $availableRequest,
    ): AvailablePriceListExamCollection {
        $laboratory = $currentLaboratory->get();
        $resolvedPriceList = PriceList::forLaboratory($laboratory)
            ->whereKey($priceList)
            ->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        $filters = $availableRequest->validated($request);

        if ($resolvedPriceList->status !== PriceList::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'price_list' => ['La lista de precios debe estar activa para consultar el catálogo comercial.'],
            ]);
        }

        $direction = (string) ($filters['direction'] ?? 'asc');
        $sort = (string) ($filters['sort'] ?? 'name');

        $query = PriceListExam::forLaboratory($laboratory)
            ->where('price_list_exams.price_list_id', $resolvedPriceList->id)
            ->where('price_list_exams.status', PriceListExam::STATUS_ACTIVE)
            ->join('laboratory_exams', function (JoinClause $join): void {
                $join
                    ->on('laboratory_exams.id', '=', 'price_list_exams.laboratory_exam_id')
                    ->on('laboratory_exams.laboratory_id', '=', 'price_list_exams.laboratory_id');
            })
            ->where('laboratory_exams.status', LaboratoryExam::STATUS_ACTIVE)
            ->select('price_list_exams.*')
            ->with([
                'laboratoryExam:id,laboratory_id,laboratory_area_id,sample_type_id,code,name',
                'laboratoryExam.laboratoryArea:id,code,name',
                'laboratoryExam.sampleType:id,name',
            ]);

        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $pattern = '%'.$search.'%';

                $query
                    ->whereLike('laboratory_exams.code', $pattern)
                    ->orWhereLike('laboratory_exams.name', $pattern);
            });
        }

        if (isset($filters['laboratory_area_id'])) {
            $query->where('laboratory_exams.laboratory_area_id', $filters['laboratory_area_id']);
        }

        if (isset($filters['sample_type_id'])) {
            $query->where('laboratory_exams.sample_type_id', $filters['sample_type_id']);
        }

        $sortColumn = match ($sort) {
            'code' => 'laboratory_exams.code',
            'price' => 'price_list_exams.price',
            default => 'laboratory_exams.name',
        };

        $prices = $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('laboratory_exams.id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return new AvailablePriceListExamCollection($prices, $resolvedPriceList);
    }

    #[OA\Put(
        path: '/api/v1/price-lists/{priceList}/exams/bulk',
        operationId: 'priceListExamsBulkUpsert',
        summary: 'Configurar precios de exámenes en lote',
        description: 'Establece parcialmente los precios indicados. Crea asignaciones faltantes, actualiza sólo precios distintos y deja intactos los exámenes omitidos y todos los estados.',
        security: [['sanctumCookie' => []]],
        tags: ['Exam Prices'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Identificador de la lista de precios dentro del laboratorio actual.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BulkUpsertPriceListExamInput')),
        responses: [
            new OA\Response(response: 200, description: 'Batch aplicado atómicamente.', content: new OA\JsonContent(ref: '#/components/schemas/BulkUpsertPriceListExamResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'), new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError')])),
            new OA\Response(response: 404, description: 'La lista no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El batch o sus reglas de creación no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function bulkUpsert(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        BulkUpsertPriceListExamRequest $bulkRequest,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $resolvedPriceList = PriceList::forLaboratory($laboratory)
            ->whereKey($priceList)
            ->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        $items = $bulkRequest->validated($request)['items'];
        $examIds = array_column($items, 'laboratory_exam_id');
        $resolvedExams = LaboratoryExam::forLaboratory($laboratory)
            ->whereIn('id', $examIds)
            ->get()
            ->keyBy('id');
        $this->ensureBulkExamsResolved($items, $resolvedExams);

        $existing = $this->priceListExamsQuery(
            $laboratory->getKey(),
            $resolvedPriceList->getKey(),
            $examIds,
        )->get()->keyBy('laboratory_exam_id');
        $this->ensureBulkCreationAllowed($items, $resolvedPriceList, $resolvedExams, $existing);

        try {
            $result = $this->performBulkUpsert($laboratory->getKey(), $resolvedPriceList->getKey(), $items);
        } catch (QueryException $exception) {
            if (! $this->isAssignmentUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $result = $this->performBulkUpsert($laboratory->getKey(), $resolvedPriceList->getKey(), $items);
        }

        $result['exams']->load([
            'laboratoryArea:id,code,name',
            'sampleType:id,name',
        ]);
        foreach ($result['items'] as $priceListExam) {
            $priceListExam->setRelation(
                'laboratoryExam',
                $result['exams']->get($priceListExam->laboratory_exam_id),
            );
        }

        return PriceListExamResource::collection($result['items'])
            ->additional(['meta' => $result['meta']])
            ->response();
    }

    #[OA\Put(
        path: '/api/v1/price-lists/{priceList}/exams/{laboratoryExam}',
        operationId: 'priceListExamsUpsert',
        summary: 'Asignar o actualizar el precio de un examen',
        description: 'Establece el precio del examen dentro de la lista del laboratorio activo. Crea una asignación activa cuando no existe y ambos catálogos están activos; si ya existe, actualiza únicamente su precio y conserva su estado aun con catálogos inactivos.',
        security: [['sanctumCookie' => []]],
        tags: ['Exam Prices'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Identificador de la lista de precios dentro del laboratorio actual.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'laboratoryExam', description: 'Identificador del examen dentro del laboratorio actual.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpsertPriceListExamInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Precio existente actualizado.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListExamResponse')),
            new OA\Response(response: 201, description: 'Precio asignado por primera vez.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListExamResponse')),
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
            new OA\Response(response: 404, description: 'La lista o el examen no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El precio o las reglas de creación no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function upsert(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        int $laboratoryExam,
        UpsertPriceListExamRequest $upsertRequest,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $resolvedPriceList = PriceList::forLaboratory($laboratory)
            ->whereKey($priceList)
            ->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        $resolvedExam = LaboratoryExam::forLaboratory($laboratory)
            ->whereKey($laboratoryExam)
            ->first();

        if ($resolvedExam === null) {
            abort(404, 'Resource not found.');
        }

        $price = $upsertRequest->validated($request)['price'];

        try {
            [$priceListExam, $created] = DB::transaction(function () use (
                $laboratory,
                $resolvedPriceList,
                $resolvedExam,
                $price,
            ): array {
                $existing = $this->priceListExamQuery(
                    $laboratory->getKey(),
                    $resolvedPriceList->getKey(),
                    $resolvedExam->getKey(),
                )->lockForUpdate()->first();

                if ($existing !== null) {
                    $existing->price = $price;
                    $existing->save();

                    return [$existing, false];
                }

                $this->ensureParentsAllowCreation($resolvedPriceList, $resolvedExam);

                $created = $laboratory->priceListExams()->create([
                    'price_list_id' => $resolvedPriceList->getKey(),
                    'laboratory_exam_id' => $resolvedExam->getKey(),
                    'price' => $price,
                ]);
                $created->setAttribute('status', PriceListExam::STATUS_ACTIVE);

                return [$created, true];
            });
        } catch (QueryException $exception) {
            if (! $this->isAssignmentUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $priceListExam = DB::transaction(function () use (
                $laboratory,
                $resolvedPriceList,
                $resolvedExam,
                $price,
                $exception,
            ): PriceListExam {
                $winner = $this->priceListExamQuery(
                    $laboratory->getKey(),
                    $resolvedPriceList->getKey(),
                    $resolvedExam->getKey(),
                )->lockForUpdate()->first();

                if ($winner === null) {
                    throw $exception;
                }

                $winner->price = $price;
                $winner->save();

                return $winner;
            });
            $created = false;
        }

        $resolvedExam->load([
            'laboratoryArea:id,code,name',
            'sampleType:id,name',
        ]);
        $priceListExam->setRelation('laboratoryExam', $resolvedExam);

        return PriceListExamResource::make($priceListExam)
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    #[OA\Patch(
        path: '/api/v1/price-lists/{priceList}/exams/{laboratoryExam}/status',
        operationId: 'priceListExamsUpdateStatus',
        summary: 'Cambiar el estado del precio de un examen',
        description: 'Activa o desactiva una asignación existente en el laboratorio activo. No crea asignaciones y no depende del estado de la lista ni del examen.',
        security: [['sanctumCookie' => []]],
        tags: ['Exam Prices'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Identificador de la lista de precios dentro del laboratorio actual.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(name: 'laboratoryExam', description: 'Identificador del examen dentro del laboratorio actual.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdatePriceListExamStatusInput')),
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado o conservado de forma idempotente.', content: new OA\JsonContent(ref: '#/components/schemas/PriceListExamResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'), new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError')])),
            new OA\Response(response: 404, description: 'La lista, el examen o su asignación no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado solicitado no es válido.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        int $priceList,
        int $laboratoryExam,
        UpdatePriceListExamStatusRequest $statusRequest,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $resolvedPriceList = PriceList::forLaboratory($laboratory)->whereKey($priceList)->first();

        if ($resolvedPriceList === null) {
            abort(404, 'Resource not found.');
        }

        $resolvedExam = LaboratoryExam::forLaboratory($laboratory)->whereKey($laboratoryExam)->first();

        if ($resolvedExam === null) {
            abort(404, 'Resource not found.');
        }

        $priceListExam = $this->priceListExamQuery(
            $laboratory->getKey(),
            $resolvedPriceList->getKey(),
            $resolvedExam->getKey(),
        )->first();

        if ($priceListExam === null) {
            abort(404, 'Resource not found.');
        }

        $priceListExam->status = $statusRequest->validated($request)['status'];
        $priceListExam->save();

        $resolvedExam->load(['laboratoryArea:id,code,name', 'sampleType:id,name']);
        $priceListExam->setRelation('laboratoryExam', $resolvedExam);

        return PriceListExamResource::make($priceListExam)->response();
    }

    /**
     * @param  list<array{laboratory_exam_id: int, price: string}>  $items
     * @return array{items: list<PriceListExam>, exams: Collection<int, LaboratoryExam>, meta: array{created: int, updated: int, unchanged: int}}
     */
    private function performBulkUpsert(int|string $laboratoryId, int|string $priceListId, array $items): array
    {
        return DB::transaction(function () use ($laboratoryId, $priceListId, $items): array {
            $priceList = PriceList::query()
                ->where('laboratory_id', $laboratoryId)
                ->whereKey($priceListId)
                ->lockForUpdate()
                ->first();

            if ($priceList === null) {
                abort(404, 'Resource not found.');
            }

            $examIds = array_column($items, 'laboratory_exam_id');
            $exams = LaboratoryExam::query()
                ->where('laboratory_id', $laboratoryId)
                ->whereIn('id', $examIds)
                ->get()
                ->keyBy('id');
            $this->ensureBulkExamsResolved($items, $exams);

            $existing = $this->priceListExamsQuery($laboratoryId, $priceListId, $examIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('laboratory_exam_id');
            $this->ensureBulkCreationAllowed($items, $priceList, $exams, $existing);

            $result = [];
            $meta = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($items as $item) {
                $priceListExam = $existing->get($item['laboratory_exam_id']);

                if ($priceListExam === null) {
                    $priceListExam = PriceListExam::query()->create([
                        'laboratory_id' => $laboratoryId,
                        'price_list_id' => $priceListId,
                        'laboratory_exam_id' => $item['laboratory_exam_id'],
                        'price' => $item['price'],
                    ]);
                    $priceListExam->setAttribute('status', PriceListExam::STATUS_ACTIVE);
                    $meta['created']++;
                } else {
                    $priceListExam->price = $item['price'];
                    if ($priceListExam->isDirty('price')) {
                        $priceListExam->save();
                        $meta['updated']++;
                    } else {
                        $meta['unchanged']++;
                    }
                }

                $result[] = $priceListExam;
            }

            return ['items' => $result, 'exams' => $exams, 'meta' => $meta];
        });
    }

    /** @param list<array{laboratory_exam_id: int, price: string}> $items */
    private function ensureBulkExamsResolved(array $items, Collection $exams): void
    {
        $errors = [];

        foreach ($items as $index => $item) {
            if (! $exams->has($item['laboratory_exam_id'])) {
                $errors["items.{$index}.laboratory_exam_id"] = ['The selected laboratory exam is invalid.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<array{laboratory_exam_id: int, price: string}> $items */
    private function ensureBulkCreationAllowed(
        array $items,
        PriceList $priceList,
        Collection $exams,
        Collection $existing,
    ): void {
        $errors = [];

        foreach ($items as $index => $item) {
            if ($existing->has($item['laboratory_exam_id'])) {
                continue;
            }

            if ($priceList->status !== PriceList::STATUS_ACTIVE) {
                $errors["items.{$index}.laboratory_exam_id"][] = 'La lista de precios debe estar activa para asignar un examen nuevo.';
            }

            if ($exams->get($item['laboratory_exam_id'])->status !== LaboratoryExam::STATUS_ACTIVE) {
                $errors["items.{$index}.laboratory_exam_id"][] = 'El examen debe estar activo para asignarle un precio nuevo.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<int> $examIds */
    private function priceListExamsQuery(int|string $laboratoryId, int|string $priceListId, array $examIds): Builder
    {
        return PriceListExam::query()
            ->where('laboratory_id', $laboratoryId)
            ->where('price_list_id', $priceListId)
            ->whereIn('laboratory_exam_id', $examIds);
    }

    /** @return Builder<PriceListExam> */
    private function priceListExamQuery(int|string $laboratoryId, int|string $priceListId, int|string $examId): Builder
    {
        return PriceListExam::query()
            ->where('laboratory_id', $laboratoryId)
            ->where('price_list_id', $priceListId)
            ->where('laboratory_exam_id', $examId);
    }

    private function ensureParentsAllowCreation(PriceList $priceList, LaboratoryExam $exam): void
    {
        $errors = [];

        if ($priceList->status !== PriceList::STATUS_ACTIVE) {
            $errors['price_list'] = ['La lista de precios debe estar activa para asignar un examen nuevo.'];
        }

        if ($exam->status !== LaboratoryExam::STATUS_ACTIVE) {
            $errors['laboratory_exam'] = ['El examen debe estar activo para asignarle un precio nuevo.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isAssignmentUniqueConstraintViolation(QueryException $exception): bool
    {
        if (! in_array($exception->getCode(), ['23505', '23000'], true)) {
            return false;
        }

        $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return str_contains($detail, 'price_list_exams_laboratory_list_exam_unique')
            || str_contains(
                $detail,
                'price_list_exams.laboratory_id, price_list_exams.price_list_id, price_list_exams.laboratory_exam_id',
            );
    }
}
