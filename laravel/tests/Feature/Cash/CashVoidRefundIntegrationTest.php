<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\ReadOrderCash;
use App\Cash\RecordOrderPayment;
use App\Cash\RecordOrderRefund;
use App\Cash\VoidOrderPayment;
use App\ClinicalExams\ClinicalConflict;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use Database\MigrationSupport\CashLedgerSchema;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashVoidRefundIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        foreach ([CashAuthority::CREATE, CashAuthority::VOID, CashAuthority::REFUND, CashAuthority::VIEW] as $permission) {
            $this->assignDirectLaboratoryPermission($actor, $lab, $permission);
        }
        setPermissionsTeamId($lab->id);

        return [$lab, $actor, $order];
    }

    private function payment(array $context, int $revision, string $amount, string $key): int
    {
        [$lab, $actor, $order] = $context;

        return app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => $revision, 'amount' => $amount, 'method' => 'cash'], $key)['body']['data']['resource']['id'];
    }

    private function refund(array $context, int $revision): void
    {
        [$lab, $actor, $order] = $context;
        app(RecordOrderRefund::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => $revision, 'amount' => '20.00', 'method' => 'cash', 'reason' => 'Devolución ficticia'], 'ficticio-merge-refund-key');
    }

    private function voidPayment(array $context, int $payment, int $revision): void
    {
        [$lab, $actor, $order] = $context;
        app(VoidOrderPayment::class)->execute($lab, $actor, $order->id, $payment,
            ['expected_revision' => $revision, 'reason' => 'Corrección ficticia'], 'ficticio-merge-void-key');
    }

    private function evidence(): array
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $snapshot = [];
        foreach (['laboratory_order_cash_movements', 'laboratory_order_cash_operations', 'laboratory_order_financial_states', 'laboratory_order_financial_revisions', 'audit_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    #[DataProvider('installationOrders')]
    public function test_each_migration_preserves_existing_evidence_and_the_other_capability(bool $refundFirst): void
    {
        $voidMigration = require database_path('migrations/2026_11_06_000003_enable_order_payment_voids.php');
        $refundMigration = require database_path('migrations/2026_11_06_000003_enable_order_cash_refunds.php');
        $voidMigration->down();
        $refundMigration->down();
        $base = require database_path('migrations/2026_11_06_000002_create_order_cash_ledger.php');
        $base->down();
        $base->up();
        DB::unprepared(file_get_contents(base_path('tests/Fixtures/Cash/'.($refundFirst ? 'refunds' : 'voids').'-before-merge.sql')));
        $context = $this->context();
        $payment = $this->payment($context, 1, '100.00', 'ficticio-merge-base-payment');
        if ($refundFirst) {
            $this->refund($context, 2);
        } else {
            $this->voidPayment($context, $payment, 2);
        }
        $before = $this->evidence();
        ($refundFirst ? $voidMigration : $refundMigration)->up();
        $this->assertSame($before, $this->evidence());
        ($refundFirst ? $voidMigration : $refundMigration)->down();
        $this->assertSame($before, $this->evidence());
        ($refundFirst ? $voidMigration : $refundMigration)->up();
        $this->assertSame($before, $this->evidence());
        if ($refundFirst) {
            try {
                $this->voidPayment($context, $payment, 3);
                $this->fail('A real refund must prevent a negative net.');
            } catch (ClinicalConflict $error) {
                $this->assertSame('cash_void_negative_net', $error->errorCode);
            }
            $this->assertSame($before, $this->evidence());
            $this->payment($context, 3, '20.00', 'ficticio-merge-correct-payment');
            $this->voidPayment($context, $payment, 4);
            $expectedNet = '0.00';
        } else {
            $this->payment($context, 3, '100.00', 'ficticio-merge-correct-payment');
            $this->refund($context, 4);
            $expectedNet = '80.00';
        }
        [$lab, $actor, $order] = $context;
        $summary = app(ReadOrderCash::class)->summary($lab, $actor, $order->id)['data'];
        $this->assertSame($expectedNet, $summary['net_payments']);
        $this->assertSame(5, $summary['financial_revision']);
        $this->assertSame(1, DB::table('laboratory_order_cash_movements')->where('type', 'payment_void')->count());
        $this->assertSame(1, DB::table('laboratory_order_cash_movements')->where('type', 'refund')->count());
        $before = $this->evidence();
        $repair = require database_path('migrations/2026_11_06_000004_reconcile_cash_voids_and_refunds.php');
        $repair->up();
        $this->assertSame($before, $this->evidence());
        $repair->down();
        $this->assertSame($before, $this->evidence());
    }

    public static function installationOrders(): array
    {
        return [[true], [false]];
    }

    public function test_corrective_migration_repairs_two_original_migrations_already_applied(): void
    {
        (require database_path('migrations/2026_11_06_000003_enable_order_payment_voids.php'))->down();
        (require database_path('migrations/2026_11_06_000003_enable_order_cash_refunds.php'))->down();
        $base = require database_path('migrations/2026_11_06_000002_create_order_cash_ledger.php');
        $base->down();
        $base->up();
        foreach (['refunds', 'voids'] as $kind) {
            DB::unprepared(file_get_contents(base_path('tests/Fixtures/Cash/'.$kind.'-before-merge.sql')));
        }
        $this->assertFalse(CashLedgerSchema::supports('record_refund'));
        $context = $this->context();
        $payment = $this->payment($context, 1, '100.00', 'ficticio-repair-payment');
        $this->voidPayment($context, $payment, 2);
        $before = $this->evidence();
        (require database_path('migrations/2026_11_06_000004_reconcile_cash_voids_and_refunds.php'))->up();
        $this->assertTrue(CashLedgerSchema::supports('record_refund'));
        $this->assertSame($before, $this->evidence());
        $this->payment($context, 3, '100.00', 'ficticio-repair-new-payment');
        $this->refund($context, 4);
        [$lab, $actor, $order] = $context;
        $this->assertSame('80.00', app(ReadOrderCash::class)->summary($lab, $actor, $order->id)['data']['net_payments']);
        $this->evidence();
    }

    public function test_sql_refund_guard_still_excludes_voided_money(): void
    {
        $context = $this->context();
        $payment = $this->payment($context, 1, '100.00', 'ficticio-merge-sql-payment');
        $this->voidPayment($context, $payment, 2);
        $before = $this->evidence();
        [$lab, $actor, $order] = $context;
        try {
            DB::transaction(function () use ($lab, $actor, $order): void {
                DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->update(['revision' => 4]);
                DB::table('laboratory_order_cash_movements')->insert([
                    'laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'operation_id' => (string) Str::ulid(),
                    'financial_revision' => 4, 'type' => 'refund', 'amount' => '0.01', 'currency' => $order->currency,
                    'method' => 'cash', 'reason' => 'Ficticio', 'created_by' => $actor->id, 'recorded_at' => now()->toISOString(),
                ]);
            });
            $this->fail('Voided money is not refundable surplus.');
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->errorInfo[0]);
            $this->assertStringContainsString('La devolución supera el excedente disponible', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
    }
}
