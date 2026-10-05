<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Actions\LaboratoryOrders\RemoveExamFromLaboratoryOrder;
use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

final class LaboratoryOrderExamDestroyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_status_transition_and_delete_serialize_on_the_same_order_lock(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $line] = $this->context();

        $results = $this->runConcurrently($laboratory, $order, $line, [
            'delete' => 'delete',
            'transition' => 'transition',
        ]);

        $this->assertSame('ok', $results['transition']);
        $this->assertContains($results['delete'], ['ok', 'validation']);
        $order->refresh();
        $this->assertSame(LaboratoryOrder::STATUS_IN_PROCESS, $order->status);
        $this->assertSame($results['delete'] === 'ok' ? 0 : 1, DB::table('laboratory_order_exams')->count());
        $this->assertSame($results['delete'] === 'ok' ? '0.00' : '35.00', $order->subtotal);
        $this->assertSame($order->subtotal, $order->total);
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertNotContains('error', $results);

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
        DB::table('laboratory_orders')->where('id', $order->id)->update(['status' => LaboratoryOrder::STATUS_PENDING]);
    }

    public function test_two_concurrent_deletes_of_same_line_yield_one_success_and_one_404(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        [$laboratory, $order, $line, $duplicate] = $this->context(withDuplicate: true);

        $results = $this->runConcurrently($laboratory, $order, $line, [
            'delete-a' => 'delete',
            'delete-b' => 'delete',
        ]);

        $this->assertSame(['not-found', 'ok'], collect($results)->sort()->values()->all());
        $this->assertDatabaseMissing('laboratory_order_exams', ['id' => $line->id]);
        $this->assertDatabaseHas('laboratory_order_exams', [
            'id' => $duplicate->id,
            'laboratory_exam_id' => $line->laboratory_exam_id,
        ]);
        $order->refresh();
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->status);
        $this->assertSame('40.00', $order->subtotal);
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame('40.00', $order->total);

        DB::table('laboratory_order_exams')->where('laboratory_order_id', $order->id)->delete();
    }

    /**
     * @return array{Laboratory, LaboratoryOrder, LaboratoryOrderExam, 3?: LaboratoryOrderExam}
     */
    private function context(bool $withDuplicate = false): array
    {
        $laboratory = Laboratory::factory()->create();
        $priceList = PriceList::factory()->for($laboratory)->create(['currency' => 'GTQ']);
        $order = LaboratoryOrder::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'status' => LaboratoryOrder::STATUS_PENDING,
        ]);
        $exam = LaboratoryExam::factory()->for($laboratory)->create();
        $attributes = [
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'unit_price' => '35.00',
            'exam_code' => $exam->code,
            'exam_name' => $exam->name,
            'price_list_name' => $priceList->name,
        ];
        $line = LaboratoryOrderExam::factory()->for($laboratory)->create($attributes);

        if (! $withDuplicate) {
            $order->update(['subtotal' => '35.00', 'total' => '35.00']);

            return [$laboratory, $order, $line];
        }

        $duplicate = LaboratoryOrderExam::factory()->for($laboratory)->create(array_replace(
            $attributes,
            ['unit_price' => '40.00', 'exam_name' => 'Independent snapshot'],
        ));
        $order->update(['subtotal' => '75.00', 'total' => '75.00']);

        return [$laboratory, $order, $line, $duplicate];
    }

    /**
     * @param  array<string, string>  $jobs
     * @return array<string, string>
     */
    private function runConcurrently(
        Laboratory $laboratory,
        LaboratoryOrder $order,
        LaboratoryOrderExam $line,
        array $jobs,
    ): array {
        $directory = sys_get_temp_dir().'/donqer-order-exam-delete-concurrency-'.bin2hex(random_bytes(8));
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
                            app(RemoveExamFromLaboratoryOrder::class)->execute(
                                $currentLaboratory,
                                $order->id,
                                $line->id,
                            );
                        }
                        $result = 'ok';
                    } catch (ValidationException) {
                        $result = 'validation';
                    } catch (HttpExceptionInterface $exception) {
                        $result = $exception->getStatusCode() === 404 ? 'not-found' : 'error';
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
