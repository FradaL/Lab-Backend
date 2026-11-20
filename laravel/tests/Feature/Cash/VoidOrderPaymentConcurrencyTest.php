<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\RecordOrderPayment;
use App\Cash\VoidOrderPayment;
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

class VoidOrderPaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_simultaneous_voids_and_total_changes_have_one_financial_effect(string $scenario, array $expected, int $count): void
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::VOID);
        setPermissionsTeamId($lab->id);
        $payment = app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => 1, 'amount' => '100.00', 'method' => 'cash'], 'ficticio-race-base-payment')['body']['data']['resource']['id'];
        $directory = sys_get_temp_dir().'/cash-void-race-'.bin2hex(random_bytes(8));
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
                            $result = app(VoidOrderPayment::class)->execute($lab, $actor, $order->id, $payment,
                                ['expected_revision' => 2, 'reason' => $scenario === 'different-content' && $index === 1 ? 'Otro motivo ficticio' : 'Error ficticio'],
                                'ficticio-race-void-'.($scenario === 'different-keys' ? $index : 'same'));
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
                $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 3 + $count]);
            } else {
                $this->assertSame($expected, $actual);
            }
            DB::reconnect();
            $this->assertDatabaseCount('laboratory_order_cash_movements', 1 + $count);
            $this->assertSame($count, DB::table('laboratory_order_cash_movements')->where('type', 'payment_void')->count());
            $this->assertSame(0, DB::table('laboratory_order_cash_movements')->where('type', 'refund')->count());
            $this->assertDatabaseCount('laboratory_order_cash_operations', 1 + $count);
            $this->assertSame($count, DB::table('audit_logs')->where('event', 'cash.payment_voided')->count());
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
            ['total-change', [], 0],
        ];
    }
}
