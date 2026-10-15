<?php

namespace Tests\Feature\ClinicalValidation;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;
use Throwable;

class ValidationConcurrencyTest extends TestCase
{
    use ClinicalResultScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        // Each race owns a disposable guarded database. Drop it on the next setup;
        // never downgrade a validated fixture into the preliminary-only BE-S2-06 schema.
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    #[DataProvider('races')]
    public function test_real_connections_serialize_clinical_decisions(string $writer, string $reader, bool $rollback, string $expected): void
    {
        [$lab,$actor,$order,$line] = $this->scenario();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $administration = app(ValidationAdministrationService::class);
        $administration->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'DEMO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        $professional = null;
        $admin = null;
        if ($writer === 'revoke') {
            $admin = $this->createLaboratoryMember($lab);
            $this->assignDirectLaboratoryPermissions($admin, $lab, ['results.professionals.manage', 'results.validation_policy.manage']);
            $professional = $administration->register($lab, $admin, ['user_id' => $actor->id, 'professional_name' => 'FICTICIO', 'profession' => 'DEMO']);
            $administration->professionalState($lab, $admin, $professional->id, true);
            $administration->setPolicy($lab, $admin, ['validation_policy' => 'professional_required']);
        }
        $revision = 1;
        $input = ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']];
        if ($writer === 'rectify') {
            app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, $input);
            $revision = 2;
        }
        $directory = sys_get_temp_dir().'/be07-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($directory.'/pid', (string) DB::scalar('SELECT pg_backend_pid()'));
                $deadline = microtime(true) + 15;
                while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($directory.'/go')) {
                    throw new \RuntimeException('Writer signal missing.');
                }
                if ($reader === 'capture') {
                    app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => $revision, 'results' => [['parameter_code' => 'N', 'value' => '3']]]);
                } elseif ($reader === 'rectify') {
                    app(LaboratoryValidationService::class)->rectify($lab, $actor, $order->id, $line->id, ['revision' => $revision, 'reason' => 'FICTICIO child']);
                } else {
                    app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => $revision, 'reference_context' => ['reference_date' => '2026-10-11']]);
                }
                $result = ['status' => 'ok'];
            } catch (ClinicalConflict $e) {
                $result = ['status' => $e->errorCode];
            } catch (AuthorizationException $e) {
                $result = ['status' => 'forbidden'];
            } catch (HttpExceptionInterface $e) {
                $result = ['status' => $e->getStatusCode() === 403 ? 'forbidden' : 'http_error'];
            } catch (Throwable $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
            }
            file_put_contents($directory.'/result', json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            exit(0);
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            if ($writer === 'policy') {
                $administration->setPolicy($lab, $actor, ['validation_policy' => 'professional_required']);
            } elseif ($writer === 'revoke') {
                $administration->professionalState($lab, $admin, $professional->id, false, ['reason' => 'FICTICIO revocation']);
            } else {
                LaboratoryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($writer === 'capture') {
                    app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 1, 'results' => [['parameter_code' => 'N', 'value' => '2']]]);
                } elseif ($writer === 'validate') {
                    app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, $input);
                } elseif ($writer === 'rectify') {
                    app(LaboratoryValidationService::class)->rectify($lab, $actor, $order->id, $line->id, ['revision' => 2, 'reason' => 'FICTICIO writer']);
                } elseif ($writer === 'cancel') {
                    $order->update(['status' => 'cancelled']);
                } elseif ($writer === 'inactive') {
                    $actor->update(['is_active' => false]);
                } elseif ($writer === 'membership') {
                    DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $actor->id)->update(['is_active' => false]);
                } elseif ($writer === 'permission') {
                    $actor->revokePermissionTo('results.validate');
                }
            }
            touch($directory.'/go');
            $deadline = microtime(true) + 15;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($directory.'/pid')) {
                    $backend = (int) file_get_contents($directory.'/pid');
                    $blocked = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock')", [$backend]);
                    if ($blocked || file_exists($directory.'/result')) {
                        break;
                    }
                }
                usleep(1000);
            }
            $this->assertTrue($blocked, 'Reader must actually wait on the writer in PostgreSQL.');
            $rollback ? DB::rollBack() : DB::commit();
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $result['status'], $result['message'] ?? '');
            $this->assertSame($expected === 'ok' || $writer === 'validate' || $writer === 'rectify' ? 1 : 0, LaboratoryResultValidation::count());
            $this->assertDatabaseCount('laboratory_order_results', 4);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            touch($directory.'/go');
            pcntl_waitpid($pid, $status);
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }rmdir($directory);
            DB::table('laboratory_orders')->update(['status' => 'pending']);
            $this->clearPermissionTeam();
        }
    }

    public static function races(): array
    {
        return [
            'capture versus validation' => ['capture', 'validate', false, 'result_revision_conflict'],
            'simultaneous validation' => ['validate', 'validate', false, 'result_revision_conflict'],
            'validated state versus capture' => ['validate', 'capture', false, 'result_revision_conflict'],
            'rectification versus validation' => ['rectify', 'validate', false, 'result_revision_conflict'],
            'rectification versus capture' => ['rectify', 'capture', false, 'result_revision_conflict'],
            'duplicate rectification' => ['rectify', 'rectify', false, 'result_revision_conflict'],
            'professional revocation' => ['revoke', 'validate', false, 'forbidden'],
            'policy change' => ['policy', 'validate', false, 'forbidden'],
            'cancellation' => ['cancel', 'validate', false, 'result_validation_unavailable'],
            'user deactivation' => ['inactive', 'validate', false, 'forbidden'],
            'membership revocation' => ['membership', 'validate', false, 'forbidden'],
            'permission revocation' => ['permission', 'validate', false, 'forbidden'],
            'validation rollback' => ['validate', 'validate', true, 'ok'],
            'policy rollback' => ['policy', 'validate', true, 'ok'],
        ];
    }
}
