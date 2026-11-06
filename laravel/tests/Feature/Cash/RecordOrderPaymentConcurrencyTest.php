<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\RecordOrderPayment;
use App\ClinicalExams\ClinicalConflict;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class RecordOrderPaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_simultaneous_posts_are_serialized_by_identity_and_order(string $scenario, array $expected, int $count): void
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        $other = $scenario === 'other-order' ? LaboratoryOrder::factory()->for($lab)->create() : $order;
        $directory = sys_get_temp_dir().'/cash-payment-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::reconnect();
                    DB::statement("SET statement_timeout='15s'");
                    try {
                        touch($directory.'/ready-'.$index);
                        $deadline = microtime(true) + 10;
                        while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                            usleep(1000);
                        }
                        if (! file_exists($directory.'/go')) {
                            throw new \RuntimeException('Race barrier timed out');
                        }
                        setPermissionsTeamId($lab->id);
                        if ($scenario === 'total-change' && $index === 1) {
                            DB::transaction(function () use ($lab, $actor, $order): void {
                                $locked = LaboratoryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                                app(RecalculateLaboratoryOrderEconomics::class)->execute($lab, $locked, $actor);
                            });
                            $status = 'total_changed';
                        } else {
                            $result = app(RecordOrderPayment::class)->execute($lab, $actor, $index === 0 ? $order->id : $other->id,
                                ['expected_revision' => 1, 'amount' => $scenario === 'different-content' && $index === 1 ? '20.00' : '10.00', 'method' => 'cash'],
                                'ficticio-race-payment-'.($scenario === 'different-keys' ? $index : 'same'));
                            $status = $result['replayed'] ? 'replayed' : 'created';
                        }
                    } catch (ClinicalConflict $error) {
                        $status = $error->errorCode;
                    } catch (Throwable $error) {
                        $status = $error::class.':'.$error->getMessage();
                    }
                    file_put_contents($directory.'/result-'.$index, $status);
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 10;
            while ((! file_exists($directory.'/ready-0') || ! file_exists($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            touch($directory.'/go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $actual = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
            sort($actual);
            sort($expected);
            if ($scenario === 'total-change') {
                $this->assertContains($actual, [['created', 'total_changed'], ['cash_revision_conflict', 'total_changed']]);
                $count = in_array('created', $actual, true) ? 1 : 0;
                $this->assertSame('0.00', $order->fresh()->total);
                $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 2 + $count]);
            } else {
                $this->assertSame($expected, $actual);
            }
            DB::reconnect();
            $this->assertDatabaseCount('laboratory_order_cash_movements', $count);
            $this->assertDatabaseCount('laboratory_order_cash_operations', $count);
            $this->assertSame($count, DB::table('audit_logs')->where('event', 'cash.payment_recorded')->count());
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public static function races(): array
    {
        return [
            ['same', ['created', 'replayed'], 1],
            ['different-content', ['created', 'cash_idempotency_conflict'], 1],
            ['different-keys', ['created', 'cash_revision_conflict'], 1],
            ['other-order', ['created', 'cash_idempotency_conflict'], 1],
            ['total-change', [], 0],
        ];
    }
}
