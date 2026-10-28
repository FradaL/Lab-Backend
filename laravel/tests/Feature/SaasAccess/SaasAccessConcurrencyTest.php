<?php

namespace Tests\Feature\SaasAccess;

use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\Models\SaasCapabilityAssignment;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class SaasAccessConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_independent_connections_preserve_access_invariants(string $case, string $expected): void
    {
        $admin = User::factory()->create();
        $second = User::factory()->create();
        $target = User::factory()->create();
        config(['saas.bootstrap.enabled' => true, 'saas.bootstrap.user_id' => $admin->id]);
        $manager = app(SaasAccessManager::class);
        $grant = null;
        if ($case !== 'bootstrap') {
            $manager->bootstrap();
            if ($case !== 'grant') {
                $grant = $manager->grant($admin, $second->id, ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO']);
            }
        }
        $adminGrant = SaasCapabilityAssignment::where('user_id', $admin->id)->first();
        $operation = function (bool $writer) use ($case, $manager, $admin, $second, $target, $grant, $adminGrant): void {
            $reason = ['reason' => 'FICTICIO: concurrencia', 'revision' => 1];
            match ($case) {
                'bootstrap' => $manager->bootstrap(),
                'grant' => $manager->grant($admin, $target->id, ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO']),
                'revocation' => $manager->revoke($admin, $second->id, $grant->id, $reason),
                'revocation_before_grant' => $writer ? $manager->revoke($admin, $second->id, $grant->id, $reason) : $manager->grant($second, $target->id, ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO']),
                'self_revocations' => $writer ? $manager->revoke($admin, $admin->id, $adminGrant->id, $reason) : $manager->revoke($second, $second->id, $grant->id, $reason),
                'deactivation_before_revocation' => $writer ? DB::table('users')->where('id', $admin->id)->update(['is_active' => false]) : $manager->revoke($second, $second->id, $grant->id, $reason),
                'deactivations' => DB::table('users')->where('id', $writer ? $admin->id : $second->id)->update(['is_active' => false]),
            };
        };
        $dir = sys_get_temp_dir().'/saas-access-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($dir.'/pid', (string) DB::scalar('SELECT pg_backend_pid()'));
                $deadline = microtime(true) + 15;
                while (! file_exists($dir.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($dir.'/go')) {
                    throw new \RuntimeException('Missing writer signal.');
                }
                $operation(false);
                $result = 'ok';
            } catch (ClinicalConflict $e) {
                $result = $e->errorCode;
            } catch (HttpExceptionInterface $e) {
                $result = (string) $e->getStatusCode();
            } catch (QueryException $e) {
                $result = $e->errorInfo[0];
            } catch (Throwable $e) {
                $result = $e::class.': '.$e->getMessage();
            }
            file_put_contents($dir.'/result', $result);
            DB::disconnect();
            exit(0);
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            app(SaasAccessAuthority::class)->lock();
            $operation(true);
            touch($dir.'/go');
            $deadline = microtime(true) + 15;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($dir.'/pid')) {
                    $blocked = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock')", [(int) file_get_contents($dir.'/pid')]);
                    if ($blocked || file_exists($dir.'/result')) {
                        break;
                    }
                }
                usleep(1000);
            }
            $this->assertTrue($blocked, 'The second PostgreSQL connection must really wait.');
            DB::commit();
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame($expected, file_get_contents($dir.'/result'));
            $active = DB::table('saas_capability_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('u.is_active', true)->whereNull('a.revoked_at')->where('a.capability_code', SaasAccessAuthority::MANAGE)->count();
            $this->assertGreaterThanOrEqual(1, $active);
            $this->assertSame($case === 'grant' ? 1 : 0, SaasCapabilityAssignment::where('user_id', $target->id)->whereNull('revoked_at')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            touch($dir.'/go');
            pcntl_waitpid($pid, $status);
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public static function races(): array
    {
        return [['bootstrap', 'ok'], ['grant', 'saas_capability_already_granted'], ['revocation', 'saas_assignment_revision_conflict'], ['revocation_before_grant', '403'], ['self_revocations', 'saas_last_access_manager'], ['deactivation_before_revocation', 'saas_last_access_manager'], ['deactivations', '23514']];
    }
}
