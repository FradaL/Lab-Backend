<?php

namespace Tests\Feature\Models;

use App\Models\LaboratoryOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LaboratoryOrderDiscountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_intent_columns_have_the_required_physical_definition(): void
    {
        $columns = collect(Schema::getColumns('laboratory_orders'))->keyBy('name');

        $this->assertTrue($columns->has('discount_type'));
        $this->assertTrue($columns->has('discount_value'));
        $this->assertTrue($columns['discount_type']['nullable']);
        $this->assertTrue($columns['discount_value']['nullable']);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $physical = collect(DB::select(<<<'SQL'
            SELECT column_name, data_type, character_maximum_length,
                   numeric_precision, numeric_scale, is_nullable
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'laboratory_orders'
              AND column_name IN ('discount_type', 'discount_value')
            SQL))->keyBy('column_name');

        $this->assertSame('character varying', $physical['discount_type']->data_type);
        $this->assertSame(10, $physical['discount_type']->character_maximum_length);
        $this->assertSame('YES', $physical['discount_type']->is_nullable);
        $this->assertSame('numeric', $physical['discount_value']->data_type);
        $this->assertSame(12, $physical['discount_value']->numeric_precision);
        $this->assertSame(2, $physical['discount_value']->numeric_scale);
        $this->assertSame('YES', $physical['discount_value']->is_nullable);
    }

    #[DataProvider('validDiscountIntentProvider')]
    public function test_database_accepts_valid_discount_intent_pairs(?string $type, ?string $value): void
    {
        $order = LaboratoryOrder::factory()->create([
            'discount_type' => $type,
            'discount_value' => $value,
        ])->fresh();

        $this->assertSame($type, $order->discount_type);
        $this->assertSame($value, $order->discount_value);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function validDiscountIntentProvider(): array
    {
        return [
            'none' => [null, null],
            'percentage ten' => [LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, '10.00'],
            'percentage one hundred' => [LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, '100.00'],
            'amount' => [LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, '25.00'],
        ];
    }

    #[DataProvider('invalidDiscountIntentProvider')]
    public function test_postgresql_rejects_invalid_discount_intent_pairs(?string $type, ?string $value): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is the physical authority for the discount intent CHECK.');
        }

        $order = LaboratoryOrder::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('laboratory_orders')->where('id', $order->id)->update([
            'discount_type' => $type,
            'discount_value' => $value,
        ]);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function invalidDiscountIntentProvider(): array
    {
        return [
            'unknown foo' => ['foo', '10.00'],
            'unknown fixed' => ['fixed', '10.00'],
            'unknown percent' => ['percent', '10.00'],
            'null type with value' => [null, '10.00'],
            'percentage without value' => [LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, null],
            'amount without value' => [LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, null],
            'percentage zero' => [LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, '0.00'],
            'percentage negative' => [LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, '-1.00'],
            'percentage above one hundred' => [LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, '100.01'],
            'amount zero' => [LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, '0.00'],
            'amount negative' => [LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, '-1.00'],
        ];
    }

    public function test_migration_preserves_legacy_discount_without_inferring_intent(): void
    {
        $order = LaboratoryOrder::factory()->create([
            'subtotal' => '100.00',
            'discount_type' => null,
            'discount_value' => null,
            'discount' => '15.00',
            'taxes' => '0.00',
            'total' => '85.00',
        ]);
        $migration = $this->migration();

        $migration->down();
        $this->assertDatabaseHas('laboratory_orders', [
            'id' => $order->id,
            'subtotal' => '100.00',
            'discount' => '15.00',
            'taxes' => '0.00',
            'total' => '85.00',
        ]);

        $migration->up();
        $order->refresh();
        $this->assertNull($order->discount_type);
        $this->assertNull($order->discount_value);
        $this->assertSame('100.00', $order->subtotal);
        $this->assertSame('15.00', $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame('85.00', $order->total);
    }

    public function test_rollback_removes_only_discount_intent_and_forward_migration_restores_null_columns(): void
    {
        $order = LaboratoryOrder::factory()->create();
        $snapshot = $order->only([
            'subtotal', 'discount', 'taxes', 'total', 'currency',
            'commercial_client_name', 'commercial_client_type', 'price_list_name',
        ]);
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('laboratory_orders', 'discount_type'));
        $this->assertFalse(Schema::hasColumn('laboratory_orders', 'discount_value'));
        $this->assertSame($snapshot, $order->fresh()->only(array_keys($snapshot)));

        $migration->up();
        $order->refresh();
        $this->assertNull($order->discount_type);
        $this->assertNull($order->discount_value);
        $this->assertSame($snapshot, $order->only(array_keys($snapshot)));
    }

    public function test_preexisting_postgresql_constraints_remain_with_discount_intent_check(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint catalog audit.');
        }

        $constraints = collect(DB::select(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'laboratory_orders'::regclass
            SQL))->pluck('conname');

        foreach ([
            'laboratory_orders_pkey',
            'laboratory_orders_laboratory_code_unique',
            'laboratory_orders_status_check',
            'laboratory_orders_subtotal_nonnegative_check',
            'laboratory_orders_discount_nonnegative_check',
            'laboratory_orders_taxes_nonnegative_check',
            'laboratory_orders_total_nonnegative_check',
            'laboratory_orders_currency_format_check',
            'laboratory_orders_commercial_snapshot_consistency_check',
            'laboratory_orders_discount_intent_check',
        ] as $constraint) {
            $this->assertContains($constraint, $constraints);
        }

        $definition = DB::selectOne(<<<'SQL'
            SELECT pg_get_constraintdef(oid) AS definition
            FROM pg_constraint
            WHERE conname = 'laboratory_orders_discount_intent_check'
            SQL)->definition;
        $this->assertStringContainsString('percentage', $definition);
        $this->assertStringContainsString('amount', $definition);
    }

    public function test_sqlite_value_triggers_survive_rollback_and_forward_migration(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger audit.');
        }

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $triggers = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', ['laboratory_orders_values_insert', 'laboratory_orders_values_update'])
            ->pluck('name');
        $this->assertEqualsCanonicalizing([
            'laboratory_orders_values_insert',
            'laboratory_orders_values_update',
        ], $triggers->all());

        foreach ([
            ['status' => 'invalid'],
            ['subtotal' => '-0.01'],
            ['discount' => '-0.01'],
            ['taxes' => '-0.01'],
            ['total' => '-0.01'],
            ['currency' => 'gtq'],
        ] as $invalid) {
            try {
                LaboratoryOrder::factory()->create($invalid);
                $this->fail('The restored SQLite value triggers should reject invalid order data.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sqlite_does_not_add_a_trigger_to_emulate_the_postgresql_discount_check(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific documented enforcement difference.');
        }

        $discountTriggers = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('tbl_name', 'laboratory_orders')
            ->where('sql', 'like', '%discount_type%')
            ->count();
        $order = LaboratoryOrder::factory()->create();

        $this->assertSame(0, $discountTriggers);
        DB::table('laboratory_orders')->where('id', $order->id)->update([
            'discount_type' => 'foo',
            'discount_value' => '10.00',
        ]);
        $this->assertDatabaseHas('laboratory_orders', [
            'id' => $order->id,
            'discount_type' => 'foo',
        ]);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_10_05_060000_add_discount_intent_to_laboratory_orders_table.php',
        );
    }
}
