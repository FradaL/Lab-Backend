<?php

namespace Tests\Feature;

use App\ClinicalExams\ClinicalConfigurationValidator;
use App\ClinicalExams\ClinicalExamService;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryExamVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\HematologyExamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class HematologyExamSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    private function basicData(): Laboratory
    {
        $this->seed(DatabaseSeeder::class);

        return Laboratory::where('nit', '000000000001')->firstOrFail();
    }

    public function test_optional_seed_publishes_the_image_parameters_without_results_or_inferred_references(): void
    {
        $lab = $this->basicData();
        $this->assertDatabaseCount('laboratory_exams', 0);
        $other = Laboratory::factory()->create();
        setPermissionsTeamId($other->id);

        $this->seed(HematologyExamSeeder::class);

        $this->assertSame($other->id, getPermissionsTeamId());
        $exam = LaboratoryExam::sole();
        $this->assertSame($lab->id, $exam->laboratory_id);
        $this->assertSame('HEM001', $exam->code);
        $this->assertSame('Hematología completa', $exam->name);
        $this->assertSame('HEM', $exam->laboratoryArea->code);
        $this->assertSame('Sangre', $exam->sampleType->name);
        $this->assertSame('active', $exam->status);
        $version = LaboratoryExamVersion::sole();
        $this->assertSame('published', $version->status);
        $configuration = app(ClinicalExamService::class)->orderConfiguration($lab, $exam->id)['configuration'];
        $this->assertSame([], app(ClinicalConfigurationValidator::class)->errors($configuration, $lab, true));
        $this->assertStringContainsString('pendientes de revisión', $configuration['description']);
        $this->assertSame('Sangre', $configuration['sample_requirements'][0]['sample_type_name']);
        $parameters = array_column($configuration['parameters'], null, 'code');
        $this->assertCount(15, $parameters);
        $this->assertSame(['11', '18'], [$parameters['HGB']['references'][0]['min'], $parameters['HGB']['references'][0]['max']]);
        $this->assertSame(['150000', '450000'], [$parameters['PLT']['references'][0]['min'], $parameters['PLT']['references'][0]['max']]);
        $this->assertSame(['0.10', '1.0'], [$parameters['PCT']['references'][0]['min'], $parameters['PCT']['references'][0]['max']]);
        foreach (['NEUT', 'LYMPH', 'MONO', 'RBC'] as $code) {
            $this->assertSame([], $parameters[$code]['references']);
        }
        $this->assertSame(11, array_sum(array_map(fn ($parameter) => count($parameter['references']), $parameters)));
        foreach (['laboratory_orders', 'laboratory_order_results', 'laboratory_report_issuances', 'price_lists', 'price_list_exams'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_repeating_the_seed_preserves_manual_clinical_versions_and_inactive_status(): void
    {
        $lab = $this->basicData();
        $this->seed(HematologyExamSeeder::class);
        $exam = LaboratoryExam::sole();
        $actor = User::where('email', 'admin@donqerlab.test')->firstOrFail();
        $clinical = app(ClinicalExamService::class);
        $original = $clinical->current($lab, $exam->id);
        $draft = $clinical->fork($lab, $actor, $exam->id, $original->id);
        $configuration = $draft->configuration;
        $configuration['parameters'][0]['references'][0]['min'] = '12';
        $draft = $clinical->update($lab, $actor, $exam->id, $draft->id, $draft->revision, $configuration);
        $manual = $clinical->publish($lab, $actor, $exam->id, $draft->id, $draft->revision);
        $exam->update(['status' => 'inactive', 'name' => 'Hematología editada manualmente']);
        $audits = AuditLog::count();

        $this->seed(HematologyExamSeeder::class);

        $this->assertDatabaseCount('laboratory_exams', 1);
        $this->assertDatabaseCount('laboratory_exam_versions', 2);
        $this->assertSame($manual->id, $clinical->current($lab, $exam->id)->id);
        $this->assertSame('12', $clinical->current($lab, $exam->id)->configuration['parameters'][0]['references'][0]['min']);
        $this->assertSame('inactive', $exam->fresh()->status);
        $this->assertSame('Hematología editada manualmente', $exam->fresh()->name);
        $this->assertSame($audits, AuditLog::count());
    }

    public function test_existing_unregistered_exam_with_the_same_code_is_not_overwritten(): void
    {
        $lab = $this->basicData();
        $existing = LaboratoryExam::factory()->for($lab)->create(['code' => 'HEM001', 'name' => 'Catálogo existente']);
        try {
            $this->seed(HematologyExamSeeder::class);
            $this->fail('Existing catalog entries must not be claimed by the demo seeder.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Unregistered preexisting record', $error->getMessage());
        }
        $this->assertSame('Catálogo existente', $existing->fresh()->name);
        $this->assertDatabaseCount('laboratory_exams', 1);
        $this->assertDatabaseCount('laboratory_exam_versions', 0);
    }

    public function test_publication_audit_failure_rolls_back_the_exam_and_provenance(): void
    {
        $this->basicData();
        $records = DB::table('demo_dataset_records')->count();
        $audits = AuditLog::count();
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->event === 'laboratory_exam.published') {
                throw new RuntimeException('Synthetic publication audit failure.');
            }
        });
        try {
            $this->seed(HematologyExamSeeder::class);
            $this->fail('Audit failure must roll back the exam.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic publication audit failure.', $error->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('laboratory_exams', 0);
        $this->assertDatabaseCount('laboratory_exam_versions', 0);
        $this->assertSame($records, DB::table('demo_dataset_records')->count());
        $this->assertSame($audits, AuditLog::count());
    }

    public function test_optional_seed_refuses_production(): void
    {
        $this->basicData();
        $previous = app()->environment();
        app()->instance('env', 'production');
        try {
            (new HematologyExamSeeder)->run();
            $this->fail('Demo configuration must refuse production.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseCount('laboratory_exams', 0);
    }
}
