<?php

namespace Tests\Feature\ClinicalValidation;

use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\LaboratoryResultValidation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class ClinicalValidationTest extends TestCase
{
    use ClinicalResultScenario, RefreshDatabase;

    protected function complete(): array
    {
        [$lab,$actor,$order,$line,$url] = $this->scenario();
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [
            ['parameter_code' => 'N', 'value' => '12345678901234567890.123456789'], ['parameter_code' => 'T', 'value' => 'ficticio'], ['parameter_code' => 'Q', 'value' => '01'], ['parameter_code' => 'O', 'value' => 'A']]]);

        return [$lab, $actor, $order, $line, $url];
    }

    public function test_permission_validation_rectification_and_immutable_history(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->complete();
        $this->getJson('/api/v1/laboratory/validation-policy')->assertOk()->assertJsonPath('data.validation_policy', 'professional_required');
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertForbidden();
        $this->putJson('/api/v1/laboratory/validation-policy', ['validation_policy' => 'permission_based'])->assertOk();
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertOk()->assertJsonPath('data.clinical_status', 'validated')->assertJsonPath('data.revision', 2);
        $v = LaboratoryResultValidation::sole();
        $this->assertSame('12345678901234567890.123456789', $v->results_snapshot[0]['value']);
        $this->assertNull($v->professional_id);
        $this->assertSame('not_configured', $v->reference_snapshot[0]['status']);
        $this->putJson($url, ['revision' => 2, 'results' => [['parameter_code' => 'N', 'value' => '2']]])->assertConflict()->assertJsonPath('code', 'result_not_editable');
        $this->postJson($url.'/validate', ['revision' => 2, 'reference_context' => ['reference_date' => '2026-10-11']])->assertConflict();
        $this->postJson($url.'/rectify', ['revision' => 2, 'reason' => 'FICTICIO: corregir'])->assertOk()->assertJsonPath('data.revision', 3);
        $this->putJson($url, ['revision' => 3, 'results' => [['parameter_code' => 'N', 'value' => '2']]])->assertOk()->assertJsonPath('data.progress.revision', 4);
        $this->postJson($url.'/validate', ['revision' => 4, 'reference_context' => ['reference_date' => '2026-10-11']])->assertOk();
        $this->getJson($url.'/validations')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($v->results_snapshot, $v->fresh()->results_snapshot);
        $this->assertSame('in_process', $order->fresh()->status);
        $this->assertDatabaseCount('laboratory_result_rectifications', 1);
    }

    public function test_professional_identity_authorization_and_revocation(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->complete();
        $admin = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($admin, $lab, ['results.professionals.manage']);
        $service = app(ValidationAdministrationService::class);
        $p = $service->register($lab, $admin, ['user_id' => $actor->id, 'professional_name' => 'FICTICIO', 'profession' => 'DEMO']);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertForbidden();
        $this->postJson("/api/v1/laboratory/validation-professionals/$p->id/authorize", [])->assertForbidden();
        setPermissionsTeamId($lab->id);
        $service->professionalState($lab, $admin, $p->id, true);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertOk();
        $v = LaboratoryResultValidation::sole();
        $this->assertSame($p->id, $v->professional_id);
        setPermissionsTeamId($lab->id);
        $service->professionalState($lab, $admin, $p->id, false, ['reason' => 'FICTICIO: revocar']);
        $this->postJson($url.'/rectify', ['revision' => 2, 'reason' => 'corregir'])->assertForbidden();
        $this->assertSame('FICTICIO', $v->fresh()->professional_snapshot['professional_name']);
    }

    public function test_incomplete_stale_and_unknown_fields_are_rejected(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->scenario();
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertConflict();
        setPermissionsTeamId($lab->id);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1']]]);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertConflict()->assertJsonPath('code', 'result_validation_incomplete');
        $this->postJson($url.'/validate', ['revision' => 0, 'reference_context' => ['reference_date' => '2026-10-11']])->assertUnprocessable();
        $this->postJson($url.'/validate', ['revision' => 1, 'validation_policy' => 'permission_based', 'reference_context' => ['reference_date' => '2026-10-11']])->assertUnprocessable();
        $this->assertDatabaseCount('laboratory_result_validations', 0);
    }

    public function test_postgres_rejects_mutating_validation_evidence(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->complete();
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']])->assertOk();
        $this->expectException(QueryException::class);
        DB::table('laboratory_result_validations')->update(['comment' => 'altered']);
    }
}
