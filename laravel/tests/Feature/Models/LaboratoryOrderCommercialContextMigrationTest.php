<?php

namespace Tests\Feature\Models;

use App\Models\CommercialClient;
use App\Models\LaboratoryOrder;
use App\Models\PriceList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LaboratoryOrderCommercialContextMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_columns_have_the_required_nullability(): void
    {
        $columns = collect(Schema::getColumns('laboratory_orders'))->keyBy('name');

        $this->assertTrue($columns['commercial_client_name']['nullable']);
        $this->assertTrue($columns['commercial_client_type']['nullable']);
        $this->assertFalse($columns['price_list_name']['nullable']);

        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame('character varying(150)', $columns['commercial_client_name']['type']);
            $this->assertSame('character varying(20)', $columns['commercial_client_type']['type']);
            $this->assertSame('character varying(75)', $columns['price_list_name']['type']);
        }
    }

    public function test_migration_backfills_catalog_values_available_at_migration_time(): void
    {
        $commercial = LaboratoryOrder::factory()->create();
        $particular = LaboratoryOrder::factory()->particular()->create();
        $client = $commercial->commercialClient;
        $commercialPriceList = $commercial->priceList;
        $particularPriceList = $particular->priceList;
        $migration = $this->migration();

        $migration->down();
        $client->update(['name' => 'Cliente al migrar', 'type' => CommercialClient::TYPE_AGREEMENT]);
        $commercialPriceList->update(['name' => 'Lista comercial al migrar']);
        $particularPriceList->update(['name' => 'Lista particular al migrar']);
        $migration->up();

        $commercial->refresh();
        $particular->refresh();
        $this->assertSame('Cliente al migrar', $commercial->commercial_client_name);
        $this->assertSame(CommercialClient::TYPE_AGREEMENT, $commercial->commercial_client_type);
        $this->assertSame('Lista comercial al migrar', $commercial->price_list_name);
        $this->assertNull($particular->commercial_client_name);
        $this->assertNull($particular->commercial_client_type);
        $this->assertSame('Lista particular al migrar', $particular->price_list_name);
    }

    public function test_database_rejects_an_inconsistent_commercial_snapshot(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is the physical authority for the commercial snapshot CHECK.');
        }

        $order = LaboratoryOrder::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('laboratory_orders')->where('id', $order->id)->update([
            'commercial_client_name' => null,
        ]);
    }

    public function test_original_foreign_keys_and_indexes_remain_and_snapshots_have_no_foreign_keys(): void
    {
        $foreignColumns = collect(Schema::getForeignKeys('laboratory_orders'))
            ->pluck('columns')
            ->values();

        foreach ([
            ['laboratory_id'],
            ['laboratory_id', 'branch_id'],
            ['laboratory_id', 'patient_id'],
            ['laboratory_id', 'doctor_id'],
            ['laboratory_id', 'commercial_client_id'],
            ['laboratory_id', 'price_list_id'],
            ['created_by'],
        ] as $columns) {
            $this->assertTrue($foreignColumns->contains($columns));
        }

        foreach ($foreignColumns->flatten() as $column) {
            $this->assertNotContains($column, [
                'commercial_client_name',
                'commercial_client_type',
                'price_list_name',
            ]);
        }

        $indexes = collect(Schema::getIndexes('laboratory_orders'))->keyBy('name');
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['primary'] && $index['columns'] === ['id'],
        ));
        foreach ([
            'laboratory_orders_laboratory_code_unique',
            'laboratory_orders_laboratory_id_id_unique',
            'laboratory_orders_laboratory_status_index',
            'laboratory_orders_laboratory_ordered_at_index',
            'laboratory_orders_laboratory_patient_ordered_at_index',
        ] as $index) {
            $this->assertTrue($indexes->has($index));
        }
    }

    public function test_rollback_only_removes_snapshot_columns_and_forward_migration_restores_them(): void
    {
        $order = LaboratoryOrder::factory()->create();
        $clientCount = CommercialClient::query()->count();
        $priceListCount = PriceList::query()->count();
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('laboratory_orders', 'commercial_client_name'));
        $this->assertFalse(Schema::hasColumn('laboratory_orders', 'commercial_client_type'));
        $this->assertFalse(Schema::hasColumn('laboratory_orders', 'price_list_name'));
        $this->assertDatabaseHas('laboratory_orders', ['id' => $order->id]);
        $this->assertSame($clientCount, CommercialClient::query()->count());
        $this->assertSame($priceListCount, PriceList::query()->count());

        $migration->up();
        $this->assertSame($order->commercialClient->name, $order->fresh()->commercial_client_name);
        $this->assertSame($order->priceList->name, $order->fresh()->price_list_name);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_10_05_034602_add_commercial_context_snapshots_to_laboratory_orders_table.php',
        );
    }
}
