<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX_NAME = 'subscriptions_one_active_per_laboratory_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(sprintf(
            "CREATE UNIQUE INDEX %s ON subscriptions (laboratory_id) WHERE status = 'active'",
            self::INDEX_NAME,
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s', self::INDEX_NAME));
    }
};
