<?php

use Database\MigrationSupport\CashLedgerSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/CashLedgerSchema.php';

return new class extends Migration
{
    public function up(): void
    {
        CashLedgerSchema::apply(CashLedgerSchema::supports('void_payment'), true);
    }

    public function down(): void
    {
        if (DB::table('laboratory_order_cash_movements')->where('type', 'refund')->exists()
            || DB::table('laboratory_order_cash_operations')->where('operation_type', 'record_refund')->exists()
            || DB::table('laboratory_order_financial_revisions')->where('cause', 'refund_recorded')->exists()) {
            throw new RuntimeException('No se puede retirar el soporte de devoluciones con evidencia existente.');
        }
        CashLedgerSchema::apply(CashLedgerSchema::supports('void_payment'), false);
    }
};
