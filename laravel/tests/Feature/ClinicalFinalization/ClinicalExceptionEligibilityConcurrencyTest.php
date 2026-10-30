<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalResolutionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class ClinicalExceptionEligibilityConcurrencyTest extends TestCase
{
    use ClinicalExceptionScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_viability_and_approval_revalidate_under_the_same_order_lock(string $writer, string $reader, string $expected): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $intent = $this->intention($s);
        $evidence = $this->evidence($s, 'sample_problem');
        $first = $this->assess($s, [$evidence], intention: $intent);
        $request = $this->request($s);
        $revision = $s['order']->fresh()->clinical_revision;
        $operation = function (string $name) use ($s, $intent, $evidence, $first, $request, $revision): void {
            setPermissionsTeamId($s['lab']->id);
            match ($name) {
                'viable' => $this->assess($s, [$evidence], intention: $intent, outcome: 'viable', previous: $first),
                'non_viable' => $this->assess($s, [$evidence], intention: $intent, previous: $first),
                'approve' => app(ClinicalResolutionService::class)->decideException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $request, true, ['clinical_revision' => $revision, 'request_revision' => 1]),
            };
        };
        $directory = sys_get_temp_dir().'/exc04-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($directory.'/pid', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $deadline = microtime(true) + 15;
                while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($directory.'/go')) {
                    throw new \RuntimeException('Writer did not release worker.');
                }
                $operation($reader);
                $result = ['status' => 'ok'];
            } catch (ClinicalConflict $exception) {
                $result = ['status' => $exception->errorCode];
            } catch (Throwable $exception) {
                $result = ['status' => 'error', 'message' => $exception->getMessage()];
            }
            file_put_contents($directory.'/result', json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            exit(0);
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            $operation($writer);
            touch($directory.'/go');
            $deadline = microtime(true) + 15;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($directory.'/pid')) {
                    $backend = (int) file_get_contents($directory.'/pid');
                    $blocked = (bool) DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock') AS blocked", [$backend])->blocked;
                    if ($blocked || file_exists($directory.'/result')) {
                        break;
                    }
                }
                usleep(1000);
            }
            $this->assertTrue($blocked, 'The independent PostgreSQL connection must wait on the writer.');
            DB::commit();
            pcntl_waitpid($pid, $exit);
            $this->assertSame(0, pcntl_wexitstatus($exit));
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $result['status'], $result['message'] ?? '');
            $this->assertDatabaseCount('clinical_viability_assessments', $writer === 'approve' ? 1 : 2);
            $this->assertDatabaseCount('clinical_line_resolutions', $writer === 'approve' ? 1 : 0);
            $this->assertDatabaseCount('clinical_exception_decisions', $writer === 'approve' ? 1 : 0);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (file_exists($directory.'/result')) {
                foreach (glob($directory.'/*') as $file) {
                    unlink($file);
                }
                rmdir($directory);
            } else {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $exit);
            }
        }
    }

    public static function races(): array
    {
        return [
            'viable assessment commits before approval' => ['viable', 'approve', 'clinical_replacement_pending_viable'],
            'non viable successor still requires review' => ['non_viable', 'approve', 'clinical_exception_review_required'],
            'approval serializes before attempted later assessment' => ['approve', 'viable', 'clinical_line_resolved'],
        ];
    }
}
