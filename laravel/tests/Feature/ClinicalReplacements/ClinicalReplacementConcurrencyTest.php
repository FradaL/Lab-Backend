<?php

namespace Tests\Feature\ClinicalReplacements;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalReplacements\ClinicalReplacementService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class ClinicalReplacementConcurrencyTest extends TestCase
{
    use DatabaseMigrations, ReplacementScenario;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_real_connections_serialize_evaluations_and_dependencies(string $writer, string $reader, string $expected): void
    {
        [$lab, $actor, $order, $line, $requirement, $branch, $type] = $this->replacementScenario();
        $samples = app(LaboratorySampleService::class);
        $sample = $samples->register($lab, $actor, $order->id, 'receiveNew', ['idempotency_key' => 'race', 'sample_type_id' => $type->id,
            'provenance' => 'patient_provided', 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id])['data']['id'];
        $service = app(ClinicalReplacementService::class);
        $i = $service->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement, 0, $sample));
        $e = $service->recordEvidence($lab, $actor, $order->id, $line->id, $this->evidenceBody(2));
        $assessment = $this->assessmentBody(3, $i['intention_id'], $e['evidence_id']);
        $operation = function (string $name) use ($lab, $actor, $order, $line, $assessment, $sample): void {
            $service = app(ClinicalReplacementService::class);
            match ($name) {
                'assess' => $service->assess($lab, $actor, $order->id, $line->id, $assessment),
                'evidence' => $service->recordEvidence($lab, $actor, $order->id, $line->id, $this->evidenceBody(3)),
                'sample' => app(LaboratorySampleService::class)->evaluate($lab, $actor, $order->id, $sample, 'accepted'),
            };
        };
        $directory = sys_get_temp_dir().'/exc03-race-'.bin2hex(random_bytes(8));
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
            $this->assertDatabaseCount('clinical_viability_assessments', $writer === 'assess' ? 1 : 0);
            $this->assertDatabaseCount('clinical_line_resolutions', 0);
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
            ['assess', 'assess', 'replacement_revision_conflict'],
            ['evidence', 'assess', 'replacement_revision_conflict'],
            ['assess', 'evidence', 'replacement_revision_conflict'],
            ['sample', 'assess', 'viability_dependencies_conflict'],
        ];
    }
}
