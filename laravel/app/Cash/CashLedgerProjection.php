<?php

namespace App\Cash;

use App\ClinicalExams\ClinicalConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CashLedgerProjection
{
    /** Derive void status without changing or hiding the original payment. */
    public function movements(Builder $source): Builder
    {
        $voids = (clone $source)->select('target_payment_id')->where('type', 'payment_void')->distinct();

        return DB::query()->fromSub(clone $source, 'movement')
            ->leftJoinSub($voids, 'voided_payment', 'voided_payment.target_payment_id', '=', 'movement.id')
            ->select('movement.*')->selectRaw('voided_payment.target_payment_id IS NOT NULL AS is_voided');
    }

    /** Historical sums are SQL NUMERIC, checked before any conversion to cents. */
    public function totals(Builder $source, string $total): array
    {
        $sums = $this->movements($source)->select([])->selectRaw("COALESCE(SUM(CASE WHEN movement.type='payment' AND voided_payment.target_payment_id IS NULL THEN movement.amount ELSE 0 END),0)::text AS valid_payments")
            ->selectRaw("COALESCE(SUM(CASE WHEN movement.type='refund' THEN movement.amount ELSE 0 END),0)::text AS refunds");
        $row = $sums->first();

        return $this->balances($total, $row->valid_payments, $row->refunds);
    }

    public function balances(string $total, string $validPayments, string $refunds): array
    {
        $total = $this->cents($total);
        $valid = $this->cents($validPayments);
        $returned = $this->cents($refunds);
        if ($returned > $valid) {
            throw new ClinicalConflict('cash_evidence_unavailable', 'Los movimientos financieros de la orden son inconsistentes.', status: 503);
        }
        $net = $valid - $returned;

        return [
            'valid_payments' => $this->money($valid), 'refunds' => $this->money($returned),
            'net_payments' => $this->money($net), 'balance_due' => $this->money(max($total - $net, 0)),
            'surplus' => $this->money(max($net - $total, 0)),
        ];
    }

    public function movementData(object $movement): array
    {
        return [
            'id' => (int) $movement->id, 'financial_revision' => (int) $movement->financial_revision,
            'created_by' => (int) $movement->created_by, 'recorded_at' => CarbonImmutable::parse($movement->recorded_at)->toISOString(),
            'type' => $movement->type, 'amount' => $movement->amount, 'currency' => $movement->currency,
            'method' => $movement->method, 'reference' => $movement->reference, 'reason' => $movement->reason,
            'target_payment_id' => $movement->target_payment_id === null ? null : (int) $movement->target_payment_id,
            'payment_status' => $movement->type === 'payment' ? ($movement->is_voided ? 'voided' : 'active') : null,
        ];
    }

    private function cents(string $value): int
    {
        if (! preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/D', $value, $match)) {
            throw new ClinicalConflict('cash_evidence_unavailable', 'El importe financiero no es válido.', status: 503);
        }
        $cents = ltrim($match[1].str_pad($match[2] ?? '', 2, '0'), '0');
        if (strlen($cents) > 18 || (strlen($cents) === 18 && strcmp($cents, '999999999999999999') > 0)) {
            throw new ClinicalConflict('cash_accumulator_overflow', 'El acumulado financiero alcanzó su límite.');
        }

        return (int) $cents;
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
