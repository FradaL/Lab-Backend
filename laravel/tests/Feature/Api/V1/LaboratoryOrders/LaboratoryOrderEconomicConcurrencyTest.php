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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

final class LaboratoryOrderEconomicConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_deletes_of_different_lines_leave_consistent_economics(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();
        $first = $this->line($laboratory, $order, $exam, '35.00');
        $second = $this->line($laboratory, $order, $exam, '40.00');
        $remaining = $this->line($laboratory, $order, $exam, '20.00');
        $order->update(['subtotal' => '95.00', 'total' => '95.00']);

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'delete-a' => ['action' => 'delete', 'line_id' => $first->id],
            'delete-b' => ['action' => 'delete', 'line_id' => $second->id],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $this->assertSame([$remaining->id], LaboratoryOrderExam::query()->pluck('id')->all());
        $this->assertConsistentEconomics($order, '20.00');

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
    }

    public function test_concurrent_add_and_delete_leave_composition_and_economics_consistent(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context(addPrice: '35.00');
        $deleted = $this->line($laboratory, $order, $exam, '20.00');
        $order->update(['subtotal' => '20.00', 'total' => '20.00']);

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'add' => ['action' => 'add'],
            'delete' => ['action' => 'delete', 'line_id' => $deleted->id],
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $this->assertDatabaseMissing('laboratory_order_exams', ['id' => $deleted->id]);
        $this->assertSame(['35.00'], LaboratoryOrderExam::query()->pluck('unit_price')->all());
        $this->assertConsistentEconomics($order, '35.00');

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
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
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
        PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => $addPrice,
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);

        return [$laboratory, $order, $exam];
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

    /**
     * @param  array<string, array{action: string, line_id?: int}>  $jobs
     * @return array<string, string>
     */
    private function runConcurrently(
        Laboratory $laboratory,
        LaboratoryOrder $order,
        LaboratoryExam $exam,
        array $jobs,
    ): array {
        $directory = sys_get_temp_dir().'/donqer-order-economic-concurrency-'.bin2hex(random_bytes(8));
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
                        if ($job['action'] === 'add') {
                            app(AddExamToLaboratoryOrder::class)->execute($currentLaboratory, $order->id, $exam->id);
                        } else {
                            app(RemoveExamFromLaboratoryOrder::class)->execute($currentLaboratory, $order->id, $job['line_id']);
                        }
                        $result = 'ok';
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

    private function assertConsistentEconomics(LaboratoryOrder $order, string $subtotal): void
    {
        $order->refresh();
        $this->assertSame($subtotal, $order->subtotal);
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame($subtotal, $order->total);
    }

    private function requirePostgreSqlConcurrencySupport(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl_fork for real concurrent connections.');
        }
    }
}
