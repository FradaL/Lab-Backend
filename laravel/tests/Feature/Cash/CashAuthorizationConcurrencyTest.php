<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\CashPermissionManager;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\UserAdministration\UserAdministrationConflict;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class CashAuthorizationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    public function test_in_flight_cash_operation_reauthorizes_after_concurrent_revocation_commits(): void
    {
        $lab = Laboratory::factory()->create();
        Subscription::factory()->for($lab)->create();
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->createLaboratoryMember($lab);
        $target = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($owner, $lab, 'owner');
        app(CashPermissionManager::class)->set($lab, $owner, $target, CashAuthority::CREATE, true, 'Asignación ficticia');
        $results = $this->race([
            function (string $directory) use ($lab, $owner, $target): void {
                setPermissionsTeamId($lab->id);
                $this->awaitFile($directory.'/warm');
                DB::transaction(function () use ($directory, $lab, $owner, $target): void {
                    Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
                    app(CashPermissionManager::class)->set($lab, $owner, $target, CashAuthority::CREATE, false, 'Revocación concurrente');
                    touch($directory.'/locked');
                    $this->awaitFile($directory.'/attempting');
                });
            },
            function (string $directory) use ($lab, $target): void {
                setPermissionsTeamId($lab->id);
                app(CashAuthority::class)->run($lab, $target, CashAuthority::CREATE, fn () => null);
                touch($directory.'/warm');
                $this->awaitFile($directory.'/locked');
                touch($directory.'/attempting');
                try {
                    app(CashAuthority::class)->run($lab, $target, CashAuthority::CREATE, function (): void {
                        throw new \RuntimeException('Revoked callback was executed.');
                    });
                } catch (AuthorizationException $error) {
                    return;
                }
                throw new \RuntimeException('Revoked operation was accepted.');
            },
        ]);
        $this->assertSame(['ok', 'ok'], $results);
        $this->assertDatabaseHas('audit_logs', ['event' => 'cash.permission_revoked']);
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertFileExists($path);
    }

    private function race(array $jobs): array
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $directory = sys_get_temp_dir().'/role-permission-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach ($jobs as $index => $job) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::reconnect();
                    DB::statement("SET statement_timeout = '20s'");
                    try {
                        $this->awaitFile($directory.'/go');
                        $job($directory);
                        $result = 'ok';
                    } catch (UserAdministrationConflict $exception) {
                        $result = $exception->errorCode;
                    } catch (Throwable $exception) {
                        $result = 'error:'.$exception::class.':'.$exception->getMessage();
                    }
                    file_put_contents($directory.'/'.$index, $result);
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            touch($directory.'/go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            return array_map(fn (int $i): string => file_get_contents($directory.'/'.$i), array_keys($jobs));
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
