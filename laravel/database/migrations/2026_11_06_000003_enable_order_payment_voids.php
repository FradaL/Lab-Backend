<?php

use Database\MigrationSupport\CashLedgerSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/CashLedgerSchema.php';

return new class extends Migration
{
    public function up(): void
    {
        CashLedgerSchema::apply(true, CashLedgerSchema::supports('record_refund'));
    }

    public function down(): void
    {
        if (DB::table('laboratory_order_cash_movements')->where('type', 'payment_void')->exists()
            || DB::table('laboratory_order_cash_operations')->where('operation_type', 'void_payment')->exists()
            || DB::table('laboratory_order_financial_revisions')->where('cause', 'payment_voided')->exists()) {
            throw new RuntimeException('No se puede retirar el soporte de anulaciones con evidencia existente.');
        }
        CashLedgerSchema::apply(false, CashLedgerSchema::supports('record_refund'));
    }
};
