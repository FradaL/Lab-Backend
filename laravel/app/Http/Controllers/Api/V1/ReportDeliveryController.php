<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\ReportDelivery\ReportDeliveryPolicyService;
use App\ReportDelivery\ReportDeliveryService;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportDeliveryController extends Controller
{
    public function __construct(private ReportDeliveryPolicyService $policies, private ReportDeliveryService $deliveries, private CurrentLaboratory $current) {}

    public function policy(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->policies->read($this->current->get(), $request->user())]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->policies->update($this->current->get(), $request->user(), $request->all())]);
    }

    public function eligibility(Request $request, int $order, int $report): JsonResponse
    {
        return response()->json(['data' => $this->deliveries->eligibility($this->current->get(), $request->user(), $order, $report)]);
    }

    public function requestException(Request $request, int $order, int $report): JsonResponse
    {
        return response()->json(['data' => $this->deliveries->requestException($this->current->get(), $request->user(), $order, $report, $request->all())], 201);
    }

    public function exceptions(Request $request, int $order, int $report): JsonResponse
    {
        return response()->json($this->deliveries->exceptions($this->current->get(), $request->user(), $order, $report));
    }

    public function authorizeException(Request $request, int $order, int $report, int $exception): JsonResponse
    {
        return response()->json(['data' => $this->deliveries->authorizeException($this->current->get(), $request->user(), $order, $report, $exception, $request->all())]);
    }

    public function revokeException(Request $request, int $order, int $report, int $exception): JsonResponse
    {
        return response()->json(['data' => $this->deliveries->revokeException($this->current->get(), $request->user(), $order, $report, $exception, $request->all())]);
    }

    public function prepare(Request $request, int $order, int $report): JsonResponse
    {
        $preparation = $this->deliveries->prepare($this->current->get(), $request->user(), $order, $report, $request->all(), (string) $request->header('Idempotency-Key'));

        return response()->json(['data' => $this->deliveries->preparationData($preparation)], 201);
    }

    public function downloadPreparation(Request $request, int $order, int $report, int $preparation): Response
    {
        [$issuance,$prepared,$bytes] = $this->deliveries->downloadPreparation($this->current->get(), $request->user(), $order, $report, $preparation);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$issuance->number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Delivery-Preparation-ID' => (string) $prepared->id]);
    }

    public function deliver(Request $request, int $order, int $report): JsonResponse
    {
        $delivery = $this->deliveries->deliver($this->current->get(), $request->user(), $order, $report, $request->all(), (string) $request->header('Idempotency-Key'));

        return response()->json(['data' => $this->deliveries->deliveryData($delivery)], 201);
    }

    public function history(Request $request, int $order, int $report): JsonResponse
    {
        return response()->json($this->deliveries->deliveries($this->current->get(), $request->user(), $order, $report));
    }
}
