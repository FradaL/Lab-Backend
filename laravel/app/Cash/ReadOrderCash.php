<?php

namespace App\Cash;

use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReadOrderCash
{
    public function __construct(private CashAuthority $authority, private CashLedgerProjection $projection) {}

    public function summary(Laboratory $lab, User $actor, int $orderId, array $query = []): array
    {
        return $this->authority->run($lab, $actor, CashAuthority::VIEW, function () use ($lab, $orderId, $query): array {
            $this->parameters($query, false);
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->sharedLock()->firstOrFail();
            $state = $this->context($order);
            $data = [
                'laboratory_order_id' => $order->id, 'currency' => $order->currency, 'total' => $order->total,
                'available' => $state !== null, 'unavailable_reason' => $state === null ? 'cash_context_unavailable' : null,
                'financial_revision' => $state?->revision, 'delivery_policy' => $order->delivery_policy_snapshot,
                'valid_payments' => null, 'refunds' => null, 'net_payments' => null,
                'balance_due' => null, 'surplus' => null, 'credit' => null,
            ];
            if ($state !== null) {
                $data = array_replace($data, $this->projection->totals($this->source($order), $order->total));
            }

            return ['data' => $data];
        });
    }

    public function movements(Laboratory $lab, User $actor, int $orderId, array $query = []): array
    {
        return $this->authority->run($lab, $actor, CashAuthority::VIEW, function () use ($lab, $orderId, $query): array {
            [$page, $perPage] = $this->parameters($query, true);
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->sharedLock()->firstOrFail();
            $state = $this->context($order);
            if ($state === null) {
                throw new ClinicalConflict('cash_context_unavailable', 'La orden no tiene contexto financiero disponible.');
            }
            $source = $this->source($order);
            $count = (clone $source)->count();
            $lastPage = max(1, intdiv(max($count - 1, 0), $perPage) + 1);
            // An out-of-range page stays empty, including very large valid pages; no offset overflow.
            $rows = $page > $lastPage ? [] : $this->projection->movements($source)
                ->orderByDesc('movement.financial_revision')->orderByDesc('movement.id')
                ->offset(($page - 1) * $perPage)->limit($perPage)->get()->all();

            return ['data' => array_map($this->projection->movementData(...), $rows), 'meta' => [
                'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage,
                'total' => $count, 'financial_revision' => $state->revision,
            ]];
        });
    }

    private function source(LaboratoryOrder $order): Builder
    {
        return DB::table('laboratory_order_cash_movements')->where('laboratory_id', $order->laboratory_id)->where('laboratory_order_id', $order->id);
    }

    /** Every caller holds the shared order lock through all queries and result construction. */
    private function context(LaboratoryOrder $order): ?object
    {
        $state = DB::table('laboratory_order_financial_states')->where('laboratory_id', $order->laboratory_id)
            ->where('laboratory_order_id', $order->id)->first();
        if ($order->delivery_policy_snapshot === null && $state === null) {
            return null;
        }
        $latest = $state === null ? null : DB::table('laboratory_order_financial_revisions')->where('laboratory_id', $order->laboratory_id)
            ->where('laboratory_order_id', $order->id)->where('revision', $state->revision)->first();
        if ($order->delivery_policy_snapshot === null || $latest === null || $latest->total_snapshot !== $order->total
            || ! CarbonImmutable::parse($state->updated_at)->equalTo(CarbonImmutable::parse($latest->recorded_at))
            || $state->active_credit_authorization_id !== null) {
            // BE07 supplies the active-credit reader when its writer/schema are implemented.
            throw new ClinicalConflict('cash_evidence_unavailable', 'El contexto financiero de la orden está incompleto.', status: 503);
        }

        return $state;
    }

    private function parameters(array $query, bool $paginated): array
    {
        $errors = [];
        foreach (array_diff(array_keys($query), $paginated ? ['page', 'per_page'] : []) as $key) {
            $errors[$key] = ['Este parámetro no está permitido.'];
        }
        foreach ($paginated ? ['page' => 1, 'per_page' => 15] : [] as $key => $default) {
            $value = $query[$key] ?? $default;
            $integer = (is_int($value) || is_string($value)) ? filter_var($value, FILTER_VALIDATE_INT) : false;
            if ($integer === false || $integer < 1 || ($key === 'per_page' && $integer > 100) || (array_key_exists($key, $query) && $query[$key] === null)) {
                $errors[$key] = ['Indica un entero positivo'.($key === 'per_page' ? ' de 1 a 100.' : '.')];
            } else {
                $query[$key] = $integer;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $paginated ? [$query['page'], $query['per_page']] : [];
    }
}
