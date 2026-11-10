<?php

namespace App\Cash;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only recovery lookup. Absence is deliberately inconclusive: an earlier POST may still arrive. */
final class ReadPaymentAttempt
{
    public function __construct(private CashAuthority $authority) {}

    public function execute(Laboratory $lab, User $actor, int $orderId, string $key): array
    {
        return $this->authority->run($lab, $actor, CashAuthority::CREATE, function () use ($lab, $actor, $orderId, $key): array {
            if (! preg_match('/\A[A-Za-z0-9_.-]{16,100}\z/D', $key)) {
                throw ValidationException::withMessages(['Idempotency-Key' => ['Se requiere una clave válida de 16 a 100 caracteres.']]);
            }
            // Preserve the neutral missing-resource behavior before inspecting evidence.
            LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->firstOrFail();
            $operation = DB::table('laboratory_order_cash_operations')
                ->where('laboratory_id', $lab->id)->where('laboratory_order_id', $orderId)
                ->where('operation_type', 'record_payment')->where('created_by', $actor->id)
                ->where('key_hash', hash('sha256', $key))->first();

            if ($operation === null) {
                return ['data' => ['status' => 'unknown']];
            }

            return ['data' => [
                'status' => 'confirmed', 'operation_id' => $operation->id,
                'http_status' => $operation->http_status,
                'response' => json_decode($operation->response_snapshot, true, flags: JSON_THROW_ON_ERROR),
            ]];
        });
    }
}
