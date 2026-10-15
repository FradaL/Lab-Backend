<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalExams\ClinicalConfigurationValidator;
use App\ClinicalExams\ClinicalExamService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicalExam\ClinicalExamRequest;
use App\Http\Resources\Api\V1\ClinicalExamVersionResource;
use App\Models\GlobalExamTemplate;
use App\Models\LaboratoryExamVersion;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ClinicalExamController extends Controller
{
    public function __construct(private ClinicalExamService $service, private CurrentLaboratory $current) {}

    #[OA\Post(
        path: '/api/v1/laboratory-exams/drafts', operationId: 'clinicalExamStore',
        summary: 'Create local inactive exam and clinical draft', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamDraftInput')),
        responses: [
            new OA\Response(response: 201, description: 'Successful operation.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamVersionResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function store(ClinicalExamRequest $request): JsonResponse
    {
        return ClinicalExamVersionResource::make($this->service->create($this->current->get(), $request->user(), $request->validated()))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/global-exam-templates', operationId: 'clinicalExamTemplates',
        summary: 'List immutable global template artifacts', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function templates(ClinicalExamRequest $request): JsonResponse
    {
        return response()->json(GlobalExamTemplate::orderBy('id')->paginate(50)->through(fn ($template) => [
            'id' => $template->id, 'key' => $template->key, 'name' => $template->name, 'configuration' => $template->configuration,
        ]));
    }

    #[OA\Post(
        path: '/api/v1/laboratory-exams/import-template', operationId: 'clinicalExamImport',
        summary: 'Import an independent local draft', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamImportInput')),
        responses: [
            new OA\Response(response: 201, description: 'Successful operation.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamVersionResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function import(ClinicalExamRequest $request): JsonResponse
    {
        $template = GlobalExamTemplate::findOrFail($request->validated('template_id'));

        return ClinicalExamVersionResource::make($this->service->create($this->current->get(), $request->user(), $request->validated(), $template))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/versions', operationId: 'clinicalExamIndex',
        summary: 'List local clinical versions', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClinicalExamVersion'))])),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function index(ClinicalExamRequest $request, int $laboratoryExam): AnonymousResourceCollection
    {
        $this->service->exam($this->current->get(), $laboratoryExam);

        return ClinicalExamVersionResource::collection(LaboratoryExamVersion::forLaboratory($this->current->get())->where('laboratory_exam_id', $laboratoryExam)->orderByDesc('number')->paginate(50));
    }

    #[OA\Get(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/versions/{version}', operationId: 'clinicalExamShow',
        summary: 'Read a clinical version', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamVersionResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function show(ClinicalExamRequest $request, int $laboratoryExam, int $version): ClinicalExamVersionResource
    {
        return ClinicalExamVersionResource::make($this->service->version($this->current->get(), $laboratoryExam, $version));
    }

    #[OA\Post(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/versions', operationId: 'clinicalExamFork',
        summary: 'Prepare a draft from a published source', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamForkInput')),
        responses: [
            new OA\Response(response: 201, description: 'Successful operation.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamVersionResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function fork(ClinicalExamRequest $request, int $laboratoryExam): JsonResponse
    {
        return ClinicalExamVersionResource::make($this->service->fork($this->current->get(), $request->user(), $laboratoryExam, $request->validated('source_version_id')))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/versions/{version}', operationId: 'clinicalExamUpdate',
        summary: 'Replace draft configuration with revision check', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamWriteInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamVersionResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function update(ClinicalExamRequest $request, int $laboratoryExam, int $version): ClinicalExamVersionResource
    {
        return ClinicalExamVersionResource::make($this->service->update($this->current->get(), $request->user(), $laboratoryExam, $version, $request->integer('revision'), $request->validated('configuration')));
    }

    #[OA\Post(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/versions/{version}/validate-publication', operationId: 'clinicalExamValidatePublication',
        summary: 'Check publication readiness without reserving publication', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'publishable', type: 'boolean'), new OA\Property(property: 'errors', type: ['object', 'array'], items: new OA\Items(type: 'string'))])])),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function validatePublication(ClinicalExamRequest $request, ClinicalConfigurationValidator $validator, int $laboratoryExam, int $version): JsonResponse
    {
        $draft = $this->service->version($this->current->get(), $laboratoryExam, $version);
        $errors = $validator->errors($draft->configuration, $this->current->get(), true);
        $exam = $this->service->exam($this->current->get(), $laboratoryExam);
        if (($draft->configuration['code'] ?? null) !== $exam->code) {
            $errors['code'][] = 'The clinical code must match the current catalog identity.';
        }
        if ($draft->base_version_id !== $this->service->state($this->current->get(), $laboratoryExam)?->current_version_id) {
            $errors['base_version_id'][] = 'The publication base is stale.';
        }
        if ($draft->status !== 'draft') {
            $errors['status'][] = 'Published versions are not editable.';
        }

        return response()->json(['data' => ['publishable' => $draft->status === 'draft' && $errors === [], 'errors' => $errors]]);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/versions/{version}/publish', operationId: 'clinicalExamPublish',
        summary: 'Publish an immutable clinical version atomically', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamPublishInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalExamVersionResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function publish(ClinicalExamRequest $request, int $laboratoryExam, int $version): ClinicalExamVersionResource
    {
        return ClinicalExamVersionResource::make($this->service->publish($this->current->get(), $request->user(), $laboratoryExam, $version, $request->integer('revision')));
    }

    #[OA\Get(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/versions/current', operationId: 'clinicalExamCurrent',
        summary: 'Read current published version or null', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', oneOf: [new OA\Schema(ref: '#/components/schemas/ClinicalExamVersion'), new OA\Schema(type: 'null')])])),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function current(ClinicalExamRequest $request, int $laboratoryExam): ClinicalExamVersionResource|JsonResponse
    {
        $version = $this->service->current($this->current->get(), $laboratoryExam);

        return $version === null ? response()->json(['data' => null]) : ClinicalExamVersionResource::make($version);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-exams/{laboratoryExam}/order-configuration', operationId: 'clinicalExamOrderConfiguration',
        summary: 'Read strict contract for future order-line integration', security: [['sanctumCookie' => []]], tags: ['Clinical Exams'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'laboratoryExam', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClinicalExamOrderConfiguration')])),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not found in this laboratory.'),
            new OA\Response(response: 409, description: 'Clinical state, code, import or revision conflict.'),
            new OA\Response(response: 422, description: 'Invalid request or publication configuration.'),
        ],
    )]
    public function orderConfiguration(ClinicalExamRequest $request, int $laboratoryExam): JsonResponse
    {
        return response()->json(['data' => $this->service->orderConfiguration($this->current->get(), $laboratoryExam)]);
    }
}
