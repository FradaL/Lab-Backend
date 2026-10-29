<?php

namespace Tests\Feature\ClinicalExams;

use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalConfigurationValidator;
use App\ClinicalExams\ClinicalExamService;
use App\Http\Controllers\Api\V1\ClinicalExamController;
use App\Models\AuditLog;
use App\Models\GlobalExamTemplate;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryExamVersion;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithPermissionTeams;
use Tests\TestCase;

class ClinicalExamTest extends TestCase
{
    use InteractsWithPermissionTeams;
    use RefreshDatabase;

    private Laboratory $lab;

    private LaboratoryArea $area;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lab = Laboratory::factory()->create();
        Subscription::factory()->for($this->lab)->create(['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->actor = $this->createLaboratoryMember($this->lab);
        $this->area = LaboratoryArea::factory()->for($this->lab)->create(['status' => 'active']);
        $this->assignDirectLaboratoryPermissions($this->actor, $this->lab, RbacCatalog::PERMISSIONS);
        $this->actingAs($this->actor)->withHeader('X-Laboratory-ID', (string) $this->lab->id);
    }

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    private function payload(array $configuration = []): array
    {
        return ['code' => 'OWN', 'name' => 'Local exam', 'laboratory_area_id' => $this->area->id, 'configuration' => $configuration];
    }

    private function configuration(): array
    {
        return ['schema_version' => 1, 'code' => 'OWN', 'name' => 'Local exam', 'sample_mode' => 'none', 'parameters' => [
            ['code' => 'NUM', 'name' => 'Numeric', 'type' => 'numeric', 'unit_required' => false],
            ['code' => 'TXT', 'name' => 'Text', 'type' => 'text', 'text_max_length' => 1000],
            ['code' => 'QUAL', 'name' => 'Qualitative', 'type' => 'qualitative', 'options' => [['code' => 'a', 'label' => 'A'], ['code' => 'b', 'label' => 'B']]],
            ['code' => 'OPT', 'name' => 'Options', 'type' => 'predefined_options', 'options' => [['code' => 'x', 'label' => 'X']]],
        ]];
    }

    private function draft(?array $configuration = null): LaboratoryExamVersion
    {
        return app(ClinicalExamService::class)->create($this->lab, $this->actor, $this->payload($configuration ?? $this->configuration()));
    }

    private function url(LaboratoryExamVersion $version): string
    {
        return "/api/v1/laboratory-exams/{$version->laboratory_exam_id}/versions/{$version->id}";
    }

    public function test_incomplete_draft_creation_null_sample_legacy_reads_and_publication_errors(): void
    {
        $data = $this->postJson('/api/v1/laboratory-exams/drafts', $this->payload())->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.revision', 1)->json('data');
        $this->getJson('/api/v1/laboratory-exams/'.$data['laboratory_exam_id'])->assertOk()->assertJsonPath('data.sample_type', null)->assertJsonPath('data.status', 'inactive');
        $url = "/api/v1/laboratory-exams/{$data['laboratory_exam_id']}/versions/{$data['id']}";
        $this->postJson($url.'/validate-publication')->assertOk()->assertJsonPath('data.publishable', false);
        $this->postJson($url.'/publish', ['revision' => 1])->assertUnprocessable()->assertJsonPath('code', 'publication_invalid');
        $this->patchJson("/api/v1/laboratory-exams/{$data['laboratory_exam_id']}/status", ['status' => 'active'])->assertConflict()->assertJsonPath('code', 'clinical_exam_unavailable');
        $this->postJson('/api/v1/laboratory-exams', ['code' => 'OLD', 'name' => 'Old API', 'laboratory_area_id' => $this->area->id, 'sample_type_id' => null])->assertUnprocessable()->assertJsonValidationErrors('sample_type_id');
    }

    public function test_all_types_publication_fork_and_current_contract_preserve_history(): void
    {
        $draft = $this->draft();
        $this->getJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/order-configuration")->assertConflict();
        $published = $this->postJson($this->url($draft).'/publish', ['revision' => 1])->assertOk()->assertJsonPath('data.status', 'published')->json('data');
        $this->patchJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/status", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/laboratory-exams/active')->assertOk()->assertJsonPath('data.0.sample_type', null);
        $contract = $this->getJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/order-configuration")->assertOk()->assertJsonPath('data.clinical_version_id', $draft->id)->json('data');
        $this->assertSame($published['configuration_hash'], $contract['configuration_hash']);
        $fork = $this->postJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/versions", ['source_version_id' => $draft->id])->assertCreated()->json('data');
        $newConfiguration = $this->configuration();
        $newConfiguration['parameters'][0]['name'] = 'New name';
        $url = "/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/versions/{$fork['id']}";
        $this->putJson($url, ['revision' => 1, 'configuration' => $newConfiguration])->assertOk()->assertJsonPath('data.revision', 2);
        $this->putJson($url, ['revision' => 1, 'configuration' => $newConfiguration])->assertConflict()->assertJsonPath('code', 'revision_conflict');
        $this->postJson($url.'/publish', ['revision' => 2])->assertOk();
        $this->getJson($this->url($draft))->assertOk()->assertJsonPath('data.configuration.parameters.0.name', 'Numeric');
        $this->getJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/versions/current")->assertOk()->assertJsonPath('data.id', $fork['id']);
        $this->putJson($this->url($draft), ['revision' => 2, 'configuration' => $newConfiguration])->assertConflict()->assertJsonPath('code', 'version_not_editable');
        $this->postJson($this->url($draft).'/publish', ['revision' => 2])->assertConflict();
        $this->patchJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/status", ['status' => 'inactive'])->assertOk();
        $this->getJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/order-configuration")->assertConflict();
        $this->getJson($this->url($draft))->assertOk();
        $this->patchJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/status", ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'laboratory_exam.published', 'laboratory_id' => $this->lab->id]);
    }

    public function test_published_document_including_every_descendant_is_immutable_in_postgresql(): void
    {
        $draft = $this->draft();
        app(ClinicalExamService::class)->publish($this->lab, $this->actor, $draft->laboratory_exam_id, $draft->id, 1);
        foreach (['parameters', 'options', 'references', 'sample_requirements', 'root', 'delete', 'ownership', 'unpublish'] as $mutation) {
            try {
                DB::transaction(function () use ($draft, $mutation) {
                    $query = DB::table('laboratory_exam_versions')->where('id', $draft->id);
                    if ($mutation === 'delete') {
                        $query->delete();
                    } elseif ($mutation === 'unpublish') {
                        $query->update(['status' => 'draft']);
                    } elseif ($mutation === 'ownership') {
                        $query->update(['laboratory_exam_id' => 999]);
                    } else {
                        $configuration = $draft->refresh()->configuration;
                        match ($mutation) {
                            'parameters' => $configuration['parameters'][0]['name'] = 'Changed',
                            'options' => $configuration['parameters'][2]['options'][0]['label'] = 'Changed',
                            'references' => $configuration['parameters'][0]['references'] = [['key' => 'new', 'priority' => 0, 'min' => 0]],
                            'sample_requirements' => $configuration['sample_requirements'] = [['key' => 'new']],
                            'root' => $configuration['name'] = 'Changed',
                        };
                        $query->update(['configuration' => json_encode($configuration)]);
                    }
                });
                $this->fail("Published $mutation changed.");
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        $this->assertEquals($this->configuration(), $draft->refresh()->configuration);
    }

    private function historicalLine(LaboratoryExam $exam): LaboratoryOrderExam
    {
        $order = LaboratoryOrder::factory()->for($this->lab)->create();

        return LaboratoryOrderExam::create([
            'laboratory_id' => $this->lab->id, 'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id, 'price_list_id' => $order->price_list_id,
            'unit_price' => '25.00', 'exam_code' => $exam->code, 'exam_name' => $exam->name,
            'price_list_name' => $order->price_list_name,
        ]);
    }

    public function test_legacy_metadata_edit_does_not_change_published_snapshot_or_existing_order_line(): void
    {
        $legacy = LaboratoryExam::factory()->for($this->lab)->create(['code' => 'LEG', 'status' => 'active']);
        $composition = require database_path('migrations/2026_10_10_000002_add_order_clinical_composition.php');
        $physical = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $resultsMigration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $validationMigration = require database_path('migrations/2026_10_11_000002_add_clinical_result_validation.php');
        $finalizationMigration = require database_path('migrations/2026_10_11_000003_add_clinical_order_finalization.php');
        $reportMigration = require database_path('migrations/2026_10_11_000004_create_clinical_report_issuances.php');
        $deliveryMigration = require database_path('migrations/2026_10_11_000005_create_report_delivery_controls.php');
        $localReasons = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $structuredReasons = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $replacementEvidence = require database_path('migrations/2026_10_29_000001_add_clinical_replacement_evidence.php');
        $exceptionEvidence = require database_path('migrations/2026_10_29_150442_add_clinical_exception_evidence_reviews.php');
        $exceptionEvidence->down();
        $replacementEvidence->down();
        $localReasons->down();
        $structuredReasons->down();
        $deliveryMigration->down();
        $reportMigration->down();
        $finalizationMigration->down();
        $validationMigration->down();
        $resultsMigration->down();
        $physical->down();
        $composition->down();
        $line = $this->historicalLine($legacy);
        $composition->up();
        $physical->up();
        $resultsMigration->up();
        $validationMigration->up();
        $finalizationMigration->up();
        $reportMigration->up();
        $deliveryMigration->up();
        $structuredReasons->up();
        $localReasons->up();
        $replacementEvidence->up();
        $exceptionEvidence->up();
        $before = $line->refresh()->getRawOriginal();
        $version = app(ClinicalExamService::class)->fork($this->lab, $this->actor, $legacy->id, null);
        $this->assertSame('active', $legacy->refresh()->status);
        $configuration = $this->configuration();
        $configuration['code'] = 'LEG';
        $version = app(ClinicalExamService::class)->update($this->lab, $this->actor, $legacy->id, $version->id, 1, $configuration);
        app(ClinicalExamService::class)->publish($this->lab, $this->actor, $legacy->id, $version->id, 2);
        $this->patchJson("/api/v1/laboratory-exams/{$legacy->id}", ['name' => 'Changed catalog name'])->assertOk();
        $this->assertSame('Local exam', $version->refresh()->configuration['name']);
        $this->assertSame($before, $line->refresh()->getRawOriginal());
    }

    public function test_cross_tenant_versions_and_catalog_references_are_rejected(): void
    {
        $other = Laboratory::factory()->create();
        $exam = LaboratoryExam::factory()->for($other)->create();
        $this->getJson("/api/v1/laboratory-exams/{$exam->id}/versions")->assertNotFound();
        $draft = $this->draft();
        $this->getJson("/api/v1/laboratory-exams/{$exam->id}/versions/{$draft->id}")->assertNotFound();
        $this->postJson("/api/v1/laboratory-exams/{$exam->id}/versions")->assertNotFound();
        $this->postJson('/api/v1/laboratory-exams/drafts', ['code' => 'OTHER', 'name' => 'Other', 'laboratory_area_id' => $exam->laboratory_area_id])->assertUnprocessable();
        $config = $this->configuration();
        $config['sample_mode'] = 'physical';
        $config['sample_requirements'] = [['key' => 'blood', 'sample_type_id' => $exam->sample_type_id]];
        $this->putJson($this->url($draft), ['revision' => 1, 'configuration' => $config])->assertUnprocessable();
        try {
            DB::transaction(fn () => DB::table('laboratory_exam_versions')->insert(['laboratory_id' => $this->lab->id, 'laboratory_exam_id' => $exam->id, 'number' => 1, 'configuration' => '{}']));
            $this->fail('Cross tenant database write succeeded.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }
    }

    public static function permissionOperations(): array
    {
        return [
            ['GET', 'versions', 'laboratory_exams.view'], ['POST', 'versions', 'laboratory_exams.create_draft'],
            ['PUT', 'version', 'laboratory_exams.edit_draft'], ['POST', 'publish', 'laboratory_exams.publish'],
            ['POST', 'validate', 'laboratory_exams.publish'], ['POST', 'create', 'laboratory_exams.create_draft'],
            ['POST', 'import', 'laboratory_exams.import_template'], ['PATCH', 'status', 'laboratory_exams.change_status'],
            ['GET', 'contract', 'laboratory_exams.view'], ['GET', 'templates', 'laboratory_exams.view'],
        ];
    }

    #[DataProvider('permissionOperations')]
    public function test_permission_is_independent_for_each_operation(string $method, string $operation, string $permission): void
    {
        $draft = $this->draft();
        $user = $this->createLaboratoryMember($this->lab);
        $this->assignDirectLaboratoryPermissions($user, $this->lab, array_values(array_diff(RbacCatalog::PERMISSIONS, [$permission])));
        $url = match ($operation) {
            'create' => '/api/v1/laboratory-exams/drafts', 'import' => '/api/v1/laboratory-exams/import-template',
            'templates' => '/api/v1/global-exam-templates', 'version' => $this->url($draft),
            'publish' => $this->url($draft).'/publish', 'validate' => $this->url($draft).'/validate-publication',
            'contract' => "/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/order-configuration",
            default => "/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/$operation",
        };
        $this->actingAs($user)->json($method, $url, [])->assertForbidden();
    }

    public function test_template_import_copies_without_sync_and_duplicate_conflicts_are_specific(): void
    {
        $template = GlobalExamTemplate::create(['key' => 'TEST-FIXTURE', 'name' => 'Fixture only', 'configuration' => $this->configuration()]);
        $input = ['template_id' => $template->id, 'code' => 'COPY', 'name' => 'Copy', 'laboratory_area_id' => $this->area->id];
        $copy = $this->postJson('/api/v1/laboratory-exams/import-template', $input)->assertCreated()->assertJsonPath('data.configuration.code', 'COPY')->json('data');
        $input['code'] = 'DIFFERENT';
        $this->postJson('/api/v1/laboratory-exams/import-template', $input)->assertConflict()->assertJsonPath('code', 'template_already_imported');
        $config = $this->configuration();
        $config['name'] = 'Independent';
        $this->putJson("/api/v1/laboratory-exams/{$copy['laboratory_exam_id']}/versions/{$copy['id']}", ['revision' => 1, 'configuration' => $config])->assertOk();
        $this->assertSame('Local exam', $template->refresh()->configuration['name']);
        $this->postJson('/api/v1/laboratory-exams/drafts', ['code' => 'COPY', 'name' => 'Duplicate', 'laboratory_area_id' => $this->area->id])->assertConflict()->assertJsonPath('code', 'exam_code_conflict');
        $other = Laboratory::factory()->create();
        $area = LaboratoryArea::factory()->for($other)->create(['status' => 'active']);
        app(ClinicalExamService::class)->create($other, $this->actor, ['code' => 'COPY', 'name' => 'Cross-lab allowed', 'laboratory_area_id' => $area->id], $template);
        $this->assertDatabaseCount('laboratory_exam_clinical_states', 2);
    }

    public function test_physical_samples_require_mapping_and_freeze_names_at_publication(): void
    {
        $sample = SampleType::factory()->for($this->lab)->create(['status' => 'active', 'name' => 'Original sample']);
        $config = $this->configuration();
        $config['sample_mode'] = 'physical';
        $config['sample_requirements'] = [['key' => 'main', 'sample_type_id' => null, 'volume' => 1.5, 'volume_unit' => 'fixture-unit']];
        $template = GlobalExamTemplate::create(['key' => 'PHYSICAL-FIXTURE', 'name' => 'Fixture', 'configuration' => $config]);
        $input = ['template_id' => $template->id, 'code' => 'PHYS', 'name' => 'Physical', 'laboratory_area_id' => $this->area->id, 'sample_type_map' => ['main' => $sample->id]];
        $draft = $this->postJson('/api/v1/laboratory-exams/import-template', $input)->assertCreated()->json('data');
        $url = "/api/v1/laboratory-exams/{$draft['laboratory_exam_id']}/versions/{$draft['id']}";
        $this->postJson($url.'/publish', ['revision' => 1])->assertOk()->assertJsonPath('data.configuration.sample_requirements.0.sample_type_name', 'Original sample');
        $sample->update(['name' => 'Changed sample', 'status' => 'inactive']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.configuration.sample_requirements.0.sample_type_name', 'Original sample');
    }

    public function test_publication_from_two_drafts_detects_stale_base(): void
    {
        $draft = $this->draft();
        $other = app(ClinicalExamService::class)->fork($this->lab, $this->actor, $draft->laboratory_exam_id, null);
        app(ClinicalExamService::class)->update($this->lab, $this->actor, $other->laboratory_exam_id, $other->id, 1, $this->configuration());
        $this->postJson($this->url($draft).'/publish', ['revision' => 1])->assertOk();
        $this->postJson($this->url($other).'/publish', ['revision' => 2])->assertConflict()->assertJsonPath('code', 'publication_conflict');
        $this->assertSame('draft', $other->refresh()->status);
    }

    public function test_transactional_audit_failure_rolls_back_create_edit_and_publication(): void
    {
        $service = app(ClinicalExamService::class);
        $draft = $this->draft();
        AuditLog::creating(static function (): void {
            throw new RuntimeException('Simulated audit failure');
        });
        foreach (['create', 'edit', 'publish'] as $operation) {
            try {
                match ($operation) {
                    'create' => $service->create($this->lab, $this->actor, ['code' => 'FAIL', 'name' => 'Fail', 'laboratory_area_id' => $this->area->id]),
                    'edit' => $service->update($this->lab, $this->actor, $draft->laboratory_exam_id, $draft->id, 1, ['schema_version' => 1]),
                    'publish' => $service->publish($this->lab, $this->actor, $draft->laboratory_exam_id, $draft->id, 1),
                };
                $this->fail('Audit failure not propagated.');
            } catch (RuntimeException $e) {
                $this->assertSame('Simulated audit failure', $e->getMessage());
            }
        }
        $this->assertDatabaseMissing('laboratory_exams', ['code' => 'FAIL']);
        $this->assertSame(1, $draft->refresh()->revision);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($service->state($this->lab, $draft->laboratory_exam_id)->current_version_id);
    }

    public function test_reference_priority_overlap_age_sex_conditions_and_type_consistency(): void
    {
        $validator = app(ClinicalConfigurationValidator::class);
        $config = $this->configuration();
        $reference = ['key' => 'default', 'priority' => 0, 'min' => 1, 'max' => 2];
        $config['parameters'][0]['references'] = [$reference, ['key' => 'specific', 'priority' => 1, 'min' => 3, 'max' => 4, 'conditions' => ['fixture_context' => 'yes']]];
        $this->assertSame([], $validator->errors($config, $this->lab, true));
        $config['parameters'][0]['references'][1]['priority'] = 0;
        $this->assertArrayHasKey('parameters.0.references.1', $validator->errors($config, $this->lab, true));
        $config['parameters'][0]['references'][0]['age_max_days'] = 10;
        $config['parameters'][0]['references'][1]['age_min_days'] = 11;
        $this->assertSame([], $validator->errors($config, $this->lab, true));
        $config['parameters'][0]['references'][1]['age_min_days'] = 10;
        $this->assertNotSame([], $validator->errors($config, $this->lab, true));
        $config['parameters'][0]['references'][0]['sex'] = 'fixture-a';
        $config['parameters'][0]['references'][1]['sex'] = 'fixture-b';
        $this->assertSame([], $validator->errors($config, $this->lab, true));
        unset($config['parameters'][0]['references'][0]['sex'], $config['parameters'][0]['references'][1]['sex']);
        $config['parameters'][0]['references'][0]['conditions'] = ['fixture_context' => 'no'];
        $this->assertSame([], $validator->errors($config, $this->lab, true));
        $config['parameters'][0]['references'][1]['min'] = 5;
        $this->assertNotSame([], $validator->errors($config, $this->lab, true));
    }

    public function test_saas_guards_and_permission_teams_protect_new_clinical_operations(): void
    {
        $other = Laboratory::factory()->create();
        Subscription::factory()->for($other)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->actor->laboratories()->attach($other, ['is_active' => true]);
        $area = LaboratoryArea::factory()->for($other)->create(['status' => 'active']);
        $this->withHeader('X-Laboratory-ID', (string) $other->id)->postJson('/api/v1/laboratory-exams/drafts', ['code' => 'OTHER', 'name' => 'Other', 'laboratory_area_id' => $area->id])->assertForbidden();
        $this->withHeader('X-Laboratory-ID', (string) $this->lab->id)->postJson('/api/v1/laboratory-exams/drafts', $this->payload())->assertCreated();
        $this->actor->laboratories()->updateExistingPivot($this->lab, ['is_active' => false]);
        $this->getJson('/api/v1/global-exam-templates')->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $this->actor->laboratories()->updateExistingPivot($this->lab, ['is_active' => true]);
        $this->lab->subscriptions()->delete();
        $this->getJson('/api/v1/global-exam-templates')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_draft_pointer_and_template_database_constraints(): void
    {
        $draft = $this->draft();
        try {
            DB::transaction(fn () => DB::table('laboratory_exam_clinical_states')->where('laboratory_exam_id', $draft->laboratory_exam_id)->update(['current_version_id' => $draft->id]));
            $this->fail('A draft became current.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $template = GlobalExamTemplate::create(['key' => 'IMMUTABLE-FIXTURE', 'name' => 'Fixture', 'configuration' => $this->configuration()]);
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($template, $operation) {
                    if ($operation === 'update') {
                        $template->update(['name' => 'Changed']);
                    } else {
                        $template->delete();
                    }
                });
                $this->fail('Template artifact changed.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_progressive_migration_preserves_legacy_exams_and_order_snapshots(): void
    {
        $exam = LaboratoryExam::factory()->for($this->lab)->create(['status' => 'inactive']);
        $composition = require database_path('migrations/2026_10_10_000002_add_order_clinical_composition.php');
        $physical = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $resultsMigration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $validationMigration = require database_path('migrations/2026_10_11_000002_add_clinical_result_validation.php');
        $finalizationMigration = require database_path('migrations/2026_10_11_000003_add_clinical_order_finalization.php');
        $reportMigration = require database_path('migrations/2026_10_11_000004_create_clinical_report_issuances.php');
        $deliveryMigration = require database_path('migrations/2026_10_11_000005_create_report_delivery_controls.php');
        $localReasons = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $structuredReasons = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $replacementEvidence = require database_path('migrations/2026_10_29_000001_add_clinical_replacement_evidence.php');
        $exceptionEvidence = require database_path('migrations/2026_10_29_150442_add_clinical_exception_evidence_reviews.php');
        $exceptionEvidence->down();
        $replacementEvidence->down();
        $localReasons->down();
        $structuredReasons->down();
        $deliveryMigration->down();
        $reportMigration->down();
        $finalizationMigration->down();
        $validationMigration->down();
        $resultsMigration->down();
        $physical->down();
        $composition->down();
        $line = $this->historicalLine($exam);
        $beforeExam = $exam->refresh()->getRawOriginal();
        $beforeLine = $line->refresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_10_000001_create_clinical_exam_versions.php');
        $migration->down();
        $migration->up();
        $this->assertSame($beforeExam, $exam->refresh()->getRawOriginal());
        $this->assertSame($beforeLine, $line->refresh()->getRawOriginal());
        $this->assertDatabaseCount('laboratory_exam_versions', 0);
        $this->assertDatabaseCount('laboratory_exam_clinical_states', 0);
        $composition->up();
        $physical->up();
        $resultsMigration->up();
        $validationMigration->up();
        $finalizationMigration->up();
        $reportMigration->up();
        $deliveryMigration->up();
        $structuredReasons->up();
        $localReasons->up();
        $replacementEvidence->up();
        $exceptionEvidence->up();
        $this->assertNull($line->refresh()->clinical_version_id);
        $this->getJson("/api/v1/laboratory-exams/{$exam->id}/versions/current")->assertOk()->assertJsonPath('data', null);
    }

    public function test_unknown_fields_nested_server_snapshots_and_missing_revision_are_rejected(): void
    {
        $this->postJson('/api/v1/laboratory-exams/drafts', $this->payload() + ['laboratory_id' => $this->lab->id])->assertUnprocessable();
        $draft = $this->draft();
        $this->putJson($this->url($draft), ['configuration' => $this->configuration()])->assertUnprocessable();
        $config = $this->configuration();
        $config['sample_requirements'] = [['key' => 'x', 'sample_type_name' => 'Injected']];
        $this->putJson($this->url($draft), ['revision' => 1, 'configuration' => $config])->assertUnprocessable();
        $this->assertSame(1, $draft->refresh()->revision);
        $this->getJson('/api/v1/global-exam-templates?page=1')->assertOk();
    }

    public function test_openapi_and_runtime_clinical_inventories_match_and_nullable_read_is_documented(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $runtime = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->getActionName(), ClinicalExamController::class.'@'))
            ->map(fn ($route) => $route->methods()[0].' /'.$route->uri())->sort()->values()->all();
        $documented = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (is_array($operation) && in_array('Clinical Exams', $operation['tags'] ?? [], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        sort($documented);
        $this->assertCount(11, $runtime);
        $this->assertSame($runtime, $documented);
        $this->assertContains(['type' => 'null'], $document['components']['schemas']['LaboratoryExamListItem']['properties']['sample_type']['oneOf']);
        $this->assertSame(['numeric', 'text', 'qualitative', 'predefined_options'], $document['components']['schemas']['ClinicalExamParameter']['properties']['type']['enum']);
    }

    public function test_sample_free_exam_can_be_priced_and_read_through_legacy_commercial_endpoints(): void
    {
        $draft = $this->draft();
        app(ClinicalExamService::class)->publish($this->lab, $this->actor, $draft->laboratory_exam_id, $draft->id, 1);
        $this->patchJson("/api/v1/laboratory-exams/{$draft->laboratory_exam_id}/status", ['status' => 'active'])->assertOk();
        $list = PriceList::factory()->for($this->lab)->create(['status' => 'active']);
        $this->putJson("/api/v1/price-lists/{$list->id}/exams/{$draft->laboratory_exam_id}", ['price' => '20.00'])->assertSuccessful()->assertJsonPath('data.laboratory_exam.sample_type', null);
        $this->getJson("/api/v1/price-lists/{$list->id}/exams")->assertOk()->assertJsonPath('data.0.laboratory_exam.sample_type', null);
        $this->getJson("/api/v1/price-lists/{$list->id}/available-exams")->assertOk()->assertJsonPath('data.0.sample_type', null);
    }

    public static function invalidConfigurations(): array
    {
        return [['no_parameters'], ['unknown_type'], ['unit'], ['categorical_options'], ['duplicate_options'], ['unknown_reference_option'], ['physical'], ['volume_unit'], ['text_numeric_ref'], ['unknown_field'], ['duplicate_parameter']];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_publication_rejects_inconsistent_configuration(string $case): void
    {
        $config = $this->configuration();
        match ($case) {
            'no_parameters' => $config['parameters'] = [],
            'unknown_type' => $config['parameters'][0]['type'] = 'unsupported',
            'unit' => $config['parameters'][0]['unit_required'] = true,
            'categorical_options' => $config['parameters'][2]['options'] = [],
            'duplicate_options' => $config['parameters'][2]['options'][] = ['code' => 'a', 'label' => 'Again'],
            'unknown_reference_option' => $config['parameters'][2]['references'] = [['key' => 'r', 'priority' => 0, 'allowed_options' => ['unknown']]],
            'physical' => $config['sample_mode'] = 'physical',
            'volume_unit' => $config['sample_requirements'] = [['key' => 'r', 'sample_type_id' => null, 'volume' => 1]],
            'text_numeric_ref' => $config['parameters'][1]['references'] = [['key' => 'r', 'priority' => 0, 'min' => 1]],
            'unknown_field' => $config['extra'] = true,
            'duplicate_parameter' => $config['parameters'][1]['code'] = 'NUM',
        };
        $this->assertNotSame([], app(ClinicalConfigurationValidator::class)->errors($config, $this->lab, true));
    }
}
