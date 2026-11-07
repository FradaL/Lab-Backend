<?php

namespace Tests\Feature\OrderDeliveryPolicy;

use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderDeliveryPolicyIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    private function migration(): object
    {
        return new class
        {
            public function up(): void
            {
                (require database_path('migrations/2026_11_06_000001_preserve_order_delivery_policy.php'))->up();
                (require database_path('migrations/2026_11_06_000002_create_order_cash_ledger.php'))->up();
                (require database_path('migrations/2026_11_06_000003_enable_order_cash_refunds.php'))->up();
                (require database_path('migrations/2026_11_06_000003_enable_order_payment_voids.php'))->up();
            }

            public function down(): void
            {
                (require database_path('migrations/2026_11_06_000003_enable_order_payment_voids.php'))->down();
                (require database_path('migrations/2026_11_06_000003_enable_order_cash_refunds.php'))->down();
                (require database_path('migrations/2026_11_06_000002_create_order_cash_ledger.php'))->down();
                (require database_path('migrations/2026_11_06_000001_preserve_order_delivery_policy.php'))->down();
            }
        };
    }

    public function test_migration_preserves_old_order_with_null_and_does_not_assign_current_policy(): void
    {
        $migration = $this->migration();
        $migration->down();
        $order = LaboratoryOrder::factory()->create();
        $before = $order->fresh()->getAttributes();

        $migration->up();

        $this->assertNull($order->fresh()->delivery_policy_snapshot);
        $this->assertSame($before, array_diff_key($order->fresh()->getAttributes(), ['delivery_policy_snapshot' => true]));
        $order->update(['notes' => 'FICTICIO — edición permitida de orden histórica']);
        $this->assertNull($order->fresh()->delivery_policy_snapshot);
        $this->assertDatabaseCount('report_delivery_policies', 0);
        $lab = $order->laboratory;
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, 'orders.view');
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->getJson('/api/v1/laboratory-orders/'.$order->id)->assertOk()->assertJsonPath('data.delivery_policy', null);

    }

    #[DataProvider('mutations')]
    public function test_sql_rejects_replacement_null_and_filling_a_historical_policy(string $mutation): void
    {
        if ($mutation === 'historical') {
            $migration = $this->migration();
            $migration->down();
            $order = LaboratoryOrder::factory()->create();
            $migration->up();
            $value = '{}';
        } else {
            $order = LaboratoryOrder::factory()->create();
            $value = $mutation === 'null' ? null : json_encode(array_replace($order->delivery_policy_snapshot, ['mode' => 'unrestricted']));
        }

        $this->expectException(QueryException::class);
        DB::table('laboratory_orders')->where('id', $order->id)->update(['delivery_policy_snapshot' => $value]);
    }

    public static function mutations(): array
    {
        return [['replacement'], ['null'], ['historical']];
    }

    #[DataProvider('invalidSnapshots')]
    public function test_sql_rejects_new_order_without_valid_current_provenance(string $case): void
    {
        $order = LaboratoryOrder::factory()->create();
        $attributes = $order->getAttributes();
        unset($attributes['id']);
        $attributes['code'] = 'ORD-'.Str::ulid();
        $snapshot = $order->delivery_policy_snapshot;
        $attributes['delivery_policy_snapshot'] = match ($case) {
            'null' => null,
            'extra' => json_encode($snapshot + ['unexpected' => true]),
            'revision' => json_encode(array_replace($snapshot, ['source_revision' => 999])),
            'actor' => json_encode(array_replace($snapshot, ['configured_by' => 999999])),
            'time' => json_encode(array_replace($snapshot, ['configured_at' => '2020-01-01T00:00:00.000000Z'])),
            'source' => json_encode(array_replace($snapshot, ['source' => 'conservative_default'])),
            'foreign' => $this->foreignSnapshot(),
        };

        $this->expectException(QueryException::class);
        DB::table('laboratory_orders')->insert($attributes);
    }

    private function foreignSnapshot(): string
    {
        return json_encode(LaboratoryOrder::factory()->create()->delivery_policy_snapshot);
    }

    public static function invalidSnapshots(): array
    {
        return [['null'], ['extra'], ['revision'], ['actor'], ['time'], ['source'], ['foreign']];
    }

    public function test_down_refuses_to_delete_policy_evidence(): void
    {
        LaboratoryOrder::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->migration()->down();
    }
}
