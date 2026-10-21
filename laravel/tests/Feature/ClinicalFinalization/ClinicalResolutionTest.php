<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\ClinicalLineResolution;
use App\Models\LaboratoryResultValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class ClinicalResolutionTest extends TestCase
{
    use ClinicalResultScenario, RefreshDatabase;

    public function test_exception_approval_has_independent_actor_and_preserves_clinical_origin(): void
    {
        [$lab,$requester,$order,$line] = $this->scenario(false);
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve']);
        $service = app(ClinicalResolutionService::class);
        $request = $service->requestException($lab, $requester, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason' => 'FICTICIO: imposibilidad documentada']);
        $result = $service->decideException($lab, $approver, $order->id, $line->id, $request['exception']['id'], true, ['clinical_revision' => $request['clinical_revision'], 'request_revision' => 1]);
        $this->assertSame('approved', $result['exception']['status']);
        $this->assertSame('authorized_exception', ClinicalLineResolution::sole()->resolution_type);
        $this->assertSame($approver->id, ClinicalLineResolution::sole()->resolved_by);
        $this->assertDatabaseCount('laboratory_result_validations', 0);
        $this->assertDatabaseCount('laboratory_order_results', 0);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($line->fresh()->processing_started_at);
    }

    public function test_individual_finalization_fixes_validated_revision_without_altering_capture(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario();
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'FICTICIO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        $result = app(ClinicalResolutionService::class)->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
        $this->assertSame('validated_result', $result['resolution']['resolution_type']);
        $this->assertDatabaseHas('laboratory_order_result_captures', ['laboratory_order_exam_id' => $line->id, 'revision' => 2, 'status' => 'validated']);
        $this->assertSame('in_process', $order->fresh()->status);
    }

    public function test_pending_order_with_only_approved_exceptions_closes_explicitly(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario(false);
        $reviewer = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reviewer, $lab, ['orders.clinical_exceptions.approve']);
        $service = app(ClinicalResolutionService::class);
        $r = $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason' => 'FICTICIO: material no disponible']);
        $service->decideException($lab, $reviewer, $order->id, $line->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]);
        $before = $order->fresh()->only(['subtotal', 'discount', 'taxes', 'total', 'currency']);
        $service->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($before, $order->fresh()->only(array_keys($before)));
        $this->assertNull($line->fresh()->processing_started_at);
        $this->assertDatabaseCount('clinical_order_finalizations', 1);
    }

    public function test_professional_required_validation_is_preserved_when_finalizing_and_closing(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario();
        $admin = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($admin, $lab, ['results.professionals.manage']);
        setPermissionsTeamId($lab->id);
        $management = app(ValidationAdministrationService::class);
        $professional = $management->register($lab, $admin, ['user_id' => $actor->id, 'professional_name' => 'FICTICIO', 'profession' => 'DEMO']);
        $management->professionalState($lab, $admin, $professional->id, true);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'FICTICIO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        $evidence = LaboratoryResultValidation::sole();
        $before = $evidence->getRawOriginal();
        $service = app(ClinicalResolutionService::class);
        $service->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
        $service->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        $this->assertSame($before, $evidence->fresh()->getRawOriginal());
        $this->assertSame('professional_required', $evidence->validation_policy);
        $this->assertSame($professional->id, $evidence->professional_id);
        $this->assertSame($evidence->id, ClinicalLineResolution::sole()->validation_id);
        $this->assertDatabaseHas('laboratory_order_result_captures', ['laboratory_order_exam_id' => $line->id, 'revision' => 2]);
    }
}
