<?php

namespace App\Cash;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VoidOrderPayment
{
    public function __construct(private CashAuthority $authority, private AuditWriter $audit, private OrderFinancialState $financial, private CashLedgerProjection $projection) {}

    /** @return array{body: array, replayed: bool} */
    public function execute(Laboratory $lab, User $actor, int $orderId, int $paymentId, array $input, string $key): array
    {
        return $this->authority->run($lab, $actor, CashAuthority::VOID, function () use ($lab, $actor, $orderId, $paymentId, $input, $key): array {
            $input = $this->validate($input, $key);
            $keyHash = hash('sha256', $key);
            $canonical = [
                'operation' => 'void_payment', 'laboratory_order_id' => $orderId, 'target_id' => $paymentId,
                'actor_id' => $actor->id, 'expected_revision' => $input['expected_revision'],
                'amount' => null, 'method' => null, 'reference' => null, 'reason' => $input['reason'],
            ];
            ksort($canonical);
            $requestHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$lab->id.':void_payment:'.$keyHash]);
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = DB::table('laboratory_order_cash_movements')->where('laboratory_id', $lab->id)
                ->where('laboratory_order_id', $orderId)->where('type', 'payment')->where('id', $paymentId)->first();
            abort_if($payment === null, 404);
            $prior = DB::table('laboratory_order_cash_operations')->where('laboratory_id', $lab->id)
                ->where('operation_type', 'void_payment')->where('key_hash', $keyHash)->first();
            if ($prior !== null) {
                if (! hash_equals($prior->request_hash, $requestHash)) {
                    throw new ClinicalConflict('cash_idempotency_conflict', 'La clave identifica otra operación de Caja.');
                }

                return ['body' => json_decode($prior->response_snapshot, true, flags: JSON_THROW_ON_ERROR), 'replayed' => true];
            }
            if ($order->delivery_policy_snapshot === null) {
                throw new ClinicalConflict('cash_context_unavailable', 'La orden no tiene contexto financiero disponible.');
            }
            $state = DB::table('laboratory_order_financial_states')->where('laboratory_id', $lab->id)->where('laboratory_order_id', $orderId)->lockForUpdate()->first();
            $latest = $state === null ? null : DB::table('laboratory_order_financial_revisions')->where('laboratory_id', $lab->id)
                ->where('laboratory_order_id', $orderId)->where('revision', $state->revision)->first();
            if ($latest === null || $latest->total_snapshot !== $order->total) {
                throw new ClinicalConflict('cash_evidence_unavailable', 'El contexto financiero de la orden está incompleto.', status: 503);
            }
            if ($state->revision !== $input['expected_revision']) {
                throw new ClinicalConflict('cash_revision_conflict', 'La revisión financiera cambió. Actualiza la orden antes de anular el cobro.');
            }
            if (DB::table('laboratory_order_cash_movements')->where('laboratory_id', $lab->id)
                ->where('laboratory_order_id', $orderId)->where('type', 'payment_void')->where('target_payment_id', $paymentId)->exists()) {
                throw new ClinicalConflict('cash_payment_already_voided', 'El cobro ya está anulado.');
            }
            $source = DB::table('laboratory_order_cash_movements')->where('laboratory_id', $lab->id)->where('laboratory_order_id', $orderId);
            $totals = $this->projection->totals($source, $order->total);
            $negative = DB::selectOne('SELECT CAST(? AS numeric) - CAST(? AS numeric) < 0 AS negative', [$totals['net_payments'], $payment->amount]);
            if ($negative->negative) {
                throw new ClinicalConflict('cash_void_negative_net', 'No se puede anular este cobro porque los pagos netos quedarían negativos. Revisa los movimientos registrados.');
            }
            $revision = $this->financial->next($state->revision);
            $operation = (string) Str::ulid();
            $time = now()->toISOString();
            DB::table('laboratory_order_financial_states')->where('id', $state->id)->update(['revision' => $revision, 'updated_at' => $time]);
            $id = DB::table('laboratory_order_cash_movements')->insertGetId([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'operation_id' => $operation,
                'financial_revision' => $revision, 'type' => 'payment_void', 'amount' => $payment->amount,
                'currency' => $order->currency, 'method' => null, 'reference' => null,
                'reason' => $input['reason'], 'target_payment_id' => $paymentId,
                'created_by' => $actor->id, 'recorded_at' => $time,
            ]);
            $resource = ['id' => $id, 'financial_revision' => $revision, 'created_by' => $actor->id, 'recorded_at' => $time,
                'type' => 'payment_void', 'amount' => $payment->amount, 'currency' => $order->currency,
                'method' => null, 'reference' => null, 'reason' => $input['reason'],
                'target_payment_id' => $paymentId, 'payment_status' => null];
            $body = ['data' => ['operation_id' => $operation, 'operation' => 'void_payment', 'laboratory_order_id' => $orderId,
                'financial_revision' => $revision, 'resource' => $resource]];
            $this->financial->revision($order, $revision, $state->revision, 'payment_voided', $actor->id, $time, $id);
            DB::table('laboratory_order_cash_operations')->insert([
                'id' => $operation, 'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId,
                'operation_type' => 'void_payment', 'key_hash' => $keyHash, 'request_hash' => $requestHash,
                'created_by' => $actor->id, 'recorded_at' => $time, 'http_status' => 201,
                'response_snapshot' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $this->audit->record($lab, $actor, new AuditEvent('cash.payment_voided', 'cash_movement', $id,
                newValues: $resource + ['operation_id' => $operation, 'laboratory_order_id' => $orderId]));

            // JSONB defines object key order; use the persisted representation for both responses.
            $stored = DB::table('laboratory_order_cash_operations')->where('id', $operation)->value('response_snapshot');

            return ['body' => json_decode($stored, true, flags: JSON_THROW_ON_ERROR), 'replayed' => false];
        });
    }

    private function validate(array $input, string $key): array
    {
        $errors = [];
        foreach (array_diff(array_keys($input), ['expected_revision', 'reason']) as $field) {
            $errors[$field] = ['Este campo no está permitido.'];
        }
        if (! preg_match('/\A[A-Za-z0-9_.-]{16,100}\z/D', $key)) {
            $errors['Idempotency-Key'] = ['Se requiere una clave válida de 16 a 100 caracteres.'];
        }
        if (! is_int($input['expected_revision'] ?? null)) {
            $errors['expected_revision'] = ['La revisión debe ser un entero JSON positivo.'];
        }
        $validator = Validator::make($input, [
            'expected_revision' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $errors = array_merge($validator->errors()->messages(), $errors);
        if (is_string($input['reason'] ?? null) && trim($input['reason']) === '') {
            $errors['reason'] = ['Indica el motivo de la anulación.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $input['reason'] = trim($input['reason']);

        return $input;
    }
}
