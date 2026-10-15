<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalResults\LaboratoryResultService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryResult\SaveLaboratoryResultsRequest;
use App\Models\LaboratoryOrder;
use App\Models\ValidationProfessional;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LaboratoryResultController extends Controller
{
    public function __construct(private LaboratoryResultService $service, private CurrentLaboratory $current) {}

    #[OA\Get(
        path: '/api/v1/laboratory-orders/{order}/exams/{line}/results', operationId: 'clinicalResultsShow',
        summary: 'Preliminary results; requires results.view', security: [['sanctumCookie' => []]], tags: ['Clinical Results'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Fixed published parameters and preliminary capture, or paginated change history.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalResultsResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'), new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active actor, membership, subscription and permission required. Submitting an existing parameter also requires results.edit, including an identical retry. Without results.view, such retries advance revision and omit parameter values.'),
            new OA\Response(response: 404, description: 'Order or line unavailable in this laboratory.'),
            new OA\Response(response: 409, description: 'Unavailable capture, noneditable clinical state or stale revision.'),
            new OA\Response(response: 422, description: 'Invalid input, parameter or typed value.')],
    )]
    public function show(Request $request, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->permissions($request, $this->service->show($this->current->get(), $order, $line))]);
    }

    #[OA\Put(
        path: '/api/v1/laboratory-orders/{order}/exams/{line}/results', operationId: 'clinicalResultsSave',
        summary: 'Preliminary results; requires results.capture', security: [['sanctumCookie' => []]], tags: ['Clinical Results'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClinicalResultsInput')),
        responses: [new OA\Response(response: 200, description: 'Fixed published parameters and preliminary capture, or paginated change history.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalResultsResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'), new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active actor, membership, subscription and permission required. Submitting an existing parameter also requires results.edit, including an identical retry. Without results.view, such retries advance revision and omit parameter values.'),
            new OA\Response(response: 404, description: 'Order or line unavailable in this laboratory.'),
            new OA\Response(response: 409, description: 'Unavailable capture, noneditable clinical state or stale revision.'),
            new OA\Response(response: 422, description: 'Invalid input, parameter or typed value.')],
    )]
    public function save(SaveLaboratoryResultsRequest $request, int $order, int $line): JsonResponse
    {
        $data = $this->service->save($this->current->get(), $request->user(), $order, $line, $request->validated());
        if (! $request->user()->can('results.view')) {
            unset($data['parameters'], $data['applied_references']);
        }

        return response()->json(['data' => $this->permissions($request, $data)]);
    }

    #[OA\Get(
        path: '/api/v1/laboratory-orders/{order}/exams/{line}/results/history', operationId: 'clinicalResultsHistory',
        summary: 'Preliminary results; requires results.view_history', security: [['sanctumCookie' => []]], tags: ['Clinical Results'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Fixed published parameters and preliminary capture, or paginated change history.', content: new OA\JsonContent(ref: '#/components/schemas/ClinicalResultsHistoryResponse')),
            new OA\Response(response: 400, description: 'Laboratory context required.'), new OA\Response(response: 401, description: 'Authentication required.'),
            new OA\Response(response: 403, description: 'Active actor, membership, subscription and permission required. Submitting an existing parameter also requires results.edit, including an identical retry. Without results.view, such retries advance revision and omit parameter values.'),
            new OA\Response(response: 404, description: 'Order or line unavailable in this laboratory.'),
            new OA\Response(response: 409, description: 'Unavailable capture, noneditable clinical state or stale revision.'),
            new OA\Response(response: 422, description: 'Invalid input, parameter or typed value.')],
    )]
    public function history(Request $request, int $order, int $line): JsonResponse
    {
        return response()->json($this->service->history($this->current->get(), $order, $line));
    }

    private function permissions(Request $request, array $data): array
    {
        $lab = $this->current->get()->fresh();
        $clinicalAuthorized = $lab->validation_policy === 'permission_based' || ValidationProfessional::forLaboratory($lab)->where('user_id', $request->user()->id)->where('status', 'authorized')->exists();
        // Validated captures are unavailable for ordinary capture but remain eligible for rectification.
        $order = LaboratoryOrder::forLaboratory($lab)->findOrFail($data['laboratory_order_id']);
        $data['validation_blocking_reasons'] = [];
        if ($data['clinical_exception_pending'] ?? false) {
            $data['validation_blocking_reasons'][] = 'clinical_exception_pending';
        }
        if ($data['clinical_resolution'] ?? null) {
            $data['validation_blocking_reasons'][] = 'clinical_line_resolved';
        }
        if (! $data['progress']['complete']) {
            $data['validation_blocking_reasons'][] = 'incomplete_capture';
        }
        if (! $clinicalAuthorized) {
            $data['validation_blocking_reasons'][] = 'professional_authorization_required';
        }
        if (! $request->user()->can('results.validate')) {
            $data['validation_blocking_reasons'][] = 'validation_permission_required';
        }
        $data['permissions'] = ['can_view' => $request->user()->can('results.view'),
            'can_capture' => $data['capture_available'] && $request->user()->can('results.capture'),
            'can_edit' => $data['capture_available'] && $request->user()->can('results.capture') && $request->user()->can('results.edit'),
            'can_validate' => ! ($data['clinical_exception_pending'] ?? false) && $data['capture_available'] && $data['progress']['complete'] && $clinicalAuthorized && $request->user()->can('results.validate'),
            'can_rectify' => ($data['clinical_resolution'] ?? null) === null && $order->status === 'in_process' && $data['processing_started_at'] !== null && $data['clinical_status'] === 'validated' && $clinicalAuthorized && $request->user()->can('results.rectify'),
            'can_view_validations' => $request->user()->can('results.validation_history'),
            'can_view_history' => $request->user()->can('results.view_history')];

        return $data;
    }
}
