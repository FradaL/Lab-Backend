<?php

namespace Tests\Feature\ClinicalResults;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\SampleType;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ClinicalResultTest extends TestCase
{
    use ClinicalResultScenario;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    private function input(int $revision = 0, string $code = 'N', mixed $value = '1.2500'): array
    {
        return ['revision' => $revision, 'results' => [['parameter_code' => $code, 'value' => $value]]];
    }

    public function test_partial_complete_edit_clear_and_history_are_preliminary_and_precise(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->scenario();
        $this->getJson($url)->assertOk()->assertJsonPath('data.progress.status', 'empty')->assertJsonPath('data.progress.revision', 0);
        $this->assertDatabaseCount('laboratory_order_result_captures', 0);
        $this->putJson($url, $this->input(value: '12345678901234567890.1234567890123456789'))->assertOk()
            ->assertJsonPath('data.progress.status', 'partial')->assertJsonPath('data.parameters.0.value', '12345678901234567890.1234567890123456789');
        $this->putJson($url, ['revision' => 1, 'results' => [['parameter_code' => 'T', 'value' => 'Demo'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]])
            ->assertOk()->assertJsonPath('data.progress.complete', true)->assertJsonPath('data.progress.status', 'complete')->assertJsonPath('data.progress.clinical_status', 'preliminary');
        $this->putJson($url, $this->input(2, 'N', '2.00'))->assertOk()->assertJsonPath('data.parameters.0.value', '2');
        $this->putJson($url, $this->input(3, 'N', '2'))->assertOk()->assertJsonPath('data.progress.revision', 3);
        $this->putJson($url, $this->input(3, 'T', null))->assertOk()->assertJsonPath('data.progress.complete', false)->assertJsonPath('data.progress.pending_parameters', ['T']);
        $this->getJson("$url/history")->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.before.results.0.value', 'Demo')->assertJsonPath('data.0.after.results.0.value', null)->assertJsonPath('data.0.actor_id', $actor->id);
        $this->assertSame('in_process', $order->refresh()->status);
        $this->assertDatabaseCount('laboratory_order_results', 3);
        $this->assertSame($line->clinical_version_id, DB::table('laboratory_order_results')->value('clinical_version_id'));
        $this->getJson("/api/v1/laboratory-orders/$order->id/operational")->assertOk()->assertJsonPath('data.exams.0.result_capture.status', 'partial')->assertJsonMissing(['value' => '2']);
    }

    public static function invalidValues(): array
    {
        return [['N', 1.25], ['N', 'NaN'], ['N', 'Infinity'], ['N', true], ['N', []], ['N', '1,25'], ['T', ''], ['T', '   '], ['T', '12345678901'], ['T', 123], ['Q', 'MISSING'], ['Q', ['YES']], ['O', 'YES'], ['X', 'x']];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_typed_values_are_atomic(string $code, mixed $value): void
    {
        [, , , , $url] = $this->scenario();
        $this->putJson($url, $this->input(0, $code, $value))->assertUnprocessable();
        $this->assertDatabaseCount('laboratory_order_result_captures', 0);
        $this->assertDatabaseCount('laboratory_order_results', 0);
    }

    public function test_unknown_fields_duplicates_and_revision_formats_are_rejected(): void
    {
        [, , , , $url] = $this->scenario();
        foreach ([$this->input() + ['unit' => 'client'], ['revision' => '0', 'results' => $this->input()['results']],
            ['revision' => 0, 'results' => ['item' => $this->input()['results'][0]]],
            ['revision' => 0, 'results' => [$this->input()['results'][0], $this->input()['results'][0]]],
            ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1', 'unit' => 'client']]]] as $input) {
            $this->putJson($url, $input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('laboratory_order_results', 0);
    }

    public function test_order_status_does_not_replace_explicit_line_start_and_cancel_blocks_edits(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->scenario(false);
        $this->putJson($url, $this->input())->assertConflict()->assertJsonPath('code', 'result_capture_unavailable');
        $order->update(['status' => 'in_process']);
        $this->putJson($url, $this->input())->assertConflict();
        setPermissionsTeamId($lab->id);
        app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
        $this->putJson($url, $this->input())->assertOk();
        $this->patchJson("/api/v1/laboratory-orders/$order->id/status", ['status' => 'cancelled'])->assertOk();
        $this->putJson($url, $this->input(1, 'N', '3'))->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.parameters.0.value', '1.25')->assertJsonPath('data.capture_available', false);
    }

    public function test_repeated_lines_have_independent_results_and_revisions(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->scenario(false);
        $second = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $actor);
        app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
        app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $second->id);
        $this->putJson($url, $this->input())->assertOk();
        $this->putJson(str_replace("/exams/$line->id/", "/exams/$second->id/", $url), $this->input(value: '99'))->assertOk()->assertJsonPath('data.parameters.0.value', '99');
        $this->assertDatabaseCount('laboratory_order_results', 2);
        $this->putJson($url, $this->input())->assertConflict()->assertJsonPath('code', 'result_revision_conflict');
    }

    public function test_capture_edit_read_history_permissions_are_independent(): void
    {
        [$lab, $actor, , , $url] = $this->scenario();
        setPermissionsTeamId($lab->id);
        $actor->syncPermissions(['results.capture']);
        $this->getJson($url)->assertForbidden();
        $this->getJson("$url/history")->assertForbidden();
        $this->putJson($url, $this->input())->assertOk()->assertJsonMissingPath('data.parameters');
        $this->putJson($url, $this->input(1, 'N', '1.25'))->assertForbidden();
        $this->putJson($url, $this->input(1, 'N', '3'))->assertForbidden();
        setPermissionsTeamId($lab->id);
        $actor->syncPermissions(['results.capture', 'results.edit']);
        $this->putJson($url, $this->input(1, 'N', '1.25'))->assertOk()->assertJsonMissingPath('data.parameters')->assertJsonPath('data.progress.revision', 2);
        $this->putJson($url, $this->input(2, 'N', '3'))->assertOk()->assertJsonMissingPath('data.parameters')->assertJsonPath('data.progress.revision', 3);
        setPermissionsTeamId($lab->id);
        $actor->syncPermissions(['results.capture']);
        $this->putJson($url, $this->input(3, 'T', 'Demo'))->assertOk();
        setPermissionsTeamId($lab->id);
        $actor->syncPermissions(['results.view_history']);
        $this->getJson("$url/history")->assertOk();
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, $this->input(4))->assertForbidden();
    }

    public function test_tenant_membership_subscription_and_actor_guards(): void
    {
        [$lab, $actor, , , $url] = $this->scenario();
        $other = Laboratory::factory()->create();
        $this->withHeader('X-Laboratory-ID', (string) $other->id)->getJson($url)->assertForbidden();
        $this->withHeader('X-Laboratory-ID', (string) $lab->id);
        $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => false]);
        $this->putJson($url, $this->input())->assertForbidden();
        $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => true]);
        $actor->update(['is_active' => false]);
        $this->putJson($url, $this->input())->assertForbidden();
        $actor->update(['is_active' => true]);
        Subscription::where('laboratory_id', $lab->id)->update(['ends_at' => now()->subDay()]);
        $this->putJson($url, $this->input())->assertForbidden();
    }

    public function test_audit_failure_rolls_back_capture_and_values(): void
    {
        [, , , , $url] = $this->scenario();
        AuditLog::creating(fn ($log) => $log->event === 'order_results.saved' ? throw new RuntimeException('Audit failed') : null);
        try {
            $this->withoutExceptionHandling();
            $this->putJson($url, $this->input());
            $this->fail('Expected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('laboratory_order_result_captures', 0);
        $this->assertDatabaseCount('laboratory_order_results', 0);
    }

    public function test_option_codes_are_literal_and_preserve_distinct_numeric_looking_labels(): void
    {
        [, , , , $url] = $this->scenario();
        $this->putJson($url, $this->input(0, 'Q', '1'))->assertOk()->assertJsonPath('data.parameters.2.option_label', 'Fictitious one');
        $this->putJson($url, $this->input(1, 'Q', '01'))->assertOk()->assertJsonPath('data.parameters.2.option_label', 'Fictitious zero-one');
        $this->getJson("$url/history")->assertOk()->assertJsonPath('data.0.before.results.0.option_label', 'Fictitious one')->assertJsonPath('data.0.after.results.0.option_label', 'Fictitious zero-one');
    }

    public function test_physical_line_requires_sample_readiness_then_explicit_start_before_capture(): void
    {
        [$lab, $actor, $order, $original] = $this->scenario(false);
        $type = SampleType::factory()->for($lab)->create(['status' => 'active']);
        $branch = Branch::factory()->for($lab)->create(['status' => 'active']);
        $clinical = app(ClinicalExamService::class);
        $draft = $clinical->fork($lab, $actor, $original->laboratory_exam_id, $original->clinical_version_id);
        $configuration = $draft->configuration;
        $configuration['schema_version'] = 2;
        $configuration['sample_mode'] = 'physical';
        $configuration['sample_requirements'] = [['key' => 'main', 'sample_type_id' => $type->id]];
        $draft = $clinical->update($lab, $actor, $original->laboratory_exam_id, $draft->id, 1, $configuration);
        $clinical->publish($lab, $actor, $original->laboratory_exam_id, $draft->id, $draft->revision);
        $line = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $original->laboratory_exam_id, $actor);
        $url = "/api/v1/laboratory-orders/$order->id/exams/$line->id/results";
        $this->putJson($url, $this->input())->assertConflict();
        $base = "/api/v1/laboratory-orders/$order->id";
        $sample = $this->postJson("$base/samples/receive", ['idempotency_key' => 'result-physical', 'sample_type_id' => $type->id, 'provenance' => 'patient_provided',
            'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id])->assertCreated()->json('data.id');
        $this->postJson("$base/samples/$sample/accept")->assertOk();
        $this->postJson("$base/samples/$sample/associations", ['sample_requirement_ids' => [$line->sampleRequirements->sole()->id]])->assertOk();
        $this->putJson($url, $this->input())->assertConflict();
        $this->postJson("$base/exams/$line->id/start")->assertOk();
        $this->putJson($url, $this->input())->assertOk()->assertJsonPath('data.progress.status', 'partial');
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);
    }

    public function test_batch_validation_failure_keeps_existing_revision_values_and_audit(): void
    {
        [, , , , $url] = $this->scenario();
        $this->putJson($url, $this->input())->assertOk();
        $this->putJson($url, ['revision' => 1, 'results' => [['parameter_code' => 'N', 'value' => '99'], ['parameter_code' => 'O', 'value' => 'INVALID']]])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('data.progress.revision', 1)->assertJsonPath('data.parameters.0.value', '1.25');
        $this->getJson("$url/history")->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_later_publication_cannot_change_the_line_parameters_units_or_admit_new_codes(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->scenario();
        setPermissionsTeamId($lab->id);
        $clinical = app(ClinicalExamService::class);
        $draft = $clinical->fork($lab, $actor, $line->laboratory_exam_id, $line->clinical_version_id);
        $config = $draft->configuration;
        $config['parameters'][0]['unit'] = 'NEW-U';
        $config['parameters'][] = ['code' => 'NEW', 'name' => 'Fictional new parameter', 'type' => 'text'];
        $draft = $clinical->update($lab, $actor, $line->laboratory_exam_id, $draft->id, 1, $config);
        $clinical->publish($lab, $actor, $line->laboratory_exam_id, $draft->id, $draft->revision);
        $this->putJson($url, $this->input(0, 'NEW', 'demo'))->assertUnprocessable();
        $this->putJson($url, $this->input())->assertOk()->assertJsonPath('data.parameters.0.unit', 'DEMO-U')->assertJsonPath('data.clinical_version_id', $line->clinical_version_id);
    }

    public function test_foreign_order_or_line_remains_not_found_even_with_all_local_permissions(): void
    {
        [$lab, , $order, $line] = $this->scenario();
        [$other, $actor] = $this->scenario();
        $this->getJson("/api/v1/laboratory-orders/$order->id/exams/$line->id/results")->assertNotFound();
        $this->putJson("/api/v1/laboratory-orders/$order->id/exams/$line->id/results", $this->input())->assertNotFound();
        $this->getJson("/api/v1/laboratory-orders/$order->id/exams/$line->id/results/history")->assertNotFound();
        $this->assertDatabaseCount('laboratory_order_results', 0);
    }

    public function test_legacy_line_is_readable_as_unavailable_but_cannot_capture_or_infer_parameters(): void
    {
        [$lab, , $order, $line, $url] = $this->scenario(false);
        $migration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $physical = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $composition = require database_path('migrations/2026_10_10_000002_add_order_clinical_composition.php');
        $validationMigration = require database_path('migrations/2026_10_11_000002_add_clinical_result_validation.php');
        $finalizationMigration = require database_path('migrations/2026_10_11_000003_add_clinical_order_finalization.php');
        $reportMigration = require database_path('migrations/2026_10_11_000004_create_clinical_report_issuances.php');
        $deliveryMigration = require database_path('migrations/2026_10_11_000005_create_report_delivery_controls.php');
        $deliveryMigration->down();
        $reportMigration->down();
        $finalizationMigration->down();
        $validationMigration->down();
        $migration->down();
        $physical->down();
        $composition->down();
        $composition->up();
        $physical->up();
        $migration->up();
        $validationMigration->up();
        $finalizationMigration->up();
        $reportMigration->up();
        $deliveryMigration->up();
        $this->getJson($url)->assertOk()->assertJsonPath('data.parameters', [])->assertJsonPath('data.capture_available', false);
        $this->putJson('/api/v1/laboratory/validation-policy', ['validation_policy' => 'permission_based'])->assertOk();
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertConflict()->assertJsonPath('code', 'result_validation_unavailable');
        $this->putJson($url, $this->input())->assertConflict()->assertJsonPath('code', 'result_capture_unavailable');
    }

    public function test_sql_cannot_change_identity_unit_option_or_audit(): void
    {
        [, , , , $url] = $this->scenario();
        $this->putJson($url, $this->input())->assertOk();
        foreach ([fn () => DB::table('laboratory_order_results')->update(['unit' => 'OTHER', 'capture_revision' => 2]),
            fn () => DB::table('laboratory_order_results')->update(['parameter_code' => 'T', 'capture_revision' => 2]),
            fn () => DB::table('laboratory_order_results')->update(['numeric_value' => 'NaN', 'capture_revision' => 2]),
            fn () => DB::table('audit_logs')->where('event', 'order_results.saved')->delete()] as $write) {
            try {
                DB::transaction(function () use ($write): void {
                    DB::table('laboratory_order_result_captures')->update(['revision' => 2]);
                    $write();
                });
                $this->fail('SQL must reject mutation');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
    }
}
