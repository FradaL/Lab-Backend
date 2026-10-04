<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_orders
                DROP CONSTRAINT laboratory_orders_status_check,
                ADD CONSTRAINT laboratory_orders_status_check
                CHECK (status IN ('pending', 'in_process', 'completed', 'cancelled'))
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->replaceSqliteTriggers(
                "NEW.status NOT IN ('pending', 'in_process', 'completed', 'cancelled')",
            );
        }
    }

    public function down(): void
    {
        if (DB::table('laboratory_orders')->where('status', '!=', 'pending')->exists()) {
            throw new RuntimeException(
                'Cannot roll back the laboratory order status workflow while non-pending orders exist.',
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_orders
                DROP CONSTRAINT laboratory_orders_status_check,
                ADD CONSTRAINT laboratory_orders_status_check
                CHECK (status IN ('pending'))
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->replaceSqliteTriggers("NEW.status != 'pending'");
        }
    }

    private function replaceSqliteTriggers(string $invalidStatusExpression): void
    {
        DB::statement('DROP TRIGGER IF EXISTS laboratory_orders_values_insert');
        DB::statement('DROP TRIGGER IF EXISTS laboratory_orders_values_update');

        DB::statement(<<<SQL
            CREATE TRIGGER laboratory_orders_values_insert
            BEFORE INSERT ON laboratory_orders
            WHEN {$invalidStatusExpression}
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
        DB::statement(<<<SQL
            CREATE TRIGGER laboratory_orders_values_update
            BEFORE UPDATE OF status, subtotal, discount, taxes, total, currency ON laboratory_orders
            WHEN {$invalidStatusExpression}
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
