<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class ClinicalFinalizationApiTest extends TestCase
{
    use ClinicalResultScenario,RefreshDatabase;

    public function validated(): array
    {
        [$lab,$actor,$order,$line,$url] = $this->scenario();
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'FICTICIO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);

        return [$lab, $actor, $order, $line, $url];
    }

    public function test_validated_finalization_and_closed_protection(): void
    {
        [$lab,$actor,$order,$line,$url] = $this->validated();
        $base = "/api/v1/laboratory-orders/$order->id";
        $this->postJson($base.'/finalize', ['clinical_revision' => $order->fresh()->clinical_revision])->assertConflict()->assertJsonPath('code', 'clinical_order_unresolved');
        $this->postJson($base."/exams/$line->id/finalize", ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2])->assertOk();
        $this->postJson($url.'/rectify', ['revision' => 2, 'reason' => 'FICTICIO'])->assertConflict()->assertJsonPath('code', 'clinical_line_resolved');
        $this->getJson($base.'/operational')->assertOk()->assertJsonPath('data.exams.0.clinical_resolution.resolution_type', 'validated_result')->assertJsonMissing(['value' => '1']);
        $before = $order->fresh()->only(['subtotal', 'discount', 'taxes', 'total', 'currency']);
        $this->postJson($base.'/finalize', ['clinical_revision' => $order->fresh()->clinical_revision])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame($before, $order->fresh()->only(array_keys($before)));
        $this->getJson($base.'/clinical-finalization')->assertOk()->assertJsonPath('data.clinical_finalization.status', 'finalized')->assertJsonPath('data.clinical_finalization.validated_lines', 1);
        $this->postJson($base.'/finalize', ['clinical_revision' => $order->fresh()->clinical_revision])->assertConflict()->assertJsonPath('code', 'clinical_order_finalized');
        $this->postJson($url.'/rectify', ['revision' => 2, 'reason' => 'FICTICIO'])->assertConflict();
        $this->patchJson($base.'/status', ['status' => 'pending'])->assertUnprocessable();
    }

    public function test_exception_request_approval_and_history_are_explicit(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario(false);
        $base = "/api/v1/laboratory-orders/$order->id/exams/$line->id";
        $revision = $order->fresh()->clinical_revision;
        $r = $this->postJson($base.'/exceptions', ['clinical_revision' => $revision, 'result_revision' => 0, 'reason_reference' => $this->exceptionReference($lab, $actor), 'justification' => 'FICTICIO: no disponible'])->assertCreated();
        $id = $r->json('data.exception.id');
        $revision = $r->json('data.clinical_revision');
        $this->postJson($base."/exceptions/$id/approve", ['clinical_revision' => $revision, 'request_revision' => 1])->assertForbidden();
        $this->postJson($base.'/exceptions', ['clinical_revision' => $revision, 'result_revision' => 0, 'reason_reference' => $this->exceptionReference($lab, $actor), 'justification' => 'FICTICIO duplicado'])->assertConflict()->assertJsonPath('code', 'clinical_exception_pending');
        $reviewer = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reviewer, $lab, ['orders.clinical_exceptions.approve', 'orders.clinical_exceptions.view']);
        $this->actingAs($reviewer);
        $this->postJson($base."/exceptions/$id/approve", ['clinical_revision' => $revision, 'request_revision' => 1])->assertOk()->assertJsonPath('data.exception.status', 'approved');
        $this->getJson($base.'/exceptions')->assertOk()->assertJsonPath('data.0.status', 'approved');
        $this->assertDatabaseCount('laboratory_result_validations', 0);
        $this->assertDatabaseCount('laboratory_order_results', 0);
    }

    #[DataProvider('invalid')]
    public function test_invalid_requests_do_not_create_evidence(array $input): void
    {
        [$lab,$actor,$order,$line] = $this->scenario(false);
        $this->postJson("/api/v1/laboratory-orders/$order->id/exams/$line->id/exceptions", $input)->assertUnprocessable();
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public static function invalid(): array
    {
        return [[['clinical_revision' => 1, 'result_revision' => 0]], [['clinical_revision' => '1', 'result_revision' => 0, 'reason' => 'FICTICIO']], [['clinical_revision' => 1, 'result_revision' => 0, 'reason' => ' ']], [['clinical_revision' => 1, 'result_revision' => 0, 'reason' => 'FICTICIO', 'requested_by' => 1]]];
    }
}
