<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\PriceList;
use App\Models\PriceListExam;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

final class LaboratoryOrderExamConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_status_transition_and_add_serialize_on_the_same_order_lock(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'add' => 'add',
            'transition' => 'transition',
        ]);

        $this->assertSame('ok', $results['transition']);
        $this->assertContains($results['add'], ['ok', 'validation']);
        $this->assertSame(LaboratoryOrder::STATUS_IN_PROCESS, $order->fresh()->status);
        $this->assertSame($results['add'] === 'ok' ? 1 : 0, DB::table('laboratory_order_exams')->count());
        $this->assertNotContains('error', $results);

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
        DB::table('laboratory_orders')->where('id', $order->id)->update(['status' => LaboratoryOrder::STATUS_PENDING]);
    }

    public function test_two_concurrent_identical_adds_create_two_distinct_lines(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $exam] = $this->context();

        $results = $this->runConcurrently($laboratory, $order, $exam, [
            'add-a' => 'add',
            'add-b' => 'add',
        ]);

        $this->assertSame(['ok', 'ok'], array_values($results));
        $lines = DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->get();
        $this->assertCount(2, $lines);
        $this->assertCount(2, $lines->pluck('id')->unique());
        $this->assertSame([$exam->id], $lines->pluck('laboratory_exam_id')->unique()->values()->all());
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->fresh()->status);

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
    }

    /** @return array{Laboratory, LaboratoryOrder, LaboratoryExam} */
    private function context(): array
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
            'price' => '35.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);

        return [$laboratory, $order, $exam];
    }

    /**
     * @param  array<string, string>  $jobs
     * @return array<string, string>
     */
    private function runConcurrently(
        Laboratory $laboratory,
        LaboratoryOrder $order,
        LaboratoryExam $exam,
        array $jobs,
    ): array {
        $directory = sys_get_temp_dir().'/donqer-order-exam-concurrency-'.bin2hex(random_bytes(8));
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
                        if ($job === 'transition') {
                            app(TransitionLaboratoryOrderStatus::class)->execute(
                                $currentLaboratory,
                                $order->id,
                                LaboratoryOrder::STATUS_IN_PROCESS,
                            );
                        } else {
                            app(AddExamToLaboratoryOrder::class)->execute(
                                $currentLaboratory,
                                $order->id,
                                $exam->id,
                            );
                        }
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

    private function requirePostgreSqlConcurrencySupport(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl_fork for real concurrent connections.');
        }
    }
}
