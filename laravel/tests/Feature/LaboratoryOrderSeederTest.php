<?php

namespace Tests\Feature;

use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceListExam;
use App\Services\LaboratoryOrders\LaboratoryOrderEconomicCalculator;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaboratoryOrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LaboratoryOrderSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_complete_and_consistent_demo_order_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();
        $orders = LaboratoryOrder::query()
            ->where('laboratory_id', $laboratory->id)
            ->where('code', 'like', LaboratoryOrderSeeder::DEMO_CODE_PREFIX.'%')
            ->with(['commercialClient', 'orderExams.exam', 'priceList'])
            ->get();
        $orderIds = $orders->modelKeys();

        $this->assertCount(LaboratoryOrderSeeder::DEMO_ORDER_COUNT, $orders);
        $this->assertSame(LaboratoryOrderSeeder::DEMO_ORDER_COUNT, $orders->pluck('code')->unique()->count());
        $this->assertSame(0, LaboratoryOrder::query()
            ->where('laboratory_id', '!=', $laboratory->id)
            ->where('code', 'like', LaboratoryOrderSeeder::DEMO_CODE_PREFIX.'%')
            ->count());

        foreach ($orders as $order) {
            $this->assertMatchesRegularExpression('/^ORD-[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $order->code);
            $this->assertSame($order->priceList->name, $order->price_list_name);
            $this->assertSame($order->priceList->currency, $order->currency);

            if ($order->commercialClient === null) {
                $this->assertNull($order->commercial_client_name);
                $this->assertNull($order->commercial_client_type);
            } else {
                $this->assertSame($order->commercialClient->name, $order->commercial_client_name);
                $this->assertSame($order->commercialClient->type, $order->commercial_client_type);
                $this->assertTrue(CommercialClientPriceList::query()
                    ->where('laboratory_id', $laboratory->id)
                    ->where('commercial_client_id', $order->commercial_client_id)
                    ->where('price_list_id', $order->price_list_id)
                    ->effectiveOn($order->ordered_at->toImmutable())
                    ->exists());
            }

            foreach ($order->orderExams as $line) {
                $configuredPrice = PriceListExam::query()
                    ->where('laboratory_id', $laboratory->id)
                    ->where('price_list_id', $order->price_list_id)
                    ->where('laboratory_exam_id', $line->laboratory_exam_id)
                    ->firstOrFail();

                $this->assertSame($order->price_list_id, $line->price_list_id);
                $this->assertSame($order->price_list_name, $line->price_list_name);
                $this->assertSame($line->exam->code, $line->exam_code);
                $this->assertSame($line->exam->name, $line->exam_name);
                $this->assertSame($configuredPrice->price, $line->unit_price);
            }

            $totals = (new LaboratoryOrderEconomicCalculator)->calculate($order, $order->orderExams);

            $this->assertSame($totals->subtotal, $order->subtotal);
            $this->assertSame($totals->discount, $order->discount);
            $this->assertSame('0.00', $order->taxes);
            $this->assertSame($totals->total, $order->total);
        }

        $statusCounts = $orders->countBy('status')->all();
        ksort($statusCounts);

        $this->assertSame([
            LaboratoryOrder::STATUS_CANCELLED => 3,
            LaboratoryOrder::STATUS_COMPLETED => 6,
            LaboratoryOrder::STATUS_IN_PROCESS => 4,
            LaboratoryOrder::STATUS_PENDING => 5,
        ], $statusCounts);
        $this->assertSame(45, LaboratoryOrderExam::query()
            ->where('laboratory_id', $laboratory->id)
            ->whereIn('laboratory_order_id', $orderIds)
            ->count());
        $this->assertSame(9, $orders->whereNull('commercial_client_id')->count());
        $this->assertSame(9, $orders->whereNotNull('commercial_client_id')->count());
        $this->assertSame(9, $orders->whereNull('doctor_id')->count());
        $this->assertSame(2, $orders->pluck('branch_id')->unique()->count());
        $this->assertSame(3, $orders->pluck('price_list_id')->unique()->count());
        $this->assertSame(7, $orders->whereNull('discount_type')->count());
        $this->assertSame(6, $orders->where('discount_type', LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE)->count());
        $this->assertSame(5, $orders->where('discount_type', LaboratoryOrder::DISCOUNT_TYPE_AMOUNT)->count());
        $this->assertSame(1, DB::table('laboratory_order_exams')
            ->select(['laboratory_order_id', 'laboratory_exam_id'])
            ->whereIn('laboratory_order_id', $orderIds)
            ->groupBy(['laboratory_order_id', 'laboratory_exam_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_a_second_run_is_idempotent_and_preserves_foreign_orders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $demoLaboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();
        $idsBefore = LaboratoryOrder::query()
            ->where('laboratory_id', $demoLaboratory->id)
            ->where('code', 'like', LaboratoryOrderSeeder::DEMO_CODE_PREFIX.'%')
            ->pluck('id', 'code')
            ->all();

        $foreignLaboratory = Laboratory::factory()->create();
        $foreignOrder = LaboratoryOrder::factory()->particular()->create([
            'laboratory_id' => $foreignLaboratory->id,
        ]);
        $foreignLine = LaboratoryOrderExam::factory()->create([
            'laboratory_id' => $foreignLaboratory->id,
            'laboratory_order_id' => $foreignOrder->id,
            'price_list_id' => $foreignOrder->price_list_id,
        ]);

        $this->seed(DatabaseSeeder::class);

        $idsAfter = LaboratoryOrder::query()
            ->where('laboratory_id', $demoLaboratory->id)
            ->where('code', 'like', LaboratoryOrderSeeder::DEMO_CODE_PREFIX.'%')
            ->pluck('id', 'code')
            ->all();

        $this->assertSame($idsBefore, $idsAfter);
        $this->assertCount(LaboratoryOrderSeeder::DEMO_ORDER_COUNT, $idsAfter);
        $this->assertSame(45, LaboratoryOrderExam::query()
            ->where('laboratory_id', $demoLaboratory->id)
            ->whereIn('laboratory_order_id', array_values($idsAfter))
            ->count());
        $this->assertDatabaseHas('laboratory_orders', [
            'id' => $foreignOrder->id,
            'laboratory_id' => $foreignLaboratory->id,
        ]);
        $this->assertDatabaseHas('laboratory_order_exams', [
            'id' => $foreignLine->id,
            'laboratory_order_id' => $foreignOrder->id,
        ]);
    }
}
