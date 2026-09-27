<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryExam\IndexLaboratoryExamRequest;
use App\Http\Resources\Api\V1\LaboratoryExamResource;
use App\Models\LaboratoryExam;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class LaboratoryExamController extends Controller
{
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
}
