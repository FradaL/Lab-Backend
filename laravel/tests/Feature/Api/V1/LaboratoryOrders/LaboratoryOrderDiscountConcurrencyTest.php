<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Actions\LaboratoryOrders\RemoveExamFromLaboratoryOrder;
use App\Actions\LaboratoryOrders\RemoveLaboratoryOrderDiscount;
use App\Actions\LaboratoryOrders\SetLaboratoryOrderDiscount;
use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

final class LaboratoryOrderDiscountConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_discount_put_and_add_leave_economics_consistent(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context(addPrice: '100.00');

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'discount' => ['action' => 'set', 'type' => 'percentage', 'value' => '10.00'],
            'add' => ['action' => 'add'],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $this->assertCount(1, DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->get());
        $this->assertConsistentEconomics($order, 'percentage', '10.00', '100.00', '10.00', '90.00');
    }

    public function test_concurrent_discount_delete_and_add_leave_canonical_undiscounted_total(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context(addPrice: '100.00');
        app(SetLaboratoryOrderDiscount::class)->execute($laboratory, $order->id, 'percentage', '10.00');

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'remove' => ['action' => 'remove'],
            'add' => ['action' => 'add'],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $this->assertCount(1, DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->get());
        $this->assertConsistentEconomics($order, null, null, '100.00', '0.00', '100.00');
    }

    public function test_concurrent_discount_delete_and_exam_delete_leave_undiscounted_remaining_lines(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();
        $first = $this->line($laboratory, $order, $exam, '100.00');
        $second = $this->line($laboratory, $order, $exam, '50.00');
        $remaining = $this->line($laboratory, $order, $exam, '25.00');
        app(SetLaboratoryOrderDiscount::class)->execute($laboratory, $order->id, 'percentage', '10.00');

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'remove' => ['action' => 'remove'],
            'delete' => ['action' => 'delete_line', 'line_id' => $first->id],
            'delete-second' => ['action' => 'delete_line', 'line_id' => $second->id],
        ]);

        $this->assertSame(['ok', 'ok', 'ok'], array_values($results));
        $this->assertSame([$remaining->id], DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->pluck('id')->all());
        $this->assertConsistentEconomics($order, null, null, '25.00', '0.00', '25.00');
    }

    public function test_concurrent_adds_with_active_discount_count_both_snapshots(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context(addPrice: '35.00');
        app(SetLaboratoryOrderDiscount::class)->execute($laboratory, $order->id, 'percentage', '10.00');

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'add-one' => ['action' => 'add'],
            'add-two' => ['action' => 'add'],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $this->assertCount(2, DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->get());
        $this->assertConsistentEconomics($order, 'percentage', '10.00', '70.00', '7.00', '63.00');

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
    }

    public function test_concurrent_deletes_with_active_discount_leave_one_correct_snapshot(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();
        $first = $this->line($laboratory, $order, $exam, '100.00');
        $second = $this->line($laboratory, $order, $exam, '50.00');
        $remaining = $this->line($laboratory, $order, $exam, '25.00');
        app(SetLaboratoryOrderDiscount::class)->execute($laboratory, $order->id, 'percentage', '10.00');

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'delete-one' => ['action' => 'delete_line', 'line_id' => $first->id],
            'delete-two' => ['action' => 'delete_line', 'line_id' => $second->id],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $this->assertSame([$remaining->id], DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->pluck('id')->all());
        $this->assertConsistentEconomics($order, 'percentage', '10.00', '25.00', '2.50', '22.50');
    }

    public function test_concurrent_discount_put_and_exam_delete_recalculate_final_lines(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();
        $deleted = $this->line($laboratory, $order, $exam, '100.00');
        $remaining = $this->line($laboratory, $order, $exam, '100.00');
        $order->update(['subtotal' => '200.00', 'total' => '200.00']);

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'discount' => ['action' => 'set', 'type' => 'percentage', 'value' => '10.00'],
            'delete' => ['action' => 'delete_line', 'line_id' => $deleted->id],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $this->assertSame([$remaining->id], DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->pluck('id')->all());
        $this->assertConsistentEconomics($order, 'percentage', '10.00', '100.00', '10.00', '90.00');
    }

    public function test_two_concurrent_discount_puts_do_not_mix_intent_or_economics(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();
        $this->line($laboratory, $order, $exam, '200.00');
        $order->update(['subtotal' => '200.00', 'total' => '200.00']);

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'percentage' => ['action' => 'set', 'type' => 'percentage', 'value' => '10.00'],
            'amount' => ['action' => 'set', 'type' => 'amount', 'value' => '25.00'],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $order->refresh();
        if ($order->discount_type === LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE) {
            $this->assertSame('10.00', $order->discount_value);
            $this->assertConsistentEconomics($order, 'percentage', '10.00', '200.00', '20.00', '180.00');
        } else {
            $this->assertSame(LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, $order->discount_type);
            $this->assertConsistentEconomics($order, 'amount', '25.00', '200.00', '25.00', '175.00');
        }
    }

    public function test_concurrent_discount_put_and_remove_leave_canonical_final_state(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();
        $this->line($laboratory, $order, $exam, '200.00');
        $order->update(['subtotal' => '200.00', 'total' => '200.00']);

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'set' => ['action' => 'set', 'type' => 'percentage', 'value' => '10.00'],
            'remove' => ['action' => 'remove'],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $order->refresh();
        if ($order->discount_type === null) {
            $this->assertNull($order->discount_value);
            $this->assertConsistentEconomics($order, null, null, '200.00', '0.00', '200.00');
        } else {
            $this->assertConsistentEconomics($order, 'percentage', '10.00', '200.00', '20.00', '180.00');
        }
    }

    public function test_discount_and_status_transition_serialize_on_the_order_lock(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();
        $this->line($laboratory, $order, $exam, '200.00');
        $order->update(['subtotal' => '200.00', 'total' => '200.00']);

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'discount' => ['action' => 'set', 'type' => 'percentage', 'value' => '10.00'],
            'transition' => ['action' => 'transition'],
        ]);

        $this->assertSame('ok', $results['transition']);
        $this->assertContains($results['discount'], ['ok', 'validation']);
        $order->refresh();
        $this->assertSame(LaboratoryOrder::STATUS_IN_PROCESS, $order->status);
        if ($results['discount'] === 'ok') {
            $this->assertConsistentEconomics($order, 'percentage', '10.00', '200.00', '20.00', '180.00');
        } else {
            $this->assertConsistentEconomics($order, null, null, '200.00', '0.00', '200.00');
        }

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
        DB::table('laboratory_orders')->where('id', $order->id)->update(['status' => LaboratoryOrder::STATUS_PENDING]);
    }

    /** @return array{Laboratory, LaboratoryOrder, LaboratoryExam} */
    private function context(string $addPrice = '35.00'): array
    {
        $laboratory = Laboratory::factory()->create();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $order = LaboratoryOrder::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'status' => LaboratoryOrder::STATUS_PENDING,
        ]);
        $exam = LaboratoryExam::factory()->for($laboratory)->create(['status' => LaboratoryExam::STATUS_ACTIVE]);
        PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => $addPrice,
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);

        return [$laboratory, $order, $exam];
    }

    private function line(Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam, string $price): LaboratoryOrderExam
    {
        return LaboratoryOrderExam::factory()->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $order->price_list_id,
            'unit_price' => $price,
        ]);
    }

    /**
     * @param  array<string, array{action: string, type?: string, value?: string, line_id?: int}>  $jobs
     * @return array<string, string>
     */
    private function runConcurrently(Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam, array $jobs): array
    {
        $directory = sys_get_temp_dir().'/donqer-order-discount-concurrency-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $gate = $directory.'/go';
        $children = [];
        DB::disconnect();

        try {
            foreach ($jobs as $name => $job) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Unable to fork the PostgreSQL concurrency worker.');

                if ($pid === 0) {
                    $deadline = microtime(true) + 5;
                    while (! file_exists($gate) && microtime(true) < $deadline) {
                        usleep(1000);
                    }

                    DB::reconnect();
                    try {
                        $currentLaboratory = Laboratory::query()->findOrFail($laboratory->id);
                        match ($job['action']) {
                            'set' => app(SetLaboratoryOrderDiscount::class)->execute(
                                $currentLaboratory,
                                $order->id,
                                $job['type'],
                                $job['value'],
                            ),
                            'remove' => app(RemoveLaboratoryOrderDiscount::class)->execute($currentLaboratory, $order->id),
                            'add' => app(AddExamToLaboratoryOrder::class)->execute($currentLaboratory, $order->id, $exam->id),
                            'delete_line' => app(RemoveExamFromLaboratoryOrder::class)->execute($currentLaboratory, $order->id, $job['line_id']),
                            'transition' => app(TransitionLaboratoryOrderStatus::class)->execute(
                                $currentLaboratory,
                                $order->id,
                                LaboratoryOrder::STATUS_IN_PROCESS,
                            ),
                        };
                        $result = 'ok';
                    } catch (ValidationException) {
                        $result = 'validation';
                    } catch (Throwable $exception) {
                        $result = 'error:'.$exception::class.':'.$exception->getMessage();
                    }

                    file_put_contents($directory.'/'.$name, $result);
                    exit(0);
                }

                $children[] = $pid;
            }

            touch($gate);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            DB::reconnect();

            return collect(array_keys($jobs))->mapWithKeys(fn (string $name): array => [
                $name => (string) file_get_contents($directory.'/'.$name),
            ])->all();
        } finally {
            DB::reconnect();
            foreach (array_merge([$gate], array_map(fn (string $name): string => $directory.'/'.$name, array_keys($jobs))) as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function assertConsistentEconomics(
        LaboratoryOrder $order,
        ?string $type,
        ?string $value,
        string $subtotal,
        string $discount,
        string $total,
    ): void {
        $order->refresh();
        $this->assertSame($type, $order->discount_type);
        $this->assertSame($value, $order->discount_value);
        $this->assertSame($subtotal, $order->subtotal);
        $this->assertSame($discount, $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame($total, $order->total);
        $lineCents = DB::table('laboratory_order_exams')
            ->where('laboratory_order_id', $order->id)
            ->get(['unit_price'])
            ->sum(fn (object $line): int => (int) str_replace('.', '', $line->unit_price));
        $this->assertSame((int) str_replace('.', '', $subtotal), $lineCents);
    }

    private function requirePostgreSqlConcurrencySupport(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl_fork for real concurrent connections.');
        }
    }
}
