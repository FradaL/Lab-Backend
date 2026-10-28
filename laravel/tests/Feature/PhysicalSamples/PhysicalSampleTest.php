<?php

namespace Tests\Feature\PhysicalSamples;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\Http\Controllers\Api\V1\LaboratorySampleController;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySample;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\SampleType;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PhysicalSampleTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(array $restrictions = [], bool $multiple = false, bool $secondMandatory = true, int $schemaVersion = 2): array
    {
        $lab = Laboratory::factory()->create();
        $actor = $this->createLaboratoryMember($lab);
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assignDirectLaboratoryPermissions($actor, $lab, RbacCatalog::PERMISSIONS);
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);
        $branch = Branch::factory()->for($lab)->create(['status' => 'active']);
        $type = SampleType::factory()->for($lab)->create(['status' => 'active']);
        $area = LaboratoryArea::factory()->for($lab)->create(['status' => 'active']);
        $service = app(ClinicalExamService::class);
        $configuration = ['schema_version' => $schemaVersion, 'sample_mode' => 'physical', 'parameters' => [['code' => 'DEMO', 'name' => 'Fictional observation', 'type' => 'text']],
            'sample_requirements' => [['key' => 'main', 'sample_type_id' => $type->id] + $restrictions]];
        if ($multiple) {
            $configuration['sample_requirements'][] = ['key' => 'second', 'sample_type_id' => $type->id, 'container' => 'SECOND-FICTIONAL', 'mandatory' => $secondMandatory];
        }
        $draft = $service->create($lab, $actor, ['code' => 'DEMO', 'name' => 'FICTITIOUS', 'laboratory_area_id' => $area->id, 'sample_type_id' => $type->id, 'configuration' => $configuration]);
        $service->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1);
        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
        $exam->update(['status' => 'active']);
        $prices = PriceList::factory()->for($lab)->create(['status' => 'active', 'currency' => 'GTQ']);
        PriceListExam::factory()->for($lab)->create(['price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id, 'status' => 'active', 'price' => '25.00']);
        $order = LaboratoryOrder::factory()->for($lab)->create(['price_list_id' => $prices->id, 'currency' => 'GTQ', 'status' => 'pending']);
        $line = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $exam->id, $actor);

        return [$lab, $actor, $branch, $type, $order, $line];
    }

    private function collection(Branch $branch, SampleType $type, array $extra = []): array
    {
        return $extra + ['idempotency_key' => 'collection-01', 'sample_type_id' => $type->id,
            'collected_at' => now()->subMinutes(5)->toISOString(), 'collection_branch_id' => $branch->id];
    }

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    public function test_real_collection_reception_acceptance_and_explicit_start_preserve_dependencies(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $base = "/api/v1/laboratory-orders/$order->id";
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertDatabaseCount('laboratory_samples', 0);
        $sample = $this->postJson("$base/samples/collect", $this->collection($branch, $type))->assertCreated()->assertJsonPath('data.status', 'collected')->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.operational_status', 'pending_samples');
        $this->postJson("$base/samples/$sample/receive", ['received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id])->assertOk()->assertJsonPath('data.status', 'received');
        $this->postJson("$base/exams/$line->id/start")->assertConflict()->assertJsonPath('code', 'order_exam_not_ready');
        $this->postJson("$base/samples/$sample/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.exams.0.operational_status', 'ready');
        $this->postJson("$base/exams/$line->id/start")->assertOk()->assertJsonPath('data.operational_status', 'started')->assertJsonPath('data.processing_started_by', $actor->id);
        $this->assertSame('in_process', $order->refresh()->status);
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);
        $this->postJson("$base/samples/$sample/reject", ['reason' => 'Correction'])->assertConflict()->assertJsonPath('code', 'sample_has_clinical_activity');
        $association = DB::table('laboratory_sample_associations')->value('id');
        $this->postJson("$base/samples/$sample/associations/$association/release", ['reason' => 'Correction'])->assertConflict()->assertJsonPath('code', 'association_has_clinical_activity');
        $this->assertDatabaseCount('laboratory_sample_events', 4);
        $this->assertNotNull($line->refresh()->processing_started_at);
    }

    public function test_idempotency_replays_original_response_and_rejects_changed_payload(): void
    {
        [$lab, $actor, $branch, $type, $order] = $this->scenario();
        $url = "/api/v1/laboratory-orders/$order->id/samples/collect";
        $input = $this->collection($branch, $type);
        $first = $this->postJson($url, $input)->assertCreated()->json();
        $this->postJson($url, $input)->assertCreated()->assertExactJson($first);
        $this->postJson($url, [...$input, 'facts' => ['container' => 'different']])->assertConflict()->assertJsonPath('code', 'sample_idempotency_conflict');
        $this->assertDatabaseCount('laboratory_samples', 1);
        $this->assertDatabaseCount('laboratory_sample_events', 1);
    }

    public function test_external_unknown_collection_does_not_invent_collector_or_satisfy_temporal_requirement(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario(['max_age_minutes' => 60]);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", ['idempotency_key' => 'external-01', 'sample_type_id' => $type->id,
            'provenance' => 'patient_provided', 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id])
            ->assertCreated()->assertJsonPath('data.collection.actor_id', null)->assertJsonPath('data.collection.occurred_at', null)->json('data.id');
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertConflict()->assertJsonPath('code', 'sample_requirement_incompatible');
        $this->assertDatabaseCount('laboratory_sample_associations', 0);
        $this->assertSame('accepted', LaboratorySample::findOrFail($sample)->status);
    }

    private function externalInput(Branch $branch, SampleType $type, array $extra = []): array
    {
        return $extra + ['idempotency_key' => 'external-01', 'sample_type_id' => $type->id, 'provenance' => 'external_center',
            'collected_at' => now()->subMinutes(5)->toISOString(), 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id];
    }

    public function test_one_sample_covers_repeated_lines_without_merging_requirements(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $second = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $actor);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $ids = [$line->sampleRequirements->first()->id, $second->sampleRequirements->first()->id];
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => $ids])->assertOk();
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => array_reverse($ids)])->assertOk();
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.operational_status', 'ready')->assertJsonPath('data.exams.1.operational_status', 'ready');
        $this->postJson("$base/exams/$line->id/start")->assertOk();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.status', 'in_process')->assertJsonPath('data.exams.0.operational_status', 'started')->assertJsonPath('data.exams.1.operational_status', 'ready');
        $this->assertDatabaseCount('laboratory_sample_associations', 2);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 2);
        $this->assertDatabaseCount('laboratory_samples', 1);
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);
    }

    public function test_partial_readiness_does_not_start_other_requirements_and_corrected_associations_keep_history(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario([], true);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $first = $line->sampleRequirements->first()->id;
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$first]])->assertOk();
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.sample_requirements.0.status', 'satisfied')->assertJsonPath('data.exams.0.sample_requirements.1.status', 'pending')->assertJsonPath('data.exams.0.operational_status', 'pending_samples');
        $this->getJson("$base/exams")->assertOk()->assertJsonPath('data.0.sample_requirements.0.status', 'satisfied');
        $this->postJson("$base/exams/$line->id/start")->assertConflict();
        $association = DB::table('laboratory_sample_associations')->value('id');
        $this->postJson("$base/samples/$sample/associations/$association/release", ['reason' => 'Wrong selection'])->assertOk();
        $this->postJson("$base/samples/$sample/associations/$association/release", ['reason' => 'Wrong selection'])->assertOk();
        $this->assertDatabaseCount('laboratory_sample_associations', 1);
        $this->assertDatabaseHas('laboratory_sample_associations', ['id' => $association, 'release_reason' => 'Wrong selection', 'released_by' => $actor->id]);
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.sample_requirements.0.status', 'pending');
        $this->assertSame('accepted', LaboratorySample::findOrFail($sample)->status);
        $this->deleteJson("$base/exams/$line->id")->assertConflict()->assertJsonPath('code', 'order_exam_has_clinical_activity');
    }

    public function test_rejection_recalculates_live_satisfaction_and_new_collection_has_another_identifier(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data');
        $this->postJson("$base/samples/{$sample['id']}/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->postJson("$base/samples/{$sample['id']}/accept")->assertOk();
        $this->postJson("$base/samples/{$sample['id']}/reject", ['reason' => 'FICTIONAL rejection'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("$base/samples/{$sample['id']}/accept")->assertConflict();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.sample_requirements.0.status', 'pending');
        $next = $this->postJson("$base/samples/collect", $this->collection($branch, $type))->assertCreated()->json('data.identifier');
        $this->assertNotSame($sample['identifier'], $next);
        $this->assertDatabaseCount('laboratory_samples', 2);
        $this->assertDatabaseCount('laboratory_sample_associations', 1);
        $this->assertDatabaseCount('laboratory_sample_events', 6);
    }

    #[DataProvider('incompatibleFacts')]
    public function test_every_configured_fact_is_required_for_an_association(array $restriction, array $facts, string $error): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario($restriction);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type, ['facts' => $facts]))->assertCreated()->json('data.id');
        $response = $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertConflict()->assertJsonPath('code', 'sample_requirement_incompatible');
        $this->assertContains($error, $response->json('errors')['sample_requirement_ids.'.$line->sampleRequirements->first()->id]);
        $this->assertDatabaseCount('laboratory_sample_associations', 0);
        $this->assertNull($line->refresh()->clinical_activity_started_at);
    }

    public static function incompatibleFacts(): array
    {
        return [
            'container unknown' => [['container' => 'FICTITIOUS'], [], 'container_mismatch_or_unknown'],
            'container different' => [['container' => 'FICTITIOUS'], ['container' => 'OTHER'], 'container_mismatch_or_unknown'],
            'additive missing' => [['additives' => ['DEMO-A']], [], 'additives_mismatch_or_unknown'],
            'extra additive forbidden' => [['additives' => ['DEMO-A']], ['additives' => ['DEMO-A', 'DEMO-B']], 'additives_mismatch_or_unknown'],
            'additive different' => [['additives' => ['DEMO-A']], ['additives' => ['DEMO-B']], 'additives_mismatch_or_unknown'],
            'collection condition unknown' => [['collection_conditions' => ['demo' => 'A']], [], 'collection_conditions_mismatch_or_unknown:demo'],
            'storage condition different' => [['storage_conditions' => ['demo' => 'A']], ['storage_conditions' => ['demo' => 'B']], 'storage_conditions_mismatch_or_unknown:demo'],
            'volume missing' => [['volume' => 2, 'volume_unit' => 'demo-mL'], [], 'volume_unknown_or_unit_mismatch'],
            'volume too small' => [['volume' => 2, 'volume_unit' => 'demo-mL'], ['volume' => 1.5, 'volume_unit' => 'demo-mL'], 'insufficient_volume'],
            'unit different' => [['volume' => 2, 'volume_unit' => 'demo-mL'], ['volume' => 3, 'volume_unit' => 'demo-uL'], 'volume_unknown_or_unit_mismatch'],
            'transport exceeded' => [['max_transport_minutes' => 1], [], 'transport_time_exceeded'],
        ];
    }

    public function test_temporal_expiration_blocks_start_using_historical_configuration_without_rejecting_sample(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario(['max_age_minutes' => 5]);
        DB::unprepared("CREATE OR REPLACE FUNCTION sample_operation_time() RETURNS timestamptz LANGUAGE sql STABLE AS $$ SELECT '2026-01-01 10:01:00+00'::timestamptz $$");
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type, ['collected_at' => '2026-01-01T10:00:00+00:00', 'received_at' => '2026-01-01T10:01:00+00:00']))->assertCreated()->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.operational_status', 'ready');
        $service = app(ClinicalExamService::class);
        $draft = $service->fork($lab, $actor, $line->laboratory_exam_id, $line->clinical_version_id);
        $config = $draft->configuration;
        unset($config['sample_requirements'][0]['sample_type_name']);
        $config['sample_requirements'][0]['max_age_minutes'] = 500;
        $draft = $service->update($lab, $actor, $line->laboratory_exam_id, $draft->id, 1, $config);
        $service->publish($lab, $actor, $line->laboratory_exam_id, $draft->id, $draft->revision);
        DB::unprepared("CREATE OR REPLACE FUNCTION sample_operation_time() RETURNS timestamptz LANGUAGE sql STABLE AS $$ SELECT '2026-01-01 10:06:00+00'::timestamptz $$");
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.operational_status', 'pending_samples');
        $this->postJson("$base/exams/$line->id/start")->assertConflict()->assertJsonPath('code', 'order_exam_not_ready');
        $this->assertSame('accepted', LaboratorySample::findOrFail($sample)->status);
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 0);
        $this->assertNull($line->refresh()->processing_started_at);
    }

    public function test_foreign_order_and_lab_associations_and_branches_never_leak_or_persist(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $base = "/api/v1/laboratory-orders/$order->id";
        $otherOrder = LaboratoryOrder::factory()->for($lab)->create(['price_list_id' => $order->price_list_id, 'currency' => 'GTQ', 'status' => 'pending']);
        $otherLine = app(AddExamToLaboratoryOrder::class)->execute($lab, $otherOrder->id, $line->laboratory_exam_id, $actor);
        $foreignBranch = Branch::factory()->create();
        $this->postJson("$base/samples/collect", $this->collection($branch, $type, ['collection_branch_id' => $foreignBranch->id]))->assertUnprocessable()->assertJsonValidationErrors('collection_branch_id');
        $this->assertDatabaseCount('laboratory_sample_idempotencies', 0);
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$otherLine->sampleRequirements->first()->id]])->assertNotFound();
        $this->getJson("/api/v1/laboratory-orders/$otherOrder->id/samples/$sample")->assertNotFound();
        $foreign = LaboratoryOrder::factory()->create();
        $this->getJson("/api/v1/laboratory-orders/$foreign->id/operational")->assertNotFound();
        $this->assertDatabaseCount('laboratory_sample_associations', 0);
    }

    public function test_receiving_permission_cannot_accept_or_associate_or_start(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $base = "/api/v1/laboratory-orders/$order->id";
        $receiver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($receiver, $lab, ['samples.receive']);
        $this->actingAs($receiver);
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $this->postJson("$base/samples/$sample/accept")->assertForbidden();
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertForbidden();
        $this->postJson("$base/exams/$line->id/start")->assertForbidden();
        $this->getJson("$base/operational")->assertForbidden();
        $this->assertSame('received', LaboratorySample::findOrFail($sample)->status);
        $this->assertDatabaseCount('laboratory_sample_events', 2);
    }

    public function test_late_audit_failure_rolls_back_sample_events_and_idempotency(): void
    {
        [$lab, $actor, $branch, $type, $order] = $this->scenario();
        AuditLog::creating(fn () => throw new RuntimeException('Injected audit failure'));
        $this->postJson("/api/v1/laboratory-orders/$order->id/samples/collect", $this->collection($branch, $type))->assertServerError();
        $this->assertDatabaseCount('laboratory_samples', 0);
        $this->assertDatabaseCount('laboratory_sample_events', 0);
        $this->assertDatabaseCount('laboratory_sample_idempotencies', 0);
    }

    public function test_first_reception_can_complete_unknown_facts_but_cannot_rewrite_known_observations(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario(['storage_conditions' => ['demo' => 'known']]);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/collect", $this->collection($branch, $type, ['facts' => ['container' => 'original']]))->assertCreated()->json('data.id');
        $receivingBranch = Branch::factory()->for($lab)->create(['status' => 'active']);
        $reception = ['received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $receivingBranch->id];
        $this->postJson("$base/samples/$sample/receive", $reception + ['facts' => ['container' => 'replacement']])->assertConflict()->assertJsonPath('code', 'sample_observation_conflict');
        $this->postJson("$base/samples/$sample/receive", $reception + ['facts' => ['storage_conditions' => ['demo' => 'known']]])->assertOk()->assertJsonPath('data.collection.branch_id', $branch->id)->assertJsonPath('data.reception.branch_id', $receivingBranch->id)->assertJsonPath('data.facts.container', 'original');
        $this->postJson("$base/samples/$sample/receive", $reception + ['facts' => ['container' => 'replacement']])->assertConflict();
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->assertDatabaseCount('laboratory_sample_events', 3);
        $this->assertDatabaseCount('laboratory_sample_associations', 1);
    }

    public function test_multiple_adequate_samples_do_not_make_unused_material_a_processing_dependency(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario(['volume' => 2, 'volume_unit' => 'DEMO-U']);
        $base = "/api/v1/laboratory-orders/$order->id";
        $ids = [];
        foreach (['first', 'second'] as $key) {
            $ids[] = $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type, ['idempotency_key' => $key, 'facts' => ['volume' => 2, 'volume_unit' => 'DEMO-U']]))->assertCreated()->json('data.id');
            $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
            $this->postJson("$base/samples/$sample/accept")->assertOk();
        }
        $this->postJson("$base/exams/$line->id/start")->assertOk();
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);
        $this->postJson("$base/samples/{$ids[1]}/reject", ['reason' => 'Unused alternate material'])->assertOk();
        $this->postJson("$base/samples/{$ids[0]}/reject", ['reason' => 'Used material'])->assertConflict();
        $this->assertDatabaseCount('laboratory_sample_associations', 2);
    }

    public function test_small_volumes_are_never_summed_across_samples(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario(['volume' => 2, 'volume_unit' => 'DEMO-U']);
        $base = "/api/v1/laboratory-orders/$order->id";
        foreach (['first', 'second'] as $key) {
            $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type, ['idempotency_key' => $key, 'facts' => ['volume' => 1.5, 'volume_unit' => 'DEMO-U']]))->assertCreated()->json('data.id');
            $this->postJson("$base/samples/$sample/accept")->assertOk();
            $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertConflict();
        }
        $this->postJson("$base/exams/$line->id/start")->assertConflict();
        $this->assertDatabaseCount('laboratory_sample_associations', 0);
        $this->assertDatabaseCount('laboratory_samples', 2);
    }

    public function test_optional_requirements_do_not_block_start_and_none_requires_no_confirmation(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario([], true, false);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.operational_status', 'ready')->assertJsonPath('data.exams.0.sample_requirements.1.status', 'pending');
        $noneExam = LaboratoryExam::factory()->for($lab)->orderable()->create(['status' => 'active']);
        PriceListExam::factory()->for($lab)->create(['price_list_id' => $order->price_list_id, 'laboratory_exam_id' => $noneExam->id, 'status' => 'active']);
        $noneLine = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $noneExam->id, $actor);
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.1.operational_status', 'ready');
        $this->postJson("$base/exams/$noneLine->id/start")->assertOk();
        $this->postJson("$base/exams/$line->id/start")->assertOk();
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);
        $this->patchJson("$base/status", ['status' => 'completed'])->assertConflict()->assertJsonPath('code', 'clinical_finalization_required');
    }

    public function test_start_and_order_audit_failure_roll_back_dependencies_markers_and_order_transition(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $before = AuditLog::count();
        AuditLog::creating(function ($event): void {
            if ($event->event === 'order.status_changed') {
                throw new RuntimeException('Injected final audit failure');
            }
        });
        $this->postJson("$base/exams/$line->id/start")->assertServerError();
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 0);
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertNull($line->refresh()->processing_started_at);
        $this->assertSame($before, AuditLog::count());
    }

    public function test_schema_upgrade_preserves_published_snapshots_historical_fields_and_pending_requirements(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $beforeOrder = Arr::except($order->fresh()->getRawOriginal(), ['clinical_revision']);
        $beforeLine = Arr::except($line->fresh()->getRawOriginal(), ['report_area_snapshot']);
        $beforeRequirements = DB::table('laboratory_order_sample_requirements')->orderBy('id')->get()->toJson();
        $beforeVersions = DB::table('laboratory_exam_versions')->orderBy('id')->get()->toJson();
        $beforeLegacy = DB::table('laboratory_order_legacy_exams')->orderBy('laboratory_exam_id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $resultsMigration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $validationMigration = require database_path('migrations/2026_10_11_000002_add_clinical_result_validation.php');
        $finalizationMigration = require database_path('migrations/2026_10_11_000003_add_clinical_order_finalization.php');
        $reportMigration = require database_path('migrations/2026_10_11_000004_create_clinical_report_issuances.php');
        $deliveryMigration = require database_path('migrations/2026_10_11_000005_create_report_delivery_controls.php');
        $integration = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $localIntegration = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $localIntegration->down();
        $integration->down();
        $deliveryMigration->down();
        $reportMigration->down();
        $finalizationMigration->down();
        $validationMigration->down();
        $resultsMigration->down();
        $migration->down();
        $migration->up();
        $resultsMigration->up();
        $validationMigration->up();
        $finalizationMigration->up();
        $reportMigration->up();
        $deliveryMigration->up();
        $integration->up();
        $localIntegration->up();
        $this->assertSame($beforeOrder, Arr::except($order->fresh()->getRawOriginal(), ['clinical_revision']));
        $this->assertSame($beforeLine, Arr::except($line->fresh()->getRawOriginal(), ['report_area_snapshot']));
        $this->assertNull($line->fresh()->report_area_snapshot); // A pre-report line receives no invented area backfill.
        $this->assertSame($beforeRequirements, DB::table('laboratory_order_sample_requirements')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeVersions, DB::table('laboratory_exam_versions')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeLegacy, DB::table('laboratory_order_legacy_exams')->orderBy('laboratory_exam_id')->get()->toJson());
        $this->assertDatabaseCount('laboratory_samples', 0);
        $this->assertSame(['pending'], DB::table('laboratory_sample_requirement_satisfactions')->pluck('status')->all());
    }

    public function test_internal_reception_does_not_invent_unknown_collection_metadata(): void
    {
        [$lab, $actor, $branch, $type, $order] = $this->scenario();
        $sample = $this->postJson("/api/v1/laboratory-orders/$order->id/samples/receive", $this->externalInput($branch, $type, ['provenance' => 'internal', 'collected_at' => null]))
            ->assertCreated()->assertJsonPath('data.collection.actor_id', null)->assertJsonPath('data.collection.branch_id', null)
            ->assertJsonPath('data.collection.occurred_at', null)->assertJsonPath('data.reception.actor_id', $actor->id);
        $this->assertCount(1, $sample->json('data.events'));
    }

    public function test_reported_collector_is_local_and_distinct_from_the_recording_actor(): void
    {
        [$lab, $actor, $branch, $type, $order] = $this->scenario();
        $collector = $this->createLaboratoryMember($lab);
        $base = "/api/v1/laboratory-orders/$order->id/samples";
        $this->postJson("$base/collect", $this->collection($branch, $type, ['collected_by' => $collector->id]))
            ->assertCreated()->assertJsonPath('data.collection.actor_id', $collector->id)->assertJsonPath('data.events.0.actor_id', $actor->id);
        $foreign = $this->createLaboratoryMember(Laboratory::factory()->create());
        $this->postJson("$base/collect", $this->collection($branch, $type, ['idempotency_key' => 'foreign-collector', 'collected_by' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('collected_by');
        $this->postJson("$base/receive", $this->externalInput($branch, $type, ['collected_by' => $collector->id]))->assertUnprocessable()->assertJsonValidationErrors('collected_by');
        $this->assertDatabaseCount('laboratory_samples', 1);
    }

    public function test_decimal_quantity_is_not_rounded_up_to_the_required_minimum(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario(['volume' => 0.1, 'volume_unit' => 'DEMO-U']);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type, ['facts' => ['volume' => '0.09999999999999999999', 'volume_unit' => 'DEMO-U']]))
            ->assertCreated()->assertJsonPath('data.facts.volume', '0.09999999999999999999')->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertConflict();
        $this->assertDatabaseCount('laboratory_sample_associations', 0);
    }

    public function test_complete_typed_constraints_and_reordered_additives_are_compatible(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario([
            'container' => 'DEMO-TUBE', 'additives' => ['DEMO-A', 'DEMO-B'], 'volume' => 2, 'volume_unit' => 'DEMO-U',
            'collection_conditions' => ['mode' => 'DEMO'], 'storage_conditions' => ['handling' => 'DEMO'],
            'max_age_minutes' => 60, 'max_transport_minutes' => 60, 'instructions' => 'FICTITIOUS informational instruction',
        ]);
        $base = "/api/v1/laboratory-orders/$order->id";
        $input = $this->externalInput($branch, $type, ['collected_at' => now()->subMinutes(5)->toISOString(),
            'facts' => ['container' => 'DEMO-TUBE', 'additives' => ['DEMO-B', 'DEMO-A'], 'volume' => '2.000', 'volume_unit' => 'DEMO-U',
                'collection_conditions' => ['mode' => 'DEMO'], 'storage_conditions' => ['handling' => 'DEMO']]]);
        $response = $this->postJson("$base/samples/receive", $input)->assertCreated();
        $input['facts']['additives'] = ['DEMO-A', 'DEMO-B'];
        $input['facts']['volume'] = 2;
        $this->postJson("$base/samples/receive", $input)->assertCreated()->assertExactJson($response->json());
        $sample = $response->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->postJson("$base/exams/$line->id/start")->assertOk();
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.sample_requirements.0.association_evaluations.0.compatibility_errors', [])
            ->assertJsonPath('data.exams.0.processing_dependencies.0.decision.clinical_version_id', $line->clinical_version_id);
    }

    public function test_receipt_retry_preserves_microseconds_and_does_not_duplicate_history(): void
    {
        [$lab, $actor, $branch, $type, $order] = $this->scenario();
        $base = "/api/v1/laboratory-orders/$order->id/samples";
        $sample = $this->postJson("$base/collect", $this->collection($branch, $type))->assertCreated()->json('data.id');
        $input = ['received_at' => now()->subMinute()->setMicrosecond(123456)->toISOString(), 'receiving_branch_id' => $branch->id];
        $first = $this->postJson("$base/$sample/receive", $input)->assertOk()->json();
        $this->postJson("$base/$sample/receive", $input)->assertOk()->assertExactJson($first);
        $this->assertDatabaseCount('laboratory_sample_events', 2);
    }

    public function test_incomplete_materialization_is_blocked_in_both_operational_read_and_start(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        DB::table('laboratory_order_sample_requirements')->where('laboratory_order_exam_id', $line->id)->delete();
        $base = "/api/v1/laboratory-orders/$order->id";
        $this->getJson("$base/operational")->assertOk()->assertJsonPath('data.exams.0.can_start', false)
            ->assertJsonPath('data.exams.0.blocking_reasons', ['materialized_requirements_incomplete']);
        $this->postJson("$base/exams/$line->id/start")->assertConflict()->assertJsonPath('code', 'order_exam_requirements_incomplete');
    }

    public function test_sql_constraints_protect_physical_identity_history_dependencies_and_derived_state(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertOk();
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->postJson("$base/exams/$line->id/start")->assertOk();
        $association = DB::table('laboratory_sample_associations')->first();
        $attempts = [
            fn () => DB::table('laboratory_samples')->where('id', $sample)->update(['identifier' => str_repeat('0', 26)]),
            fn () => DB::table('laboratory_samples')->where('id', $sample)->update(['status' => 'rejected']),
            fn () => DB::table('laboratory_samples')->where('id', $sample)->delete(),
            fn () => DB::table('laboratory_sample_events')->update(['event' => 'tampered']),
            fn () => DB::table('laboratory_sample_events')->delete(),
            fn () => DB::table('laboratory_sample_associations')->where('id', $association->id)->delete(),
            fn () => DB::table('laboratory_sample_associations')->where('id', $association->id)->update(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => 'tampered']),
            fn () => DB::table('laboratory_sample_processing_dependencies')->update(['decision' => '{}']),
            fn () => DB::table('laboratory_sample_processing_dependencies')->delete(),
            fn () => DB::table('laboratory_order_exams')->where('id', $line->id)->update(['processing_started_at' => null, 'processing_started_by' => null]),
            fn () => DB::table('laboratory_sample_idempotencies')->update(['response' => '{}']),
            fn () => DB::table('laboratory_sample_requirement_satisfactions')->update(['status' => 'pending']),
        ];
        foreach ($attempts as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('PostgreSQL must reject mutation of clinical evidence.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('laboratory_samples', 1);
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);
        $this->assertSame(['satisfied'], DB::table('laboratory_sample_requirement_satisfactions')->pluck('status')->all());
    }

    public function test_consolidated_query_count_does_not_grow_with_repeated_exam_lines(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario();
        $service = app(LaboratorySampleService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->operational($lab, $order->id);
        $first = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 0; $index < 5; $index++) {
            app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $actor);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $service->operational($lab, $order->id);
        $this->assertCount(6, $result['exams']);
        $this->assertSame($first, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_legacy_unstructured_instructions_cannot_be_silently_validated(): void
    {
        [$lab, $actor, $branch, $type, $order, $line] = $this->scenario(['instructions' => 'Unstructured historical restriction'], false, true, 1);
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", $this->externalInput($branch, $type))->assertCreated()->json('data.id');
        $candidates = $this->getJson("$base/samples/$sample/candidates")->assertOk()->assertJsonPath('data.0.compatible', false);
        $this->assertContains('unstructured_sample_instructions', $candidates->json('data.0.errors'));
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->first()->id]])->assertConflict();
    }

    public function test_sample_routes_enforce_membership_subscription_and_active_actor_before_writing(): void
    {
        [$lab, $actor, $branch, $type, $order] = $this->scenario();
        $url = "/api/v1/laboratory-orders/$order->id/samples/collect";
        $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => false]);
        $this->postJson($url, $this->collection($branch, $type))->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => true]);
        $lab->subscriptions()->delete();
        $this->postJson($url, $this->collection($branch, $type))->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $actor->update(['is_active' => false]);
        $this->postJson($url, $this->collection($branch, $type))->assertForbidden();
        $this->assertDatabaseCount('laboratory_samples', 0);
        $this->assertDatabaseCount('laboratory_sample_idempotencies', 0);
    }

    public function test_all_physical_sample_operations_match_runtime_routes_and_openapi(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $runtime = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->getActionName(), LaboratorySampleController::class.'@'))
            ->map(fn ($route) => $route->methods()[0].' /'.$route->uri())->sort()->values()->all();
        $documented = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (is_array($operation) && in_array('Physical Samples', $operation['tags'] ?? [], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        sort($documented);
        $this->assertCount(12, $runtime);
        $this->assertSame($runtime, $documented);
        $this->assertSame(['pending', 'satisfied'], $document['components']['schemas']['LaboratoryOrderSampleRequirement']['properties']['status']['enum']);
    }
}
