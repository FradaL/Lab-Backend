<?php

namespace App\Http\Controllers\Api\V1;

use App\Cash\ReadOrderCash;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderCashController extends Controller
{
    public function summary(Request $request, int $order, CurrentLaboratory $current, ReadOrderCash $cash): JsonResponse
    {
        return response()->json($cash->summary($current->get(), $request->user(), $order, $request->query()));
    }

    public function movements(Request $request, int $order, CurrentLaboratory $current, ReadOrderCash $cash): JsonResponse
    {
        return response()->json($cash->movements($current->get(), $request->user(), $order, $request->query()));
    }

    public function credits(Request $request, int $order, CurrentLaboratory $current, ReadOrderCash $cash): JsonResponse
    {
        return response()->json($cash->credits($current->get(), $request->user(), $order, $request->query()));
    }
}
