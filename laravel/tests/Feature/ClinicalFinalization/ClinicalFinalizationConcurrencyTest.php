<?php

namespace Tests\Feature\ClinicalFinalization;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Actions\LaboratoryOrders\RemoveExamFromLaboratoryOrder;
use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\LaboratoryOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;
use Throwable;

class ClinicalFinalizationConcurrencyTest extends TestCase
{
    use ClinicalResultScenario,DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_real_connections_preserve_resolution_and_closure(string $setup, string $writer, string $reader, string $expected): void
    {
        [$lab,$actor,$order,$line] = $this->scenario($setup === 'validated' || $writer === 'cancel');
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $service = app(ClinicalResolutionService::class);
        $reviewer = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reviewer, $lab, RbacCatalog::PERMISSIONS);
        $reasonReference = $this->exceptionReference($lab, $actor);
        $requestId = null;
        $resultRevision = 0;
        if ($setup === 'validated') {
            app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
            app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'DEMO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
            $resultRevision = 1;
            if (! in_array($writer, ['validate', 'request'], true)) {
                app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
                $resultRevision = 2;
            }
        } else {
            $request = $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $reasonReference, 'justification' => 'FICTICIO']);
            $requestId = $request['exception']['id'];
            if ($setup === 'resolved') {
                $service->decideException($lab, $reviewer, $order->id, $line->id, $requestId, true, ['clinical_revision' => $request['clinical_revision'], 'request_revision' => 1]);
            }
        }
        $revision = $order->fresh()->clinical_revision;
        $operation = function (string $op) use ($service, $lab, $actor, $reviewer, $order, $line, $requestId, $revision, $resultRevision, $reasonReference): void {
            match ($op) {
                'line' => $service->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $revision, 'result_revision' => $resultRevision]),
                'close' => $service->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $revision]),
                'approve' => $service->decideException($lab, $reviewer, $order->id, $line->id, $requestId, true, ['clinical_revision' => $revision, 'request_revision' => 1]),
                'reject' => $service->decideException($lab, $reviewer, $order->id, $line->id, $requestId, false, ['clinical_revision' => $revision, 'request_revision' => 1, 'reason' => 'FICTICIO rechazo']),
                'validate' => app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]),
                'rectify' => app(LaboratoryValidationService::class)->rectify($lab, $actor, $order->id, $line->id, ['revision' => 2, 'reason' => 'FICTICIO']),
                'add' => app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $actor),
                'remove' => app(RemoveExamFromLaboratoryOrder::class)->execute($lab, $order->id, $line->id, $actor),
                'cancel' => app(TransitionLaboratoryOrderStatus::class)->execute($lab, $order->id, 'cancelled', $actor),
                'request' => $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $revision, 'result_revision' => 1, 'reason_reference' => $reasonReference, 'justification' => 'FICTICIO']),
                default => null,
            };
        };
        $dir = sys_get_temp_dir().'/be08-race-'.bin2hex(random_bytes(8));
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
                }if (! file_exists($dir.'/go')) {
                    throw new \RuntimeException('Writer signal missing');
                }$operation($reader);
                $result = ['status' => 'ok'];
            } catch (ClinicalConflict $e) {
                $result = ['status' => $e->errorCode];
            } catch (AuthorizationException $e) {
                $result = ['status' => 'forbidden'];
            } catch (ValidationException $e) {
                $result = ['status' => 'validation'];
            } catch (HttpExceptionInterface $e) {
                $result = ['status' => $e->getStatusCode() === 403 ? 'forbidden' : 'http_error'];
            } catch (Throwable $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
            }
            file_put_contents($dir.'/result', json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            exit(0);
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            LaboratoryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($writer === 'permission') {
                $reviewer->revokePermissionTo('orders.clinical_exceptions.approve');
            } elseif ($writer === 'membership') {
                $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => false]);
            } else {
                $operation($writer);
            }
            touch($dir.'/go');
            $deadline = microtime(true) + 15;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($dir.'/pid')) {
                    $blocked = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock')", [(int) file_get_contents($dir.'/pid')]);
                    if ($blocked || file_exists($dir.'/result')) {
                        break;
                    }
                }usleep(1000);
            }
            $this->assertTrue($blocked, 'An independent PostgreSQL connection must really wait on the writer lock.');
            DB::commit();
            pcntl_waitpid($pid, $exit);
            $this->assertSame(0, pcntl_wexitstatus($exit));
            $result = json_decode(file_get_contents($dir.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $result['status'], $result['message'] ?? '');
            $this->assertSame($writer === 'close' ? 1 : 0, DB::table('clinical_order_finalizations')->count());
            $this->assertDatabaseCount('laboratory_order_exams', $writer === 'add' ? 2 : 1);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }touch($dir.'/go');
            pcntl_waitpid($pid, $exit);
            foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
            setPermissionsTeamId(null);
        }
    }

    public static function races(): array
    {
        return [
            'validation versus line finalization' => ['validated', 'validate', 'line', 'result_revision_conflict'],
            'rectification versus line finalization' => ['validated', 'rectify', 'line', 'clinical_line_not_validated'],
            'finalization versus rectification' => ['validated', 'line', 'rectify', 'clinical_line_resolved'],
            'two line finalizations' => ['validated', 'line', 'line', 'clinical_revision_conflict'],
            'two order closures' => ['resolved', 'close', 'close', 'clinical_order_finalized'],
            'pending request blocks validation' => ['validated', 'request', 'validate', 'clinical_exception_pending'],
            'request versus validation' => ['validated', 'validate', 'request', 'clinical_exception_not_available'],
            'approval versus closure' => ['pending', 'approve', 'close', 'clinical_revision_conflict'],
            'two approvals' => ['pending', 'approve', 'approve', 'clinical_revision_conflict'],
            'rejection versus approval' => ['pending', 'reject', 'approve', 'clinical_revision_conflict'],
            'cancellation versus closure' => ['resolved', 'cancel', 'close', 'clinical_order_not_open'],
            'addition versus closure' => ['resolved', 'add', 'close', 'clinical_revision_conflict'],
            'closure versus addition' => ['resolved', 'close', 'add', 'validation'],
            'closure versus removal' => ['resolved', 'close', 'remove', 'validation'],
            'permission change versus approval' => ['pending', 'permission', 'approve', 'forbidden'],
            'membership revocation versus closure' => ['resolved', 'membership', 'close', 'forbidden'],
        ];
    }
}
