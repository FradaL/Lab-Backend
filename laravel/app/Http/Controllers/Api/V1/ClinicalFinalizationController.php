<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalFinalization\ClinicalResolutionService;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClinicalFinalizationController extends Controller
{
    public function __construct(private ClinicalResolutionService $service, private CurrentLaboratory $current) {}

    public function requestException(Request $r, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->service->requestException($this->current->get(), $r->user(), $order, $line, $r->all())], 201);
    }

    public function exceptions(Request $r, int $order, int $line): JsonResponse
    {
        return response()->json($this->service->exceptions($this->current->get(), $r->user(), $order, $line))->header('Cache-Control', 'private, no-store');
    }

    public function approve(Request $r, int $order, int $line, int $exception): JsonResponse
    {
        return response()->json(['data' => $this->service->decideException($this->current->get(), $r->user(), $order, $line, $exception, true, $r->all())]);
    }

    public function reject(Request $r, int $order, int $line, int $exception): JsonResponse
    {
        return response()->json(['data' => $this->service->decideException($this->current->get(), $r->user(), $order, $line, $exception, false, $r->all())]);
    }

    public function finalizeLine(Request $r, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->service->finalizeLine($this->current->get(), $r->user(), $order, $line, $r->all())]);
    }

    public function finalizeOrder(Request $r, int $order): JsonResponse
    {
        return response()->json(['data' => $this->service->finalizeOrder($this->current->get(), $r->user(), $order, $r->all())]);
    }

    public function show(Request $r, int $order): JsonResponse
    {
        return response()->json(['data' => $this->service->show($this->current->get(), $r->user(), $order)]);
    }
}
