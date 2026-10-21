<?php

namespace Tests\Feature\ClinicalExams;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalExamService;
use App\Models\GlobalExamTemplate;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExamVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class ClinicalExamConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function context(): array
    {
        $lab = Laboratory::factory()->create();
        $actor = User::factory()->create();
        $area = LaboratoryArea::factory()->for($lab)->create(['status' => 'active']);
        $configuration = ['schema_version' => 1, 'code' => 'RACE', 'name' => 'Fixture', 'sample_mode' => 'none', 'parameters' => [['code' => 'p', 'name' => 'P', 'type' => 'text']]];

        return [$lab, $actor, ['code' => 'RACE', 'name' => 'Fixture', 'laboratory_area_id' => $area->id, 'configuration' => $configuration]];
    }

    public function test_concurrent_code_creation_has_one_winner_and_one_specific_conflict(): void
    {
        [$lab, $actor, $input] = $this->context();
        $results = $this->race([
            fn () => app(ClinicalExamService::class)->create($lab, $actor, $input),
            fn () => app(ClinicalExamService::class)->create($lab, $actor, $input),
        ]);
        sort($results);
        $this->assertSame(['exam_code_conflict', 'ok'], $results);
        $this->assertDatabaseCount('laboratory_exams', 1);
        $this->assertDatabaseCount('laboratory_exam_versions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_concurrent_template_import_has_one_copy_and_one_specific_conflict(): void
    {
        [$lab, $actor, $input] = $this->context();
        $template = GlobalExamTemplate::create(['key' => 'RACE-FIXTURE', 'name' => 'Fixture', 'configuration' => $input['configuration']]);
        $other = $input;
        $other['code'] = 'SECOND';
        $results = $this->race([
            fn () => app(ClinicalExamService::class)->create($lab, $actor, $input, $template),
            fn () => app(ClinicalExamService::class)->create($lab, $actor, $other, $template),
        ]);
        sort($results);
        $this->assertSame(['ok', 'template_already_imported'], $results);
        $this->assertDatabaseCount('laboratory_exam_clinical_states', 1);
        $this->assertDatabaseCount('laboratory_exam_versions', 1);
    }

    public function test_competing_publications_have_one_winner_and_preserve_losing_draft(): void
    {
        [$lab, $actor, $input] = $this->context();
        $service = app(ClinicalExamService::class);
        $first = $service->create($lab, $actor, $input);
        $second = $service->fork($lab, $actor, $first->laboratory_exam_id, null);
        $second = $service->update($lab, $actor, $first->laboratory_exam_id, $second->id, 1, $input['configuration']);
        $results = $this->race([
            fn () => app(ClinicalExamService::class)->publish($lab, $actor, $first->laboratory_exam_id, $first->id, $first->revision),
            fn () => app(ClinicalExamService::class)->publish($lab, $actor, $second->laboratory_exam_id, $second->id, $second->revision),
        ]);
        sort($results);
        $this->assertSame(['ok', 'publication_conflict'], $results);
        $this->assertSame(1, LaboratoryExamVersion::where('status', 'published')->count());
        $this->assertSame(1, LaboratoryExamVersion::where('status', 'draft')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'laboratory_exam.published')->count());
        $state = $service->state($lab, $first->laboratory_exam_id);
        $this->assertSame('published', LaboratoryExamVersion::findOrFail($state->current_version_id)->status);
    }

    public function test_concurrent_edits_detect_revision_loss(): void
    {
        [$lab, $actor, $input] = $this->context();
        $draft = app(ClinicalExamService::class)->create($lab, $actor, $input);
        $results = $this->race([
            fn () => app(ClinicalExamService::class)->update($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1, ['schema_version' => 1, 'name' => 'First']),
            fn () => app(ClinicalExamService::class)->update($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1, ['schema_version' => 1, 'name' => 'Second']),
        ]);
        sort($results);
        $this->assertSame(['ok', 'revision_conflict'], $results);
        $this->assertSame(2, $draft->refresh()->revision);
        $this->assertContains($draft->configuration['name'], ['First', 'Second']);
    }

    /** Fork workers each reconnect to real PostgreSQL. No transactions shared across processes. */
    private function race(array $jobs): array
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $directory = sys_get_temp_dir().'/clinical-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach ($jobs as $index => $job) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    DB::reconnect();
                    DB::statement("SET statement_timeout = '15s'");
                    try {
                        $job();
                        $result = 'ok';
                    } catch (ClinicalConflict $e) {
                        $result = $e->errorCode;
                    } catch (Throwable $e) {
                        $result = 'error:'.$e::class.':'.$e->getMessage();
                    }
                    file_put_contents($directory.'/'.$index, $result);
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            touch($directory.'/go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            return array_map(fn ($index) => file_get_contents($directory.'/'.$index), array_keys($jobs));
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
