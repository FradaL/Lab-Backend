<?php

use Database\MigrationSupport\CashLedgerSchema;
use Illuminate\Database\Migrations\Migration;

require_once __DIR__.'/support/CashLedgerSchema.php';

return new class extends Migration
{
    public function up(): void
    {
        // Also repairs databases where the original migrations have already run.
        CashLedgerSchema::apply(true, true);
    }

    public function down(): void
    {
        // Preceding migrations retain both capabilities; their down() protects evidence.
    }
};
