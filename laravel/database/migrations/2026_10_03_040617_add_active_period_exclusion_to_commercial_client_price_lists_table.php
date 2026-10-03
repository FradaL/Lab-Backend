<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE commercial_client_price_lists
            ADD CONSTRAINT ccpl_no_active_period_overlap
            EXCLUDE USING gist (
                laboratory_id WITH =,
                commercial_client_id WITH =,
                daterange(starts_at, ends_at, '[]') WITH &&
            )
            WHERE (status = 'active')
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE commercial_client_price_lists
            DROP CONSTRAINT IF EXISTS ccpl_no_active_period_overlap
            SQL);
    }
};
