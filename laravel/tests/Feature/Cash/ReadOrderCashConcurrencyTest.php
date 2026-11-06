<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\ReadOrderCash;
use App\Cash\RecordOrderPayment;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class ReadOrderCashConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('scenarios')]
    public function test_shared_order_lock_serializes_read_with_payment_or_total_writer(string $scenario): void
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::VIEW);
        $directory = sys_get_temp_dir().'/cash-read-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $readerFirst = str_starts_with($scenario, 'reader');
        $list = $scenario === 'reader-list';
        $writer = function () use ($scenario, $lab, $actor, $order): void {
            if ($scenario === 'writer-total') {
                DB::transaction(function () use ($lab, $actor, $order): void {
                    $locked = LaboratoryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                    app(RecalculateLaboratoryOrderEconomics::class)->execute($lab, $locked, $actor);
                });
            } else {
                app(RecordOrderPayment::class)->execute($lab, $actor, $order->id, ['expected_revision' => 1, 'amount' => '25.00', 'method' => 'cash'], 'ficticio-read-race-key');
            }
        };
        $reader = fn () => $list ? app(ReadOrderCash::class)->movements($lab, $actor, $order->id) : app(ReadOrderCash::class)->summary($lab, $actor, $order->id);
        DB::disconnect();
        $children = [];
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::reconnect();
                    DB::statement("SET statement_timeout='20s'");
                    setPermissionsTeamId($lab->id);
                    file_put_contents($directory.'/pid-'.$index, (string) DB::scalar('SELECT pg_backend_pid()'));
                    try {
                        if ($index === 0) {
                            if ($readerFirst) {
                                $paused = false;
                                DB::listen(function (QueryExecuted $query) use ($directory, $list, &$paused): void {
                                    $sql = strtolower($query->sql);
                                    $matches = $list ? (str_contains($sql, 'count(*)') && str_contains($sql, 'laboratory_order_cash_movements')) : str_contains($sql, 'from "laboratory_order_financial_states"');
                                    if (! $paused && $matches) {
                                        $paused = true;
                                        touch($directory.'/locked');
                                        $this->awaitFile($directory.'/release');
                                    }
                                });
                                $result = $reader();
                            } else {
                                DB::transaction(function () use ($writer, $directory): void {
                                    $writer();
                                    touch($directory.'/locked');
                                    $this->awaitFile($directory.'/release');
                                });
                                $result = ['written' => true];
                            }
                        } else {
                            $this->awaitFile($directory.'/locked');
                            touch($directory.'/attempting');
                            if ($readerFirst) {
                                $writer();
                                $result = ['written' => true];
                            } else {
                                $result = $reader();
                            }
                        }
                        file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                    } catch (Throwable $error) {
                        file_put_contents($directory.'/result-'.$index, json_encode(['error' => $error::class.':'.$error->getMessage()]));
                    }
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            DB::reconnect();
            $this->awaitFile($directory.'/locked');
            $this->awaitFile($directory.'/attempting');
            $this->awaitFile($directory.'/pid-1');
            $pid = (int) file_get_contents($directory.'/pid-1');
            $deadline = microtime(true) + 10;
            $blocked = false;
            while (microtime(true) < $deadline) {
                $blocked = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock')", [$pid]);
                if ($blocked) {
                    break;
                }
                usleep(1000);
            }
            touch($directory.'/release');
            $this->assertTrue($blocked, 'The contender must actually wait on the shared/exclusive order lock.');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map(fn ($i) => json_decode(file_get_contents($directory.'/result-'.$i), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
            foreach ($results as $result) {
                $this->assertArrayNotHasKey('error', $result, json_encode($result));
            }
            $read = $results[$readerFirst ? 0 : 1];
            if ($list) {
                $this->assertSame([], $read['data']);
                $this->assertSame(0, $read['meta']['total']);
                $this->assertSame(1, $read['meta']['financial_revision']);
            } else {
                $this->assertSame($readerFirst ? 1 : 2, $read['data']['financial_revision']);
                $this->assertSame($scenario === 'writer-total' ? '0.00' : '80.00', $read['data']['total']);
                $this->assertSame($scenario === 'writer-payment' ? '25.00' : '0.00', $read['data']['net_payments']);
                $this->assertSame($scenario === 'writer-total' ? '0.00' : ($readerFirst ? '80.00' : '55.00'), $read['data']['balance_due']);
            }
            setPermissionsTeamId($lab->id);
            $current = app(ReadOrderCash::class)->summary($lab, $actor, $order->id);
            $this->assertSame(2, $current['data']['financial_revision']);
            $this->assertSame($scenario === 'writer-total' ? '0.00' : '25.00', $current['data']['net_payments']);
        } finally {
            touch($directory.'/release');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (! file_exists($path)) {
            throw new \RuntimeException('Race barrier timed out: '.basename($path));
        }
    }

    public static function scenarios(): array
    {
        return [['writer-payment'], ['writer-total'], ['reader-summary'], ['reader-list']];
    }
}
