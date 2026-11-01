<?php

namespace Tests\Feature\ClinicalValidation;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryResultValidation;
use App\Models\Patient;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class ValidationSecurityTest extends TestCase
{
    use ClinicalResultScenario, RefreshDatabase;

    private function filled(array $overrides = []): array
    {
        [$lab,$actor,$order,$line,$url] = $this->scenario(true, $overrides);
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'DEMO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);

        return [$lab, $actor, $order, $line, $url];
    }

    #[DataProvider('denials')]
    public function test_validation_checks_effective_authorization_and_line_state(string $case, int $status): void
    {
        [$lab,$actor,$order,$line,$url] = $this->filled();
        if ($case === 'inactive') {
            $actor->update(['is_active' => false]);
        }
        if ($case === 'membership') {
            $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => false]);
        }
        if ($case === 'permission') {
            $actor->revokePermissionTo('results.validate');
        }
        if (in_array($case, ['owner', 'administrator'])) {
            $actor->syncPermissions([]);
            $role = $this->assignLaboratoryRole($actor, $lab, $case);
            $role->syncPermissions(RbacCatalog::rolePermissions()[$case]);
            // An existing owner matrix can lack this permission; role name alone must not bypass authorization.
            $role->revokePermissionTo('results.validate');
        }
        if ($case === 'cancelled') {
            $order->update(['status' => 'cancelled']);
        }
        if ($case === 'pending') {
            $order->fresh()->update(['status' => 'pending']);
        }
        if ($case === 'other_lab') {
            $other = Laboratory::factory()->create();
            $otherActor = $this->createLaboratoryMember($other);
            $this->assignDirectLaboratoryPermissions($otherActor, $other, RbacCatalog::PERMISSIONS);
            Subscription::factory()->for($other)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
            $this->actingAs($otherActor)->withHeader('X-Laboratory-ID', (string) $other->id);
        }
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertStatus($status);
        $this->assertDatabaseCount('laboratory_result_validations', 0);
    }

    public static function denials(): array
    {
        return [['inactive', 403], ['membership', 403], ['permission', 403], ['owner', 403], ['administrator', 403], ['cancelled', 409], ['pending', 409], ['other_lab', 404]];
    }

    public function test_default_owner_can_validate_but_still_requires_an_authorized_professional_when_policy_demands_it(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->filled();
        $actor->syncPermissions([]);
        $this->assignLaboratoryRole($actor, $lab, 'owner')->syncPermissions(RbacCatalog::rolePermissions()['owner']);
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'professional_required']);
        $body = ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']];
        $this->postJson($url.'/validate', $body)->assertForbidden();
        $this->assertDatabaseCount('laboratory_result_validations', 0);
        setPermissionsTeamId($lab->id);
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        $this->postJson($url.'/validate', $body)->assertOk();
        $this->assertDatabaseCount('laboratory_result_validations', 1);
    }

    public function test_missing_reference_context_and_historical_snapshot(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->filled(['N' => ['references' => [['key' => 'DEMO-AGE', 'priority' => 1, 'age_min_days' => 0, 'sex' => 'CLINICAL-DEMO', 'conditions' => ['demo' => 'yes'], 'min' => 0]]]]);
        $patient = Patient::findOrFail($order->patient_id);
        $patient->update(['birth_date' => '2000-01-01', 'gender' => 'CLINICAL-DEMO']);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertConflict()->assertJsonPath('code', 'result_reference_unresolved');
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11', 'sex' => 'CLINICAL-DEMO', 'conditions' => ['demo' => 'yes'], 'confirm_birth_date' => true]])->assertOk();
        $evidence = LaboratoryResultValidation::sole();
        $this->assertSame('patient.birth_date', $evidence->context_snapshot['sources']['age_days']['source']);
        $this->assertSame($actor->id, $evidence->context_snapshot['sources']['age_days']['confirmed_by']);
        $this->assertSame('DEMO-AGE', $evidence->reference_snapshot[0]['reference_key']);
        $before = $evidence->toArray();
        $patient->update(['birth_date' => '2005-01-01', 'gender' => 'changed']);
        setPermissionsTeamId($lab->id);
        $clinical = app(ClinicalExamService::class);
        $draft = $clinical->fork($lab, $actor, $line->laboratory_exam_id, $line->clinical_version_id);
        $config = $draft->configuration;
        $config['parameters'][0]['references'][0]['key'] = 'NEW-DEMO';
        $draft = $clinical->update($lab, $actor, $line->laboratory_exam_id, $draft->id, 1, $config);
        $clinical->publish($lab, $actor, $line->laboratory_exam_id, $draft->id, $draft->revision);
        $this->assertSame($before, $evidence->fresh()->toArray());
        $this->getJson($url)->assertOk()->assertJsonPath('data.applied_references.0.reference_key', 'DEMO-AGE')->assertJsonPath('data.permissions.can_capture', false);
        $this->getJson("/api/v1/laboratory-orders/$order->id/operational")->assertOk()->assertJsonPath('data.exams.0.result_capture.clinical_status', 'validated')->assertJsonMissing(['value' => '1']);
    }

    public function test_context_contradiction_is_rejected_without_writes(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->filled(['N' => ['references' => [['key' => 'DEMO', 'priority' => 1, 'age_min_days' => 0, 'min' => 0]]]]);
        Patient::findOrFail($order->patient_id)->update(['birth_date' => '2000-01-01']);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '1999-01-01']])->assertConflict()->assertJsonPath('code', 'result_reference_unresolved');
        $this->assertDatabaseCount('laboratory_result_validations', 0);
        $this->assertDatabaseHas('laboratory_order_result_captures', ['laboratory_order_exam_id' => $line->id, 'revision' => 1]);
    }

    public function test_audit_failure_rolls_back_validation_atomically(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->filled();
        AuditLog::creating(fn ($log) => $log->event === 'order_results.validated' ? throw new \RuntimeException('BE07 audit failure') : null);
        $this->withoutExceptionHandling();
        try {
            $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('BE07 audit failure', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('laboratory_result_validations', 0);
        $this->assertDatabaseHas('laboratory_order_result_captures', ['laboratory_order_exam_id' => $line->id, 'revision' => 1, 'status' => 'preliminary']);
    }

    public function test_independent_history_and_clinical_permissions_and_required_reason(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->filled();
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertOk();
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo('results.rectify');
        $this->postJson($url.'/rectify', ['revision' => 2, 'reason' => 'FICTICIO'])->assertForbidden();
        setPermissionsTeamId($lab->id);
        $actor->givePermissionTo('results.rectify');
        $this->postJson($url.'/rectify', ['revision' => 2])->assertUnprocessable();
        $this->postJson($url.'/rectify', ['revision' => 1, 'reason' => 'FICTICIO'])->assertConflict()->assertJsonPath('code', 'result_revision_conflict');
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo('results.view');
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'/validations')->assertOk()->assertJsonCount(1, 'data');
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo('results.validation_history');
        $this->getJson($url.'/validations')->assertForbidden();
    }

    public function test_context_literals_are_preserved_and_unrelated_data_is_not_required(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->filled(['N' => ['references' => [['key' => 'literal', 'priority' => 1, 'sex' => ' DEMO ', 'conditions' => ['demo' => ' exact '], 'min' => 0]]]]);
        Patient::findOrFail($order->patient_id)->update(['birth_date' => null]);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11', 'sex' => ' DEMO ', 'conditions' => ['demo' => ' exact ']]])
            ->assertOk();
        $evidence = LaboratoryResultValidation::sole();
        $this->assertSame(' DEMO ', $evidence->context_snapshot['sex']);
        $this->assertSame(' exact ', $evidence->context_snapshot['conditions']['demo']);
        $this->assertNull($evidence->context_snapshot['age_days']);
        $this->assertSame('resolved', $evidence->reference_snapshot[0]['status']);
    }

    public function test_a_different_role_with_explicit_permission_can_validate(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->filled();
        $actor->syncPermissions(['results.validate']);
        $this->assignLaboratoryRole($actor, $lab, 'viewer');
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertOk();
        $this->assertSame($actor->id, LaboratoryResultValidation::sole()->validated_by);
    }

    public function test_repeated_lines_validate_independently_and_unstarted_lines_are_blocked(): void
    {
        [$lab, $actor, $order, $first, $url] = $this->scenario(false);
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        $second = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $first->laboratory_exam_id, $actor);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertConflict()->assertJsonPath('code', 'result_validation_unavailable');
        setPermissionsTeamId($lab->id);
        foreach ([$first, $second] as $line) {
            app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
            app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'DEMO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        }
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertOk();
        $this->assertDatabaseCount('laboratory_result_validations', 1);
        $this->assertDatabaseHas('laboratory_order_result_captures', ['laboratory_order_exam_id' => $second->id, 'revision' => 1, 'status' => 'preliminary']);
        $this->getJson("/api/v1/laboratory-orders/$order->id/exams/$second->id/results/validations")->assertOk()->assertJsonCount(0, 'data');
    }
}
