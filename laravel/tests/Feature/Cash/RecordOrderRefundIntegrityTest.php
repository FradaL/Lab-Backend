<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\RecordOrderPayment;
use App\Cash\RecordOrderRefund;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordOrderRefundIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        foreach ([CashAuthority::CREATE, CashAuthority::REFUND] as $permission) {
            $this->assignDirectLaboratoryPermission($actor, $lab, $permission);
        }
        setPermissionsTeamId($lab->id);
        app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => 1, 'amount' => '100.00', 'method' => 'cash'], 'ficticio-integrity-payment');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return [$lab, $actor, $order];
    }

    private function refund(array $context): array
    {
        [$lab, $actor, $order] = $context;

        return app(RecordOrderRefund::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => 2, 'amount' => '10.00', 'method' => 'cash', 'reason' => 'FICTICIO'], 'ficticio-integrity-refund');
    }

    #[DataProvider('invalidSql')]
    public function test_sql_rejects_invalid_refunds_even_without_service_validation(array $overrides): void
    {
        [$lab, $actor, $order] = $this->context();
        try {
            DB::transaction(function () use ($lab, $actor, $order, $overrides): void {
                DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->update(['revision' => 3]);
                DB::table('laboratory_order_cash_movements')->insert(array_replace([
                    'laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'operation_id' => (string) Str::ulid(),
                    'financial_revision' => 3, 'type' => 'refund', 'amount' => '10.00', 'currency' => $order->currency,
                    'method' => 'cash', 'reason' => 'FICTICIO', 'created_by' => $actor->id, 'recorded_at' => now()->toISOString(),
                ], $overrides));
                $this->fail('The insert itself must reject invalid data.');
            });
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->errorInfo[0]);
        }
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 2]);
    }

    public static function invalidSql(): array
    {
        return [[['amount' => '20.01']], [['amount' => '0.00']], [['reason' => null]], [['reason' => ' ']],
            [['reason' => ' FICTICIO ']], [['method' => 'card', 'reference' => null]], [['currency' => 'ZZZ']],
            [['financial_revision' => 2]], [['target_payment_id' => 1]], [['type' => 'payment_void']]];
    }

    #[DataProvider('immutableRefundHistory')]
    public function test_refund_history_and_audit_cannot_be_changed_or_deleted(string $table, bool $delete): void
    {
        $this->refund($this->context());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($table, $delete): void {
            $query = DB::table($table);
            if ($table === 'audit_logs') {
                $query->where('event', 'cash.refund_recorded');
            }
            if ($delete) {
                $query->delete();
            } else {
                $query->update([$table === 'audit_logs' ? 'event' : 'recorded_at' => $table === 'audit_logs' ? 'altered' : now()->toISOString()]);
            }
        });
    }

    public static function immutableRefundHistory(): array
    {
        $cases = [];
        foreach (['laboratory_order_cash_movements', 'laboratory_order_cash_operations', 'laboratory_order_financial_revisions', 'audit_logs'] as $table) {
            foreach ([true, false] as $delete) {
                $cases[] = [$table, $delete];
            }
        }

        return $cases;
    }

    public function test_deferred_guard_rejects_missing_audit_and_rolls_back_complete_operation(): void
    {
        $context = $this->context();
        DB::unprepared("CREATE FUNCTION omit_refund_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.event := 'ficticio.omitted_refund'; RETURN NEW; END \$\$; CREATE TRIGGER omit_refund_audit BEFORE INSERT ON audit_logs FOR EACH ROW WHEN (NEW.event='cash.refund_recorded') EXECUTE FUNCTION omit_refund_audit();");
        try {
            DB::transaction(function () use ($context): void {
                $this->refund($context);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Deferred audit guard must reject the operation.');
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->errorInfo[0]);
        }
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 1);
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 2);
        DB::unprepared('DROP TRIGGER omit_refund_audit ON audit_logs; DROP FUNCTION omit_refund_audit();');
        $this->refund($context);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_migration_preserves_payments_and_can_be_reversed_before_refunds(): void
    {
        $context = $this->context();
        $before = DB::table('laboratory_order_cash_operations')->get()->toJson();
        $migration = require database_path('migrations/2026_11_06_000003_enable_order_cash_refunds.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('laboratory_order_cash_operations')->get()->toJson());
        $this->refund($context);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_migration_rollback_refuses_to_remove_refund_support_with_evidence(): void
    {
        $this->refund($this->context());
        $migration = require database_path('migrations/2026_11_06_000003_enable_order_cash_refunds.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se puede retirar el soporte de devoluciones con evidencia existente.');
        $migration->down();
    }
}
