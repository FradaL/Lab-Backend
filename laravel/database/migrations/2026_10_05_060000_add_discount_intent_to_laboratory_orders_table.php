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
            $table->string('discount_type', 10)->nullable()->after('discount');
            $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_orders
                ADD CONSTRAINT laboratory_orders_discount_intent_check
                CHECK (
                    (discount_type IS NULL AND discount_value IS NULL)
                    OR
                    (discount_type IS NOT NULL
                        AND discount_value IS NOT NULL
                        AND discount_type = 'percentage'
                        AND discount_value > 0
                        AND discount_value <= 100)
                    OR
                    (discount_type IS NOT NULL
                        AND discount_value IS NOT NULL
                        AND discount_type = 'amount'
                        AND discount_value > 0)
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_orders
                DROP CONSTRAINT IF EXISTS laboratory_orders_discount_intent_check
                SQL);
        }

        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value']);
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
