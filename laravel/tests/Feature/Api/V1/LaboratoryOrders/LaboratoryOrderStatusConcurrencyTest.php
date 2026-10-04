<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

final class LaboratoryOrderStatusConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_pending_transitions_have_a_serializable_workflow_result(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        $order = LaboratoryOrder::factory()->create(['status' => LaboratoryOrder::STATUS_PENDING]);

        $results = $this->runConcurrently($order, [
            LaboratoryOrder::STATUS_IN_PROCESS,
            LaboratoryOrder::STATUS_CANCELLED,
        ]);

        $this->assertSame(LaboratoryOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('ok', $results[LaboratoryOrder::STATUS_CANCELLED]);
        $this->assertContains($results[LaboratoryOrder::STATUS_IN_PROCESS], ['ok', 'validation']);
        $this->assertNotContains('error', $results);

        DB::table('laboratory_orders')->where('id', $order->id)->update(['status' => LaboratoryOrder::STATUS_PENDING]);
    }

    public function test_only_one_concurrent_terminal_transition_can_win(): void
    {
        $this->requirePostgreSqlConcurrencySupport();
        $order = LaboratoryOrder::factory()->create(['status' => LaboratoryOrder::STATUS_IN_PROCESS]);

        $results = $this->runConcurrently($order, [
            LaboratoryOrder::STATUS_COMPLETED,
            LaboratoryOrder::STATUS_CANCELLED,
        ]);

        $this->assertContains($order->fresh()->status, [
            LaboratoryOrder::STATUS_COMPLETED,
            LaboratoryOrder::STATUS_CANCELLED,
        ]);
        $this->assertSame(1, collect($results)->filter(fn (string $result): bool => $result === 'ok')->count());
        $this->assertSame(1, collect($results)->filter(fn (string $result): bool => $result === 'validation')->count());
        $this->assertNotContains('error', $results);

        DB::table('laboratory_orders')->where('id', $order->id)->update(['status' => LaboratoryOrder::STATUS_PENDING]);
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, string>
     */
    private function runConcurrently(LaboratoryOrder $order, array $targets): array
    {
        $directory = sys_get_temp_dir().'/donqer-order-concurrency-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $gate = $directory.'/go';
        $children = [];
        DB::disconnect();

        try {
            foreach ($targets as $target) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Unable to fork the PostgreSQL concurrency worker.');

                if ($pid === 0) {
                    $deadline = microtime(true) + 5;
                    while (! file_exists($gate) && microtime(true) < $deadline) {
                        usleep(1000);
                    }

                    DB::reconnect();
                    try {
                        $laboratory = Laboratory::query()->findOrFail($order->laboratory_id);
                        app(TransitionLaboratoryOrderStatus::class)->execute($laboratory, $order->id, $target);
                        $result = 'ok';
                    } catch (ValidationException) {
                        $result = 'validation';
                    } catch (Throwable $exception) {
                        $result = 'error:'.$exception::class.':'.$exception->getMessage();
                    }

                    file_put_contents($directory.'/'.$target, $result);
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

            return collect($targets)->mapWithKeys(fn (string $target): array => [
                $target => (string) file_get_contents($directory.'/'.$target),
            ])->all();
        } finally {
            DB::reconnect();
            foreach (array_merge([$gate], array_map(fn (string $target): string => $directory.'/'.$target, $targets)) as $path) {
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
