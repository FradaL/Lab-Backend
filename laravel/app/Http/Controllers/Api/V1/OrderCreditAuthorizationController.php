<?php

namespace App\Http\Controllers\Api\V1;

use App\Cash\AuthorizeOrderCredit;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderCreditAuthorizationController extends Controller
{
    public function store(Request $request, int $order, CurrentLaboratory $current, AuthorizeOrderCredit $credit): JsonResponse
    {
        $result = $credit->authorize($current->get(), $request->user(), $order, $request->all(), (string) $request->header('Idempotency-Key'));
        return response()->json($result['body'], 201, ['Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }

    public function revoke(Request $request, int $order, int $authorization, CurrentLaboratory $current, AuthorizeOrderCredit $credit): JsonResponse
    {
        $result = $credit->revoke($current->get(), $request->user(), $order, $authorization, $request->all(), (string) $request->header('Idempotency-Key'));
        return response()->json($result['body'], 201, ['Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }
}
