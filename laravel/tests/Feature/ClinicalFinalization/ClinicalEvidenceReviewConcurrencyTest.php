<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalResolutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

class ClinicalEvidenceReviewConcurrencyTest extends TestCase
{
    use ClinicalExceptionScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_waiting_review_rechecks_current_state_and_dependencies(string $writer, string $reader, string $expected): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $this->assignDirectLaboratoryPermissions($s['approver'], $s['lab'], ['orders.clinical_finalization.view', 'orders.clinical_exceptions.view']);
        $request = $this->request($s);
        $revision = $s['order']->fresh()->clinical_revision;
        $dependencies = DB::scalar('SELECT clinical_exception_dependencies(?)::text', [$s['line']->id]);
        $originalReview = (array) DB::table('clinical_exception_evidence_reviews')->sole();
        $operation = function (string $name) use ($s, $request, $revision, $dependencies): void {
            setPermissionsTeamId($s['lab']->id);
            if ($name === 'order_permission') {
                DB::table('laboratory_orders')->where('id', $s['order']->id)->lockForUpdate()->first();
            } elseif ($name === 'show') {
                app(ClinicalResolutionService::class)->show($s['lab'], $s['approver'], $s['order']->id);
            } elseif ($name === 'exceptions') {
                app(ClinicalResolutionService::class)->exceptions($s['lab'], $s['approver'], $s['order']->id, $s['line']->id);
            } elseif ($name === 'raw_review') {
                DB::table('clinical_exception_evidence_reviews')->insert(['laboratory_id' => $s['lab']->id, 'laboratory_order_id' => $s['order']->id,
                    'laboratory_order_exam_id' => $s['line']->id, 'exception_request_id' => $request, 'reviewed_by' => $s['approver']->id,
                    'kind' => 'review', 'justification' => 'FICTICIO: revisión SQL en espera', 'dependency_snapshot' => $dependencies, 'created_at' => now()]);
            } elseif ($name === 'review') {
                app(ClinicalResolutionService::class)->reviewException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $request,
                    ['clinical_revision' => $revision, 'request_revision' => 1, 'dependency_token' => hash('sha256', $dependencies), 'justification' => 'FICTICIO: revisión']);
            } elseif ($name === 'permission') {
                DB::table('model_has_permissions')->where('laboratory_id', $s['lab']->id)->where('model_id', $s['approver']->id)->delete();
            } elseif ($name === 'evidence') {
                $this->evidence($s, 'patient_refusal');
            } else {
                app(ClinicalResolutionService::class)->decideException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $request, $name === 'approve',
                    ['clinical_revision' => $revision, 'request_revision' => 1, 'reason' => 'FICTICIO: decisión']);
            }
        };
        $directory = sys_get_temp_dir().'/exc06-race-'.bin2hex(random_bytes(8));
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
            } catch (QueryException $exception) {
                $result = ['status' => $exception->errorInfo[0]];
            } catch (HttpException $exception) {
                $result = ['status' => (string) $exception->getStatusCode()];
            } catch (AuthorizationException $exception) {
                $result = ['status' => '403'];
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
            if ($writer === 'order_permission') {
                DB::table('model_has_permissions')->where('laboratory_id', $s['lab']->id)->where('model_id', $s['approver']->id)->delete();
            }
            DB::commit();
            pcntl_waitpid($pid, $exit);
            $this->assertSame(0, pcntl_wexitstatus($exit));
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $result['status'], $result['message'] ?? '');
            $this->assertDatabaseCount('clinical_exception_evidence_reviews', 1);
            $this->assertSame($originalReview, (array) DB::table('clinical_exception_evidence_reviews')->sole());
            $this->assertDatabaseCount('clinical_line_resolutions', $writer === 'approve' ? 1 : 0);
            $this->assertDatabaseCount('clinical_exception_decisions', in_array($writer, ['approve', 'reject'], true) ? 1 : 0);
            $this->assertDatabaseHas('clinical_exception_requests', ['id' => $request, 'status' => match ($writer) {
                'approve' => 'approved', 'reject' => 'rejected', default => 'pending'
            }]);
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
            'approval blocks SQL review' => ['approve', 'raw_review', '23514'],
            'rejection blocks SQL review' => ['reject', 'raw_review', '23514'],
            'approval blocks service review' => ['approve', 'review', 'clinical_revision_conflict'],
            'rejection blocks service review' => ['reject', 'review', 'clinical_revision_conflict'],
            'dependencies invalidate SQL review' => ['evidence', 'raw_review', '23514'],
            'dependencies invalidate service review' => ['evidence', 'review', 'clinical_exception_dependencies_conflict'],
            'show reads order before revocation' => ['order_permission', 'show', '403'],
            'exceptions read order before revocation' => ['order_permission', 'exceptions', '403'],
            'revocation blocks service review' => ['permission', 'review', '403'],
        ];
    }
}
