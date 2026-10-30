<?php

namespace Tests\Feature\Authorization;

use App\Authorization\RolePermissionManager;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use App\UserAdministration\LaboratoryUserManager;
use App\UserAdministration\UserAdministrationConflict;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Throwable;

class RolePermissionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function context(): array
    {
        $lab = Laboratory::factory()->create();
        Subscription::factory()->create(['laboratory_id' => $lab->id]);
        $this->seed(RolePermissionSeeder::class);
        $a = $this->createLaboratoryMember($lab);
        $b = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($a, $lab, 'owner');
        $this->assignLaboratoryRole($b, $lab, 'owner');

        return [$lab, $a, $b];
    }

    public function test_concurrent_replacements_of_one_revision_commit_exactly_once(): void
    {
        [$lab, $a, $b] = $this->context();
        $results = $this->race([
            function () use ($lab, $a): void {
                setPermissionsTeamId($lab->id);
                app(RolePermissionManager::class)->replace($lab, $a, 'viewer', ['expected_revision' => 1, 'permissions' => ['orders.view'], 'reason' => 'Primero']);
            },
            function () use ($lab, $b): void {
                setPermissionsTeamId($lab->id);
                app(RolePermissionManager::class)->replace($lab, $b, 'viewer', ['expected_revision' => 1, 'permissions' => ['patients.view'], 'reason' => 'Segundo']);
            },
        ]);
        sort($results);
        $this->assertSame(['ok', 'role_permissions_revision_conflict'], $results);
        $this->assertSame(2, DB::table('roles')->where('laboratory_id', $lab->id)->where('name', 'viewer')->value('permissions_revision'));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'role.permissions_replaced')->count());
    }

    public function test_two_owner_demotions_preserve_one_active_owner_even_with_an_administrator(): void
    {
        [$lab, $a, $b] = $this->context();
        $admin = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($admin, $lab, 'administrator');
        $results = $this->race(array_map(fn (User $actor) => function () use ($lab, $actor): void {
            setPermissionsTeamId($lab->id);
            app(LaboratoryUserManager::class)->changeRole($lab, $actor, $actor, 'administrator');
        }, [$a, $b]));
        sort($results);
        $this->assertSame(['laboratory_last_owner', 'ok'], $results);
        $this->assertSame(1, DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'role_id')->where('roles.laboratory_id', $lab->id)->where('name', 'owner')->count());
    }

    public function test_concurrent_owner_deactivation_and_demotion_preserve_one_active_owner(): void
    {
        [$lab, $a, $b] = $this->context();
        $results = $this->race([
            function () use ($lab, $a): void {
                setPermissionsTeamId($lab->id);
                app(LaboratoryUserManager::class)->changeStatus($lab, $a, $a, 'inactive');
            },
            function () use ($lab, $b): void {
                setPermissionsTeamId($lab->id);
                app(LaboratoryUserManager::class)->changeRole($lab, $b, $b, 'viewer');
            },
        ]);
        sort($results);
        $this->assertSame(['laboratory_last_owner', 'ok'], $results);
        $owners = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'role_id')
            ->join('laboratory_user', function ($join): void {
                $join->on('laboratory_user.user_id', '=', 'model_id')->on('laboratory_user.laboratory_id', '=', 'roles.laboratory_id');
            })->where('roles.laboratory_id', $lab->id)->where('name', 'owner')->where('laboratory_user.is_active', true)->count();
        $this->assertSame(1, $owners);
    }

    public function test_warm_authorization_in_another_process_observes_committed_revocation(): void
    {
        [$lab, $owner] = $this->context();
        $viewer = $this->createLaboratoryMember($lab);
        $role = $this->assignLaboratoryRole($viewer, $lab, 'viewer');
        $role->givePermissionTo('roles.permissions.manage');
        $results = $this->race([
            function (string $directory) use ($lab, $viewer): void {
                $this->actingAs($viewer)->withHeader('X-Laboratory-ID', (string) $lab->id);
                $this->getJson('/api/v1/laboratory/permissions')->assertOk();
                touch($directory.'/warm');
                $this->awaitFile($directory.'/committed');
                $this->getJson('/api/v1/laboratory/permissions')->assertForbidden();
            },
            function (string $directory) use ($lab, $owner): void {
                $this->awaitFile($directory.'/warm');
                setPermissionsTeamId($lab->id);
                app(RolePermissionManager::class)->replace($lab, $owner, 'viewer', ['expected_revision' => 1, 'permissions' => [], 'reason' => 'Revocación']);
                touch($directory.'/committed');
            },
        ]);
        $this->assertSame(['ok', 'ok'], $results);
    }

    public function test_in_flight_manager_is_reauthorized_after_waiting_for_a_revocation_commit(): void
    {
        [$lab, $owner] = $this->context();
        $viewer = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($viewer, $lab, 'viewer')->givePermissionTo('roles.permissions.manage');
        $results = $this->race([
            function (string $directory) use ($lab, $owner): void {
                setPermissionsTeamId($lab->id);
                DB::transaction(function () use ($directory, $lab, $owner): void {
                    Laboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
                    touch($directory.'/locked');
                    $this->awaitFile($directory.'/authorized');
                    app(RolePermissionManager::class)->replace($lab, $owner, 'viewer', ['expected_revision' => 1, 'permissions' => [], 'reason' => 'Revocación']);
                });
            },
            function (string $directory) use ($lab, $viewer): void {
                setPermissionsTeamId($lab->id);
                $this->awaitFile($directory.'/locked');
                Gate::forUser($viewer)->authorize('roles.permissions.manage');
                touch($directory.'/authorized');
                try {
                    app(RolePermissionManager::class)->replace($lab, $viewer, 'cashier', ['expected_revision' => 1, 'permissions' => [], 'reason' => 'Debe rechazarse']);
                } catch (AuthorizationException $exception) {
                    return;
                }
                throw new \RuntimeException('Revoked manager was allowed to write.');
            },
        ]);
        $this->assertSame(['ok', 'ok'], $results);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'role.permissions_replaced')->count());
        $this->assertSame(1, DB::table('roles')->where('laboratory_id', $lab->id)->where('name', 'cashier')->value('permissions_revision'));
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
