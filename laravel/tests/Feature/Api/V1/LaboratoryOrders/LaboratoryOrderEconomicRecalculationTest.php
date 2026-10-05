<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Actions\LaboratoryOrders\RemoveExamFromLaboratoryOrder;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;
use Tests\TestCase;

final class LaboratoryOrderEconomicRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_uses_every_independent_price_snapshot_including_changed_and_zero_prices(): void
    {
        [$laboratory, $order, $exam, $price] = $this->context(price: '35.00');

        app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
        $price->update(['price' => '40.00']);
        app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
        $price->update(['price' => '0.00']);
        app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);

        $this->assertSame(
            ['35.00', '40.00', '0.00'],
            $order->orderExams()->orderBy('id')->pluck('unit_price')->all(),
        );
        $this->assertEconomics($order, '75.00', '0.00', '0.00', '75.00');
    }

    public function test_percentage_discount_recalculates_after_add_and_delete(): void
    {
        [$laboratory, $order, $exam, $price] = $this->context([
            'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => '10.00',
        ], '100.00');

        $first = app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
        $this->assertEconomics($order, '100.00', '10.00', '0.00', '90.00');

        $price->update(['price' => '50.00']);
        app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
        $this->assertEconomics($order, '150.00', '15.00', '0.00', '135.00');

        app(RemoveExamFromLaboratoryOrder::class)->execute($laboratory, $order->id, $first->id);
        $this->assertEconomics($order, '50.00', '5.00', '0.00', '45.00');
        $this->assertSame(LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, $order->discount_type);
        $this->assertSame('10.00', $order->discount_value);
    }

    public function test_amount_discount_clamps_recovers_and_preserves_intent_through_last_delete(): void
    {
        [$laboratory, $order, $exam, $price] = $this->context([
            'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT,
            'discount_value' => '100.00',
        ], '70.00');

        $first = app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
        $this->assertEconomics($order, '70.00', '70.00', '0.00', '0.00');

        $price->update(['price' => '80.00']);
        $second = app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
        $this->assertEconomics($order, '150.00', '100.00', '0.00', '50.00');

        app(RemoveExamFromLaboratoryOrder::class)->execute($laboratory, $order->id, $second->id);
        $this->assertEconomics($order, '70.00', '70.00', '0.00', '0.00');
        app(RemoveExamFromLaboratoryOrder::class)->execute($laboratory, $order->id, $first->id);
        $this->assertEconomics($order, '0.00', '0.00', '0.00', '0.00');
        $this->assertSame(LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, $order->discount_type);
        $this->assertSame('100.00', $order->discount_value);
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->status);
    }

    public function test_delete_recalculates_only_remaining_lines(): void
    {
        [$laboratory, $order, $exam] = $this->context();
        $lines = collect(['35.00', '75.00', '30.00'])->map(
            fn (string $price): LaboratoryOrderExam => $this->line($laboratory, $order, $exam, $price),
        );

        app(RemoveExamFromLaboratoryOrder::class)->execute($laboratory, $order->id, $lines[1]->id);

        $this->assertSame(['35.00', '30.00'], $order->orderExams()->orderBy('id')->pluck('unit_price')->all());
        $this->assertEconomics($order, '65.00', '0.00', '0.00', '65.00');
    }

    public function test_explicit_recalculation_clears_legacy_discount_and_historical_taxes(): void
    {
        [$laboratory, $order, $exam] = $this->context([
            'subtotal' => '100.00',
            'discount_type' => null,
            'discount_value' => null,
            'discount' => '15.00',
            'taxes' => '12.00',
            'total' => '97.00',
        ], '35.00');

        app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);

        $this->assertEconomics($order, '35.00', '0.00', '0.00', '35.00');
        $this->assertNull($order->discount_type);
        $this->assertNull($order->discount_value);
    }

    public function test_failed_economic_update_rolls_back_add_and_delete_with_previous_economics(): void
    {
        [$laboratory, $order, $exam] = $this->context(price: '35.00');
        $existing = $this->line($laboratory, $order, $exam, '20.00');
        $order->update(['subtotal' => '20.00', 'total' => '20.00']);
        $before = $order->fresh()->only(['subtotal', 'discount', 'taxes', 'total']);
        Event::listen('eloquent.updating: '.LaboratoryOrder::class, fn (): never => throw new RuntimeException('forced economic update failure'));

        foreach (['add', 'delete'] as $operation) {
            try {
                $operation === 'add'
                    ? app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id)
                    : app(RemoveExamFromLaboratoryOrder::class)->execute($laboratory, $order->id, $existing->id);
                $this->fail("The forced {$operation} failure was not raised.");
            } catch (RuntimeException $exception) {
                $this->assertSame('forced economic update failure', $exception->getMessage());
            }

            $this->assertDatabaseCount('laboratory_order_exams', 1);
            $this->assertDatabaseHas('laboratory_order_exams', ['id' => $existing->id]);
            $this->assertSame($before, $order->fresh()->only(array_keys($before)));
        }
    }

    public function test_overflow_rolls_back_new_line_and_preserves_previous_economics(): void
    {
        [$laboratory, $order, $exam] = $this->context([
            'subtotal' => '9999999999.99',
            'total' => '9999999999.99',
        ], '0.01');
        $existing = $this->line($laboratory, $order, $exam, '9999999999.99');

        try {
            app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
            $this->fail('The subtotal overflow was not raised.');
        } catch (OverflowException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('laboratory_order_exams', 1);
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $existing->id]);
        $this->assertEconomics($order, '9999999999.99', '0.00', '0.00', '9999999999.99');
    }

    public function test_invalid_in_memory_representable_intent_rolls_back_on_sqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('PostgreSQL prevents invalid intent before the calculator can receive it.');
        }

        [$laboratory, $order, $exam] = $this->context(price: '35.00');
        DB::table('laboratory_orders')->where('id', $order->id)->update([
            'discount_type' => 'foo',
            'discount_value' => '10.00',
        ]);

        try {
            app(AddExamToLaboratoryOrder::class)->execute($laboratory, $order->id, $exam->id);
            $this->fail('The invalid discount intent was not rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertDatabaseHas('laboratory_orders', [
            'id' => $order->id,
            'discount_type' => 'foo',
            'discount_value' => '10.00',
            'subtotal' => '0.00',
            'discount' => '0.00',
            'taxes' => '0.00',
            'total' => '0.00',
        ]);
    }

    public function test_recalculation_uses_one_tenant_scoped_line_query_and_one_economic_update_for_twenty_lines(): void
    {
        [$laboratory, $order, $exam] = $this->context();
        foreach (range(1, 20) as $unused) {
            $this->line($laboratory, $order, $exam, '35.00');
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        DB::transaction(function () use ($laboratory, $order): void {
            $lockedOrder = LaboratoryOrder::forLaboratory($laboratory)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            app(RecalculateLaboratoryOrderEconomics::class)->execute($laboratory, $lockedOrder);
        });

        $lineQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "laboratory_order_exams"'));
        $this->assertCount(1, $lineQueries);
        $this->assertStringContainsString('"laboratory_id"', $lineQueries->sole());
        $this->assertStringContainsString('"laboratory_order_id"', $lineQueries->sole());
        $this->assertCount(1, collect($queries)->filter(fn (string $sql): bool => str_starts_with($sql, 'update "laboratory_orders"')));
        foreach (['laboratory_exams', 'price_lists', 'price_list_exams', 'commercial_client_price_lists'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')));
        }
        $this->assertEconomics($order, '700.00', '0.00', '0.00', '700.00');
    }

    /** @return array{Laboratory, LaboratoryOrder, LaboratoryExam, PriceListExam} */
    private function context(array $orderAttributes = [], string $price = '35.00'): array
    {
        $laboratory = Laboratory::factory()->create();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $order = LaboratoryOrder::factory()->for($laboratory)->create(array_merge([
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'status' => LaboratoryOrder::STATUS_PENDING,
        ], $orderAttributes));
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
        $priceListExam = PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => $price,
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);

        return [$laboratory, $order, $exam, $priceListExam];
    }

    private function line(
        Laboratory $laboratory,
        LaboratoryOrder $order,
        LaboratoryExam $exam,
        string $price,
    ): LaboratoryOrderExam {
        return LaboratoryOrderExam::factory()->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $order->price_list_id,
            'unit_price' => $price,
        ]);
    }

    private function assertEconomics(
        LaboratoryOrder $order,
        string $subtotal,
        string $discount,
        string $taxes,
        string $total,
    ): void {
        $order->refresh();
        $this->assertSame($subtotal, $order->subtotal);
        $this->assertSame($discount, $order->discount);
        $this->assertSame($taxes, $order->taxes);
        $this->assertSame($total, $order->total);
    }
}
