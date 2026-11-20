<?php

namespace App\Http\Controllers\Api\V1;

use App\Cash\ReadPaymentAttempt;
use App\Cash\RecordOrderPayment;
use App\Cash\VoidOrderPayment;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderPaymentController extends Controller
{
    public function attempt(Request $request, int $order, string $idempotencyKey, CurrentLaboratory $current, ReadPaymentAttempt $attempts): JsonResponse
    {
        $result = $attempts->execute($current->get(), $request->user(), $order, $idempotencyKey);

        return response()->json($result, $result['data']['status'] === 'confirmed' ? 200 : 202);
    }

    public function store(Request $request, int $order, CurrentLaboratory $current, RecordOrderPayment $payments): JsonResponse
    {
        $result = $payments->execute($current->get(), $request->user(), $order, $request->all(), (string) $request->header('Idempotency-Key'));

        return response()->json($result['body'], 201, ['Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }

    public function void(Request $request, int $order, int $payment, CurrentLaboratory $current, VoidOrderPayment $payments): JsonResponse
    {
        $result = $payments->execute($current->get(), $request->user(), $order, $payment, $request->all(), (string) $request->header('Idempotency-Key'));

        return response()->json($result['body'], 201, ['Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }
}
