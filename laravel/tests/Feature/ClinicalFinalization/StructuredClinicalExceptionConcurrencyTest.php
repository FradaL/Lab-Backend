<?php

namespace Tests\Feature\ClinicalFinalization;

use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\ClinicalExams\GlobalClinicalReasonImpactManager;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\Models\ClinicalExceptionReasonReference;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;
use Throwable;

class StructuredClinicalExceptionConcurrencyTest extends TestCase
{
    use ClinicalResultScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_real_clinical_commands_and_catalog_writes_share_one_transactional_order(string $writer, string $reader, string $expected): void
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        $reference = $this->exceptionReference($lab, $actor);
        $version = GlobalClinicalReasonVersion::findOrFail($reference['version_id']);
        $operator = User::findOrFail($version->created_by);
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve', 'clinical_reasons.review_impact']);
        $service = app(ClinicalResolutionService::class);
        $request = null;
        if (! in_array('request', [$writer, $reader], true)) {
            $request = $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO']);
        }
        $declare = fn () => app(GlobalClinicalReasonImpactManager::class)->declare($operator, $reference['reason_id'], $reference['version_id'], ['expected_revision' => 1, 'classification' => 'restrictive', 'justification' => 'FICTICIO: restricción concurrente']);
        if (in_array('favorable', [$writer, $reader], true) || in_array('non_favorable', [$writer, $reader], true)) {
            $declare();
        }
        $review = ClinicalReasonImpactReview::first();
        $next = null;
        if ($writer === 'adoption') {
            $this->assignLaboratoryRole($actor, $lab, 'owner');
            $next = app(ClinicalReasonVersionStore::class)->appendGlobal($reference['reason_id'], $operator, 1, $version->only(['name', 'description', 'classification', 'evidence_requirements', 'is_active', 'change_reason']));
            app(GlobalClinicalReasonImpactManager::class)->declare($operator, $reference['reason_id'], $next->id, ['expected_revision' => 0, 'classification' => 'non_restrictive', 'justification' => 'FICTICIO']);
        }
        $other = null;
        if ($reader === 'other_lab') {
            [$otherLab, $otherActor, $otherOrder, $otherLine] = $this->scenario(false);
            $otherReference = $this->exceptionReference($otherLab, $otherActor);
            $other = [$otherLab, $otherActor, $otherOrder, $otherLine, $otherReference];
            setPermissionsTeamId($lab->id);
        }
        $revision = $order->fresh()->clinical_revision;
        $operation = function (string $op) use ($lab, $actor, $order, $line, $reference, $approver, $service, $request, $declare, $review, $next, $revision, $other): void {
            setPermissionsTeamId($lab->id);
            match ($op) {
                'restrictive' => $declare(),
                'request' => $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO']),
                'approve' => $service->decideException($lab, $approver, $order->id, $line->id, $request['exception']['id'], true, ['clinical_revision' => $revision, 'request_revision' => 1]),
                'adoption' => app(ClinicalReasonAdoptionManager::class)->update($lab, $actor, $reference['adoption_id'], ['version_id' => $next->id, 'expected_revision' => 1, 'justification' => 'FICTICIO: adopción explícita concurrente']),
                'favorable', 'non_favorable' => app(ClinicalReasonAdoptionManager::class)->resolve($lab, $approver, $reference['adoption_id'], $review->id, ['expected_revision' => 0, 'outcome' => $op, 'assessment' => 'FICTICIO: evaluación concurrente', 'justification' => 'FICTICIO']),
                'permission' => $approver->revokePermissionTo('orders.clinical_exceptions.approve'),
                'other_lab' => $this->requestInOtherLaboratory($other),
            };
        };
        $dir = sys_get_temp_dir().'/structured-clinical-race-'.bin2hex(random_bytes(8));
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
                $operation($reader);
                $result = 'ok';
            } catch (ClinicalConflict $e) {
                $result = $e->errorCode;
            } catch (HttpExceptionInterface $e) {
                $result = (string) $e->getStatusCode();
            } catch (AuthorizationException $e) {
                $result = '403';
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
            $operation($writer);
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
            $this->assertTrue($blocked, 'The independent clinical/catalog transaction must really wait in PostgreSQL.');
            DB::commit();
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame($expected, file_get_contents($dir.'/result'));
            $this->assertDatabaseCount('clinical_exception_requests', ($request || $writer === 'request' ? 1 : 0) + ($reader === 'other_lab' ? 1 : 0));
            $this->assertDatabaseCount('clinical_line_resolutions', $writer === 'approve' || $reader === 'approve' && $expected === 'ok' ? 1 : 0);
            if ($writer === 'adoption') {
                $this->assertSame($reference['version_id'], ClinicalExceptionReasonReference::sole()->version_id);
            }
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
            setPermissionsTeamId(null);
        }
    }

    private function requestInOtherLaboratory(array $other): array
    {
        [$lab, $actor, $order, $line, $reference] = $other;
        setPermissionsTeamId($lab->id);

        return app(ClinicalResolutionService::class)->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO: motivo independiente en otro laboratorio']);
    }

    public static function races(): array
    {
        return [
            'restriction before request' => ['restrictive', 'request', 'clinical_reason_use_blocked'],
            'request before restriction' => ['request', 'restrictive', 'ok'],
            'restriction before approval' => ['restrictive', 'approve', 'clinical_reason_use_blocked'],
            'approval before restriction' => ['approve', 'restrictive', 'ok'],
            'adoption before approval' => ['adoption', 'approve', 'ok'],
            'favorable before approval' => ['favorable', 'approve', 'ok'],
            'non favorable before approval' => ['non_favorable', 'approve', 'clinical_reason_use_blocked'],
            'revocation before approval' => ['permission', 'approve', '403'],
            'independent laboratories' => ['restrictive', 'other_lab', 'ok'],
        ];
    }
}
