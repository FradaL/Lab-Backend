<?php

namespace App\Http\Controllers\Api\V1;

use App\Cash\RecordOrderRefund;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderRefundController extends Controller
{
    public function store(Request $request, int $order, CurrentLaboratory $current, RecordOrderRefund $refunds): JsonResponse
    {
        $result = $refunds->execute($current->get(), $request->user(), $order, $request->all(), (string) $request->header('Idempotency-Key'));

        return response()->json($result['body'], 201, ['Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }
}
