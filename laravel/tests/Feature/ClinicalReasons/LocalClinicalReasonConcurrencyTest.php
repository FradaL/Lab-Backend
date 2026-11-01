<?php

namespace Tests\Feature\ClinicalReasons;

use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\LocalClinicalReasonManager;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\LocalClinicalReasonReview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\Support\StructuredClinicalReasonFixture;
use Tests\TestCase;
use Throwable;

class LocalClinicalReasonConcurrencyTest extends TestCase
{
    use ClinicalResultScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_concurrent_local_administration_and_clinical_commands_preserve_original_context(string $writer, string $reader, string $expected): void
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        $this->assignLaboratoryRole($actor, $lab, 'owner');
        StructuredClinicalReasonFixture::medicalEvidence($lab, $actor);
        $manager = app(LocalClinicalReasonManager::class);
        $definition = ['name' => 'FICTICIO: local', 'description' => 'FICTICIO', 'classification' => 'terminal_candidate', 'evidence_requirements' => ['schema_version' => 1, 'requirements' => ['medical_instruction']], 'is_active' => true, 'change_reason' => 'FICTICIO'];
        $data = $manager->create($lab, $actor, ['code' => 'ficticio', 'definition' => $definition])['data'];
        $reference = ['source' => 'local', 'reason_id' => $data['reason']->id, 'version_id' => $data['version']->id];
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve', 'clinical_reasons.review_impact']);
        $service = app(ClinicalResolutionService::class);
        $request = null;
        if (! in_array('request', [$writer, $reader], true) && ! in_array('create', [$writer, $reader], true) && ! in_array('publish', [$writer, $reader], true)) {
            $request = $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO']);
        }
        if (array_intersect(['favorable', 'non_favorable'], [$writer, $reader]) !== []) {
            $manager->activation($lab, $actor, $reference['reason_id'], ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO']);
        }
        $other = null;
        if ($reader === 'other_lab') {
            [$otherLab, $otherActor] = $this->scenario(false);
            $this->assignLaboratoryRole($otherActor, $otherLab, 'owner');
            $otherData = $manager->create($otherLab, $otherActor, ['code' => 'ficticio', 'definition' => $definition])['data'];
            $other = [$otherLab, $otherActor, $otherData];
            setPermissionsTeamId($lab->id);
        }
        $review = LocalClinicalReasonReview::first();
        $revision = $order->fresh()->clinical_revision;
        $operation = function (string $op) use ($lab, $actor, $order, $line, $reference, $approver, $service, $manager, $definition, $request, $review, $revision, $other): void {
            setPermissionsTeamId($lab->id);
            match ($op) {
                'create' => $manager->create($lab, $actor, ['code' => 'concurrent', 'definition' => $definition]),
                'publish' => $manager->publish($lab, $actor, $reference['reason_id'], ['expected_number' => 1, 'definition' => $definition]),
                'deactivate' => $manager->activation($lab, $actor, $reference['reason_id'], ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO']),
                'reclassify' => $manager->publish($lab, $actor, $reference['reason_id'], ['expected_number' => 1, 'definition' => array_replace($definition, ['classification' => 'incidence_only'])]),
                'other_lab' => $this->publishOtherLaboratory($other, $definition),
                'request' => $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO']),
                'approve' => $service->decideException($lab, $approver, $order->id, $line->id, $request['exception']['id'], true, ['clinical_revision' => $revision, 'request_revision' => 1]),
                'favorable', 'non_favorable' => $manager->resolve($lab, $approver, $reference['reason_id'], $review->id, ['expected_revision' => 0, 'outcome' => $op, 'assessment' => 'FICTICIO: evaluación concurrente', 'justification' => 'FICTICIO']),
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
            $this->assertDatabaseCount('clinical_exception_requests', $request || in_array('request', [$writer, $reader]) && ($writer === 'request' || $expected === 'ok') ? 1 : 0);
            $this->assertDatabaseCount('clinical_line_resolutions', $writer === 'approve' || $reader === 'approve' && $expected === 'ok' ? 1 : 0);
            $this->assertDatabaseCount('laboratory_clinical_reason_versions', $other !== null ? 4 : ($writer === 'approve' && $reader === 'approve' ? 1 : 2));
            if ($other !== null) {
                $this->assertSame(2, LaboratoryClinicalReasonVersion::forLaboratory($lab)->count());
                $this->assertSame(2, LaboratoryClinicalReasonVersion::forLaboratory($other[0])->count());
            }
            $context = ClinicalExceptionLocalReasonReference::first();
            if ($context !== null) {
                $this->assertSame($reference['version_id'], $context->version_id);
            }
            $this->assertDatabaseCount('clinical_exception_reason_references', 0);
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

    private function publishOtherLaboratory(array $other, array $definition): void
    {
        [$lab, $actor, $data] = $other;
        setPermissionsTeamId($lab->id);
        app(LocalClinicalReasonManager::class)->publish($lab, $actor, $data['reason']->id, ['expected_number' => 1, 'definition' => $definition]);
    }

    public static function races(): array
    {
        return [
            'duplicate code' => ['create', 'create', 'local_clinical_reason_code_conflict'],
            'duplicate version' => ['publish', 'publish', 'clinical_reason_version_conflict'],
            'publish before activation' => ['publish', 'deactivate', 'clinical_reason_version_conflict'],
            'deactivation before request' => ['deactivate', 'request', 'clinical_reason_selection_conflict'],
            'request before deactivation' => ['request', 'deactivate', 'ok'],
            'deactivation before approval' => ['deactivate', 'approve', 'clinical_reason_use_blocked'],
            'approval before deactivation' => ['approve', 'deactivate', 'ok'],
            'classification before approval' => ['reclassify', 'approve', 'clinical_reason_use_blocked'],
            'favorable before approval' => ['favorable', 'approve', 'ok'],
            'publication before request' => ['publish', 'request', 'clinical_reason_selection_conflict'],
            'request before publication' => ['request', 'publish', 'ok'],
            'different laboratory publications' => ['publish', 'other_lab', 'ok'],
            'two local approvals' => ['approve', 'approve', 'clinical_revision_conflict'],
            'non favorable before local approval' => ['non_favorable', 'approve', 'clinical_reason_use_blocked'],
            'duplicate assessment' => ['favorable', 'favorable', 'clinical_reason_review_revision_conflict'],
        ];
    }
}
