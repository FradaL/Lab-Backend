<?php

namespace Tests\Feature\ClinicalReasons;

use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\GlobalClinicalReasonManager;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\SaasAuditLog;
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

class GlobalClinicalReasonAdministrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_independent_connections_serialize_catalog_writes_and_revocation(string $case, string $expected): void
    {
        $admin = User::factory()->create();
        $operator = User::factory()->create();
        config(['saas.bootstrap.enabled' => true, 'saas.bootstrap.user_id' => $admin->id]);
        $access = app(SaasAccessManager::class);
        $access->bootstrap();
        foreach ([GlobalClinicalReasonManager::CREATE, GlobalClinicalReasonManager::PUBLISH, GlobalClinicalReasonManager::MANAGE] as $capability) {
            $grant = $access->grant($admin, $operator->id, ['capability' => $capability, 'reason' => 'FICTICIO']);
            if ($capability === GlobalClinicalReasonManager::PUBLISH) {
                $publishGrant = $grant;
            }
        }
        $manager = app(GlobalClinicalReasonManager::class);
        $definition = ['name' => 'FICTICIO', 'description' => 'FICTICIO', 'classification' => 'incidence_only', 'evidence_requirements' => ['schema_version' => 1, 'requirements' => []], 'is_active' => true, 'change_reason' => 'FICTICIO'];
        $create = ['code' => 'ficticio', 'definition' => $definition];
        $reasonId = $case === 'create' ? null : $manager->create($operator, $create)['data']['reason']->id;
        $operation = function (bool $writer) use ($case, $access, $admin, $operator, $publishGrant, $manager, $reasonId, $create, $definition): void {
            $publish = fn () => $manager->publish($operator, $reasonId, ['expected_number' => 1, 'definition' => $definition]);
            $revoke = fn () => $access->revoke($admin, $operator->id, $publishGrant->id, ['revision' => 1, 'reason' => 'FICTICIO']);
            match ($case) {
                'create' => $manager->create($operator, $create),
                'publish' => $publish(),
                'activation_before_publish' => $writer ? $manager->activation($operator, $reasonId, ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO']) : $publish(),
                'revoke_before_publish' => $writer ? $revoke() : $publish(),
                'publish_before_revoke' => $writer ? $publish() : $revoke(),
            };
        };
        $dir = sys_get_temp_dir().'/global-reason-race-'.bin2hex(random_bytes(8));
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
            $versions = $case === 'create' || $case === 'revoke_before_publish' ? 1 : 2;
            $this->assertDatabaseCount('global_clinical_reasons', 1);
            $this->assertDatabaseCount('global_clinical_reason_versions', $versions);
            $this->assertSame($versions, SaasAuditLog::whereNotNull('global_reason_id')->count());
            $this->assertSame(range(1, $versions), GlobalClinicalReasonVersion::orderBy('number')->pluck('number')->all());
            $this->assertDatabaseCount('laboratory_clinical_reasons', 0);
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
        return [['create', 'global_clinical_reason_code_conflict'], ['publish', 'clinical_reason_version_conflict'], ['activation_before_publish', 'clinical_reason_version_conflict'], ['revoke_before_publish', '403'], ['publish_before_revoke', 'ok']];
    }
}
