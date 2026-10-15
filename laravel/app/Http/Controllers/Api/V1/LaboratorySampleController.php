<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalExams\SampleCompatibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratorySample\LaboratorySampleRequest;
use App\Http\Resources\Api\V1\LaboratorySampleResource;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySample;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class LaboratorySampleController extends Controller
{
    public function __construct(private LaboratorySampleService $service, private SampleCompatibility $compatibility, private CurrentLaboratory $current) {}

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/samples/collect', operationId: 'physicalSampleCollect', summary: 'collect; requires samples.collect',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SampleCollectionInput')),
        responses: [
            new OA\Response(response: 201, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function collect(LaboratorySampleRequest $request, int $order): JsonResponse
    {
        return response()->json($this->service->register($this->current->get(), $request->user(), $order, 'collect', $request->validated()), 201);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/samples/receive', operationId: 'physicalSampleReceivenew', summary: 'receiveNew; requires samples.receive',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SampleExternalReceptionInput')),
        responses: [
            new OA\Response(response: 201, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function receiveNew(LaboratorySampleRequest $request, int $order): JsonResponse
    {
        return response()->json($this->service->register($this->current->get(), $request->user(), $order, 'receiveNew', $request->validated()), 201);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/samples/{sample}/receive', operationId: 'physicalSampleReceive', summary: 'receive; requires samples.receive',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'sample', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SampleReceptionInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function receive(LaboratorySampleRequest $request, int $order, int $sample): LaboratorySampleResource
    {
        return LaboratorySampleResource::make($this->service->receive($this->current->get(), $request->user(), $order, $sample, $request->validated())->load(['events', 'associations']));
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/samples/{sample}/accept', operationId: 'physicalSampleAccept', summary: 'accept; requires samples.evaluate',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'sample', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SampleEmptyInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function accept(LaboratorySampleRequest $request, int $order, int $sample): LaboratorySampleResource
    {
        return LaboratorySampleResource::make($this->service->evaluate($this->current->get(), $request->user(), $order, $sample, 'accepted', $request->validated())->load(['events', 'associations']));
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/samples/{sample}/reject', operationId: 'physicalSampleReject', summary: 'reject; requires samples.evaluate',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'sample', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SampleReasonInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function reject(LaboratorySampleRequest $request, int $order, int $sample): LaboratorySampleResource
    {
        return LaboratorySampleResource::make($this->service->evaluate($this->current->get(), $request->user(), $order, $sample, 'rejected', $request->validated())->load(['events', 'associations']));
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/samples/{sample}/associations', operationId: 'physicalSampleAssociate', summary: 'associate; requires samples.associate',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'sample', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SampleAssociationInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function associate(LaboratorySampleRequest $request, int $order, int $sample): LaboratorySampleResource
    {
        return LaboratorySampleResource::make($this->service->associate($this->current->get(), $request->user(), $order, $sample, $request->validated())->load(['events', 'associations']));
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/samples/{sample}/associations/{association}/release', operationId: 'physicalSampleRelease', summary: 'release; requires samples.associate',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'sample', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'association', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SampleReasonInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function release(LaboratorySampleRequest $request, int $order, int $sample, int $association): LaboratorySampleResource
    {
        return LaboratorySampleResource::make($this->service->release($this->current->get(), $request->user(), $order, $sample, $association, $request->validated())->load(['events', 'associations']));
    }

    #[OA\Get(
        path: '/api/v1/laboratory-orders/{order}/samples/{sample}/candidates', operationId: 'physicalSampleCandidates', summary: 'candidates; requires samples.view',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'sample', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleCandidateResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function candidates(LaboratorySampleRequest $request, int $order, int $sample): JsonResponse
    {
        return response()->json(['data' => $this->compatibility->candidates($this->current->get(), $this->service->sample($this->current->get(), $order, $sample))]);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-orders/{order}/samples', operationId: 'physicalSampleIndex', summary: 'index; requires samples.view',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleCollectionResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function index(LaboratorySampleRequest $request, int $order): JsonResponse
    {
        LaboratoryOrder::forLaboratory($this->current->get())->findOrFail($order);

        return response()->json(LaboratorySampleResource::collection(LaboratorySample::forLaboratory($this->current->get())->where('laboratory_order_id', $order)->with(['events', 'associations'])->orderBy('id')->paginate(50))->response()->getData(true));
    }

    #[OA\Get(
        path: '/api/v1/laboratory-orders/{order}/samples/{sample}', operationId: 'physicalSampleShow', summary: 'show; requires samples.view',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'sample', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/SampleResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function show(LaboratorySampleRequest $request, int $order, int $sample): LaboratorySampleResource
    {
        return LaboratorySampleResource::make($this->service->sample($this->current->get(), $order, $sample)->load(['events', 'associations']));
    }

    #[OA\Get(
        path: '/api/v1/laboratory-orders/{order}/operational', operationId: 'physicalSampleOperational', summary: 'operational; requires samples.view and orders.view',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/OrderOperationalResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function operational(LaboratorySampleRequest $request, int $order): JsonResponse
    {
        Gate::forUser($request->user())->authorize('orders.view');

        $data = $this->service->operational($this->current->get(), $order);
        $data['clinical_finalization']['can_finalize'] = $data['clinical_finalization']['eligible'] && $request->user()->can('orders.clinical_finalize');

        return response()->json(['data' => $data]);
    }

    #[OA\Post(
        path: '/api/v1/laboratory-orders/{order}/exams/{line}/start', operationId: 'physicalSampleStart', summary: 'start; requires orders.start_exam',
        security: [['sanctumCookie' => []]], tags: ['Physical Samples'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'), new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SampleEmptyInput')),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation; creation retries replay their original response.', content: new OA\JsonContent(ref: '#/components/schemas/OrderExamStartResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'),
            new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active user, tenant membership, subscription and operation permission required.'),
            new OA\Response(response: 404, description: 'Resource not accessible in this laboratory and order.'),
            new OA\Response(response: 409, description: 'State, clinical dependency, compatibility, readiness or idempotency conflict.', content: new OA\JsonContent(ref: '#/components/schemas/SampleConflict')),
            new OA\Response(response: 422, description: 'Invalid input or local reference.'),
        ],
    )]
    public function start(LaboratorySampleRequest $request, int $order, int $line): JsonResponse
    {
        $line = $this->service->start($this->current->get(), $request->user(), $order, $line);

        return response()->json(['data' => ['id' => $line->id, 'operational_status' => 'started', 'processing_started_at' => $line->processing_started_at->toISOString(), 'processing_started_by' => $line->processing_started_by]]);
    }
}
