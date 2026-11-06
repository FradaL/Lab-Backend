<?php

namespace Tests\Support;

use App\Models\LaboratoryOrder;
use Closure;
use Illuminate\Support\Facades\DB;

/** Emula exclusivamente filas sintéticas anteriores a la migración BE-D02-03. */
final class HistoricalOrderFixture
{
    public static function create(Closure $create): LaboratoryOrder
    {
        TestDatabaseGuard::verify(app());

        return DB::transaction(function () use ($create): LaboratoryOrder {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            DB::statement('LOCK TABLE laboratory_orders IN ACCESS EXCLUSIVE MODE');
            DB::statement('ALTER TABLE laboratory_orders DISABLE TRIGGER preserve_order_delivery_policy');
            DB::statement('ALTER TABLE laboratory_orders DISABLE TRIGGER cash_order_initialized');
            try {
                return $create();
            } finally {
                DB::statement('ALTER TABLE laboratory_orders ENABLE TRIGGER preserve_order_delivery_policy');
                DB::statement('ALTER TABLE laboratory_orders ENABLE TRIGGER cash_order_initialized');
            }
        });
    }
}
