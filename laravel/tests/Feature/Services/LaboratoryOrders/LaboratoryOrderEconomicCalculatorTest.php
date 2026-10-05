<?php

namespace Tests\Feature\Services\LaboratoryOrders;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Services\LaboratoryOrders\LaboratoryOrderEconomicCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LaboratoryOrderEconomicCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculation_performs_no_queries_and_preserves_legacy_persistence(): void
    {
        $order = LaboratoryOrder::factory()->create([
            'subtotal' => '100.00',
            'discount_type' => null,
            'discount_value' => null,
            'discount' => '15.00',
            'taxes' => '0.00',
            'total' => '85.00',
        ]);
        $line = LaboratoryOrderExam::factory()->create([
            'laboratory_id' => $order->laboratory_id,
            'laboratory_order_id' => $order->id,
            'price_list_id' => $order->price_list_id,
            'unit_price' => '100.00',
        ]);
        $orderBefore = $order->getAttributes();
        $lineBefore = $line->getAttributes();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = (new LaboratoryOrderEconomicCalculator)->calculate($order, [$line]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
        $this->assertSame(['100.00', '0.00', '0.00', '100.00'], [
            $result->subtotal,
            $result->discount,
            $result->taxes,
            $result->total,
        ]);
        $this->assertSame($orderBefore, $order->getAttributes());
        $this->assertSame($lineBefore, $line->getAttributes());
        $this->assertDatabaseHas('laboratory_orders', [
            'id' => $order->id,
            'subtotal' => '100.00',
            'discount' => '15.00',
            'taxes' => '0.00',
            'total' => '85.00',
        ]);
    }
}
