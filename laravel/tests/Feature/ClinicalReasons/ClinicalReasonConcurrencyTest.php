<?php

namespace Tests\Feature\ClinicalReasons;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\Models\GlobalClinicalReason;
use App\Models\LaboratoryClinicalReason;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class ClinicalReasonConcurrencyTest extends TestCase
{
    use ClinicalReasonScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('writers')]
    public function test_independent_connections_cannot_fork_a_version(string $scope, string $mode): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario($scope);
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $append = fn () => $scope === 'global'
            ? app(ClinicalReasonVersionStore::class)->appendGlobal($reason->id, $actor, 0, $this->definition())
            : app(ClinicalReasonVersionStore::class)->appendLocal($lab, $reason->id, $actor, 0, $this->definition());
        $dir = sys_get_temp_dir().'/be-exc-021-'.bin2hex(random_bytes(8));
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
                    throw new \RuntimeException('Writer signal missing.');
                }
                if ($mode === 'service') {
                    $append();
                } else {
                    $row = $this->definition() + ['reason_id' => $reason->id, 'created_by' => $actor->id, 'number' => 1];
                    $row['evidence_requirements'] = json_encode($row['evidence_requirements'], JSON_THROW_ON_ERROR);
                    if ($scope === 'laboratory') {
                        $row['laboratory_id'] = $lab->id;
                    }
                    DB::table($scope.'_clinical_reason_versions')->insert($row);
                }
                $result = 'unexpected_success';
            } catch (ClinicalConflict $e) {
                $result = $e->errorCode;
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
            ($scope === 'global' ? GlobalClinicalReason::query() : LaboratoryClinicalReason::query())->whereKey($reason->id)->lockForUpdate()->firstOrFail();
            $append();
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
            $this->assertTrue($blocked, 'The second PostgreSQL connection must really wait on the identity.');
            DB::commit();
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $result = file_get_contents($dir.'/result');
            if ($mode === 'service') {
                $this->assertSame('clinical_reason_version_conflict', $result);
            } else {
                $this->assertContains($result, ['23514', '23505']);
            }
            $this->assertSame([1], $reason->versions()->pluck('number')->all());
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

    public static function writers(): array
    {
        return [['global', 'service'], ['laboratory', 'service'], ['global', 'sql'], ['laboratory', 'sql']];
    }
}
