<?php

namespace Tests\Feature\Models;

use App\Models\LaboratoryOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class LaboratoryOrderStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_check_accepts_exactly_the_four_workflow_statuses(): void
    {
        $definition = $this->statusCheckDefinition();

        foreach (LaboratoryOrder::STATUSES as $status) {
            $this->assertStringContainsString($status, $definition);
        }
        foreach (['draft', 'paid', 'processing', 'done'] as $status) {
            $this->assertStringNotContainsString("'{$status}'", $definition);
        }
    }

    public function test_migration_preserves_existing_pending_orders_when_applied(): void
    {
        $order = LaboratoryOrder::factory()->create(['status' => LaboratoryOrder::STATUS_PENDING]);
        $migration = $this->migration();

        $migration->down();
        $this->assertStringNotContainsString('in_process', $this->statusCheckDefinition());
        $migration->up();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertStringContainsString('in_process', $this->statusCheckDefinition());
    }

    public function test_clean_rollback_restores_pending_only_check_without_data_loss(): void
    {
        $order = LaboratoryOrder::factory()->create(['status' => LaboratoryOrder::STATUS_PENDING]);
        $migration = $this->migration();

        try {
            $migration->down();

            $definition = $this->statusCheckDefinition();
            $this->assertStringContainsString('pending', $definition);
            $this->assertStringNotContainsString('in_process', $definition);
            $this->assertSame('pending', $order->fresh()->status);
        } finally {
            $migration->up();
        }
    }

    public function test_incompatible_rollback_fails_before_schema_or_data_changes(): void
    {
        $order = LaboratoryOrder::factory()->create(['status' => LaboratoryOrder::STATUS_COMPLETED]);
        $before = $this->statusCheckDefinition();
        $migration = $this->migration();

        try {
            $migration->down();
            $this->fail('The rollback should reject non-pending data.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cannot roll back the laboratory order status workflow while non-pending orders exist.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($before, $this->statusCheckDefinition());
    }

    public function test_model_encodes_the_complete_transition_matrix(): void
    {
        foreach ([
            'pending' => ['pending' => true, 'in_process' => true, 'completed' => false, 'cancelled' => true],
            'in_process' => ['pending' => false, 'in_process' => true, 'completed' => true, 'cancelled' => true],
            'completed' => ['pending' => false, 'in_process' => false, 'completed' => true, 'cancelled' => false],
            'cancelled' => ['pending' => false, 'in_process' => false, 'completed' => false, 'cancelled' => true],
        ] as $current => $targets) {
            $order = new LaboratoryOrder(['status' => $current]);

            foreach ($targets as $target => $allowed) {
                $this->assertSame($allowed, $order->canTransitionTo($target), "{$current} -> {$target}");
            }
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_04_120000_expand_laboratory_order_status_check.php');
    }

    private function statusCheckDefinition(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne(<<<'SQL'
                SELECT pg_get_constraintdef(oid) AS definition
                FROM pg_constraint
                WHERE conname = 'laboratory_orders_status_check'
                SQL);

            return strtolower((string) $row->definition);
        }

        $definitions = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', ['laboratory_orders_values_insert', 'laboratory_orders_values_update'])
            ->orderBy('name')
            ->pluck('sql')
            ->implode(' ');

        return strtolower($definitions);
    }
}
