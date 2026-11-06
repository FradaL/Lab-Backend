<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\RecordOrderPayment;
use App\ClinicalExams\ClinicalConflict;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class CashLedgerMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    public function test_activation_preserves_existing_snapshot_order_without_inventing_financial_context(): void
    {
        $migration = require database_path('migrations/2026_11_06_000002_create_order_cash_ledger.php');
        $migration->down();
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $before = $order->fresh()->getAttributes();
        $migration->up();
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('laboratory_order_financial_states', 0);
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 0);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        setPermissionsTeamId($lab->id);
        try {
            app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
                ['expected_revision' => 1, 'amount' => '10.00', 'method' => 'cash'], 'ficticio-preactivation-01');
            $this->fail('An unavailable context cannot be initialized by payment.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('cash_evidence_unavailable', $error->errorCode);
            $this->assertSame(503, $error->status);
        }
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 0);
    }

    public function test_rollback_refuses_to_remove_initialized_financial_evidence(): void
    {
        LaboratoryOrder::factory()->create();
        $migration = require database_path('migrations/2026_11_06_000002_create_order_cash_ledger.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se puede eliminar evidencia financiera existente.');
        $migration->down();
    }
}
