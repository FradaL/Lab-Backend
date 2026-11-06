<?php

namespace Tests\Feature\Cash;

use App\Cash\CashLedgerProjection;
use App\ClinicalExams\ClinicalConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashLedgerProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function source(array $rows)
    {
        // Synthetic read-only relation: never relax the production ledger's payment-only guards.
        return DB::query()->fromRaw('jsonb_to_recordset(?) AS fixture(id bigint,type text,amount numeric(12,2),target_payment_id bigint,financial_revision integer,created_by bigint,recorded_at timestamptz,currency text,method text,reference text,reason text)', [json_encode($rows, JSON_THROW_ON_ERROR)]);
    }

    #[DataProvider('books')]
    public function test_sql_projection_excludes_voided_payments_once_and_refunds_reduce_net(array $rows, string $total, array $expected): void
    {
        $this->assertSame($expected, app(CashLedgerProjection::class)->totals($this->source($rows), $total));
    }

    public static function books(): array
    {
        $p = fn ($id, $type, $amount, $target = null) => ['id' => $id, 'type' => $type, 'amount' => $amount, 'target_payment_id' => $target];
        $b = fn ($valid, $refund, $net, $due, $surplus) => ['valid_payments' => $valid, 'refunds' => $refund, 'net_payments' => $net, 'balance_due' => $due, 'surplus' => $surplus];

        return [
            [[], '0.00', $b('0.00', '0.00', '0.00', '0.00', '0.00')],
            [[$p(1, 'payment', '0.10'), $p(2, 'payment', '0.20')], '0.30', $b('0.30', '0.00', '0.30', '0.00', '0.00')],
            [[$p(1, 'payment', '100.00'), $p(2, 'payment_void', '100.00', 1)], '80.00', $b('0.00', '0.00', '0.00', '80.00', '0.00')],
            [[$p(1, 'payment', '100.00'), $p(2, 'refund', '10.00')], '80.00', $b('100.00', '10.00', '90.00', '0.00', '10.00')],
            [[$p(1, 'payment', '100.00'), $p(2, 'refund', '20.00')], '80.00', $b('100.00', '20.00', '80.00', '0.00', '0.00')],
            [[$p(1, 'payment', '100.00'), $p(2, 'payment', '50.00'), $p(3, 'payment_void', '100.00', 1), $p(4, 'refund', '10.00')], '80.00', $b('50.00', '10.00', '40.00', '40.00', '0.00')],
            [[$p(1, 'credit_authorized', '100.00'), $p(2, 'delivery_exception', '100.00')], '80.00', $b('0.00', '0.00', '0.00', '80.00', '0.00')],
            [[$p(1, 'payment', '9999999999.99'), $p(2, 'payment', '9999999999.99')], '0.00', $b('19999999999.98', '0.00', '19999999999.98', '0.00', '19999999999.98')],
        ];
    }

    public function test_movement_status_is_derived_without_changing_original_amount_or_including_unrelated_voids(): void
    {
        $base = ['financial_revision' => 2, 'created_by' => 1, 'recorded_at' => '2026-11-06T12:00:00.123456Z', 'currency' => 'GTQ', 'method' => 'cash', 'reference' => null, 'reason' => null];
        $rows = [
            $base + ['id' => 1, 'type' => 'payment', 'amount' => '100.00', 'target_payment_id' => null],
            array_replace($base, ['id' => 2, 'type' => 'payment_void', 'amount' => '100.00', 'target_payment_id' => 1, 'method' => null, 'reason' => 'FICTICIO — anulación']),
            array_replace($base, ['id' => 3, 'type' => 'refund', 'amount' => '10.00', 'target_payment_id' => null, 'reason' => 'FICTICIO — devolución']),
            $base + ['id' => 4, 'type' => 'payment', 'amount' => '50.00', 'target_payment_id' => null],
        ];
        $projection = app(CashLedgerProjection::class);
        $data = $projection->movements($this->source($rows))->orderBy('movement.id')->get()->map($projection->movementData(...))->all();
        $this->assertSame(['voided', null, null, 'active'], array_column($data, 'payment_status'));
        $this->assertSame('100.00', $data[0]['amount']);
        $this->assertSame(1, $data[1]['target_payment_id']);
        $this->assertSame('2026-11-06T12:00:00.123456Z', $data[0]['recorded_at']);
    }

    public function test_aggregate_limit_uses_exact_integer_cents_without_float_rounding(): void
    {
        $values = app(CashLedgerProjection::class)->balances('9999999999.99', '9999999999999999.99', '0.01');
        $this->assertSame('9999999999999999.98', $values['net_payments']);
        $this->assertSame('9999989999999999.99', $values['surplus']);
    }

    #[DataProvider('invalidTotals')]
    public function test_invalid_or_overflowed_totals_are_never_truncated(string $valid, string $refund, string $code): void
    {
        try {
            app(CashLedgerProjection::class)->balances('80.00', $valid, $refund);
            $this->fail('Expected financial conflict');
        } catch (ClinicalConflict $e) {
            $this->assertSame($code,$e->errorCode);
        }
    }

    public static function invalidTotals(): array
    {
        return [['10000000000000000.00', '0.00', 'cash_accumulator_overflow'], ['0.00', '0.01', 'cash_evidence_unavailable'], ['-1.00', '0.00', 'cash_evidence_unavailable'], ['1.001', '0.00', 'cash_evidence_unavailable']];
    }
}
