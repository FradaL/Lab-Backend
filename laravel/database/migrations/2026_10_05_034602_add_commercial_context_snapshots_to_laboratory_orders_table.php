<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->string('commercial_client_name', 150)->nullable()->after('commercial_client_id');
            $table->string('commercial_client_type', 20)->nullable()->after('commercial_client_name');
            $table->string('price_list_name', 75)->nullable()->after('price_list_id');
        });

        // Pre-BE-11 rows can only capture catalog values as they exist when
        // this migration runs; earlier historical names were never stored.
        DB::statement(<<<'SQL'
            UPDATE laboratory_orders
            SET commercial_client_name = (
                    SELECT commercial_clients.name
                    FROM commercial_clients
                    WHERE commercial_clients.id = laboratory_orders.commercial_client_id
                      AND commercial_clients.laboratory_id = laboratory_orders.laboratory_id
                ),
                commercial_client_type = (
                    SELECT commercial_clients.type
                    FROM commercial_clients
                    WHERE commercial_clients.id = laboratory_orders.commercial_client_id
                      AND commercial_clients.laboratory_id = laboratory_orders.laboratory_id
                )
            WHERE commercial_client_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE laboratory_orders
            SET price_list_name = (
                SELECT price_lists.name
                FROM price_lists
                WHERE price_lists.id = laboratory_orders.price_list_id
                  AND price_lists.laboratory_id = laboratory_orders.laboratory_id
            )
            SQL);

        if (DB::table('laboratory_orders')->whereNull('price_list_name')->exists()) {
            throw new RuntimeException('Cannot backfill the price list snapshot for every laboratory order.');
        }

        if (DB::table('laboratory_orders')
            ->whereNotNull('commercial_client_id')
            ->where(function ($query): void {
                $query->whereNull('commercial_client_name')
                    ->orWhereNull('commercial_client_type');
            })
            ->exists()) {
            throw new RuntimeException('Cannot backfill the commercial client snapshot for every commercial laboratory order.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_orders
                ALTER COLUMN price_list_name SET NOT NULL,
                ADD CONSTRAINT laboratory_orders_commercial_snapshot_consistency_check
                CHECK (
                    (commercial_client_id IS NULL
                        AND commercial_client_name IS NULL
                        AND commercial_client_type IS NULL)
                    OR
                    (commercial_client_id IS NOT NULL
                        AND commercial_client_name IS NOT NULL
                        AND commercial_client_type IS NOT NULL)
                )
                SQL);
        } else {
            Schema::table('laboratory_orders', function (Blueprint $table) {
                $table->string('price_list_name', 75)->nullable(false)->change();
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteValueTriggers();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_orders
                DROP CONSTRAINT IF EXISTS laboratory_orders_commercial_snapshot_consistency_check
                SQL);
        }

        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropColumn([
                'commercial_client_name',
                'commercial_client_type',
                'price_list_name',
            ]);
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteValueTriggers();
        }
    }

    private function createSqliteValueTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS laboratory_orders_values_insert');
        DB::statement('DROP TRIGGER IF EXISTS laboratory_orders_values_update');

        DB::statement(<<<'SQL'
            CREATE TRIGGER laboratory_orders_values_insert
            BEFORE INSERT ON laboratory_orders
            WHEN NEW.status NOT IN ('pending', 'in_process', 'completed', 'cancelled')
                OR NEW.subtotal < 0
                OR NEW.discount < 0
                OR NEW.taxes < 0
                OR NEW.total < 0
                OR length(NEW.currency) != 3
                OR NEW.currency GLOB '*[^A-Z]*'
            BEGIN
                SELECT RAISE(ABORT, 'laboratory order values are invalid');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER laboratory_orders_values_update
            BEFORE UPDATE OF status, subtotal, discount, taxes, total, currency ON laboratory_orders
            WHEN NEW.status NOT IN ('pending', 'in_process', 'completed', 'cancelled')
                OR NEW.subtotal < 0
                OR NEW.discount < 0
                OR NEW.taxes < 0
                OR NEW.total < 0
                OR length(NEW.currency) != 3
                OR NEW.currency GLOB '*[^A-Z]*'
            BEGIN
                SELECT RAISE(ABORT, 'laboratory order values are invalid');
            END
            SQL);
    }
};
