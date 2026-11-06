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

final class RecordOrderPayment
{
    public function __construct(private CashAuthority $authority, private AuditWriter $audit, private OrderFinancialState $financial) {}

    /** @return array{body: array, replayed: bool} */
    public function execute(Laboratory $lab, User $actor, int $orderId, array $input, string $key): array
    {
        return $this->authority->run($lab, $actor, CashAuthority::CREATE, function () use ($lab, $actor, $orderId, $input, $key): array {
            $input = $this->validate($input, $key);
            $keyHash = hash('sha256', $key);
            $canonical = [
                'operation' => 'record_payment', 'laboratory_order_id' => $orderId, 'target_id' => null,
                'actor_id' => $actor->id, 'expected_revision' => $input['expected_revision'],
                'amount' => $input['amount'], 'method' => $input['method'], 'reference' => $input['reference'], 'reason' => null,
            ];
            ksort($canonical);
            $requestHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$lab->id.':record_payment:'.$keyHash]);
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $prior = DB::table('laboratory_order_cash_operations')->where('laboratory_id', $lab->id)
                ->where('operation_type', 'record_payment')->where('key_hash', $keyHash)->first();
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
                throw new ClinicalConflict('cash_revision_conflict', 'La revisión financiera cambió. Actualiza la orden antes de registrar el cobro.');
            }
            $revision = $this->financial->next($state->revision);
            $overflow = DB::selectOne('SELECT COALESCE(SUM(amount),0) + CAST(? AS numeric) > 9999999999999999.99 AS overflow FROM laboratory_order_cash_movements WHERE laboratory_id=? AND laboratory_order_id=?', [$input['amount'], $lab->id, $orderId]);
            if ($overflow->overflow) {
                throw new ClinicalConflict('cash_accumulator_overflow', 'El acumulado financiero alcanzó su límite.');
            }
            $operation = (string) Str::ulid();
            $time = now()->toISOString();
            DB::table('laboratory_order_financial_states')->where('id', $state->id)->update(['revision' => $revision, 'updated_at' => $time]);
            $id = DB::table('laboratory_order_cash_movements')->insertGetId([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'operation_id' => $operation,
                'financial_revision' => $revision, 'type' => 'payment', 'amount' => $input['amount'],
                'currency' => $order->currency, 'method' => $input['method'], 'reference' => $input['reference'],
                'created_by' => $actor->id, 'recorded_at' => $time,
            ]);
            $resource = ['id' => $id, 'financial_revision' => $revision, 'created_by' => $actor->id, 'recorded_at' => $time,
                'type' => 'payment', 'amount' => $input['amount'], 'currency' => $order->currency,
                'method' => $input['method'], 'reference' => $input['reference'], 'reason' => null,
                'target_payment_id' => null, 'payment_status' => 'active'];
            $body = ['data' => ['operation_id' => $operation, 'operation' => 'record_payment', 'laboratory_order_id' => $orderId,
                'financial_revision' => $revision, 'resource' => $resource]];
            $this->financial->revision($order, $revision, $state->revision, 'payment_recorded', $actor->id, $time, $id);
            DB::table('laboratory_order_cash_operations')->insert([
                'id' => $operation, 'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId,
                'operation_type' => 'record_payment', 'key_hash' => $keyHash, 'request_hash' => $requestHash,
                'created_by' => $actor->id, 'recorded_at' => $time, 'http_status' => 201,
                'response_snapshot' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $this->audit->record($lab, $actor, new AuditEvent('cash.payment_recorded', 'cash_movement', $id,
                newValues: $resource + ['operation_id' => $operation, 'laboratory_order_id' => $orderId]));

            // JSONB defines object key order; use the persisted representation for both responses.
            $stored = DB::table('laboratory_order_cash_operations')->where('id', $operation)->value('response_snapshot');

            return ['body' => json_decode($stored, true, flags: JSON_THROW_ON_ERROR), 'replayed' => false];
        });
    }

    private function validate(array $input, string $key): array
    {
        $errors = [];
        foreach (array_diff(array_keys($input), ['expected_revision', 'amount', 'method', 'reference']) as $field) {
            $errors[$field] = ['Este campo no está permitido.'];
        }
        if (! preg_match('/\A[A-Za-z0-9_.-]{16,100}\z/D', $key)) {
            $errors['Idempotency-Key'] = ['Se requiere una clave válida de 16 a 100 caracteres.'];
        }
        if (! is_int($input['expected_revision'] ?? null)) {
            $errors['expected_revision'] = ['La revisión debe ser un entero JSON positivo.'];
        }
        if (! is_string($input['amount'] ?? null) || ! preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D', $input['amount']) || $input['amount'] === '0.00') {
            $errors['amount'] = ['El monto debe ser una cadena decimal positiva de dos decimales dentro del rango permitido.'];
        }
        $validator = Validator::make($input, [
            'expected_revision' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'method' => ['required', 'string', 'in:cash,card,bank_transfer'],
            'reference' => ['sometimes', 'nullable', 'string', 'min:1', 'max:150'],
        ]);
        $errors = array_merge($validator->errors()->messages(), $errors);
        $reference = $input['reference'] ?? null;
        if ((is_string($reference) && trim($reference) === '') || (in_array($input['method'] ?? null, ['card', 'bank_transfer'], true) && (! is_string($reference) || trim($reference) === ''))) {
            $errors['reference'] = ['Indica un comprobante o referencia válido para este medio de pago.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $input['reference'] = $reference === null ? null : trim($reference);

        return $input;
    }
}
