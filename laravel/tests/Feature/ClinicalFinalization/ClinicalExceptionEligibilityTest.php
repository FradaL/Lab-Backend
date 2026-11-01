<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalFinalization\ClinicalExceptionEvidenceGuard;
use App\ClinicalFinalization\ClinicalExceptionReasonGuard;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\Models\AuditLog;
use App\Models\ClinicalExceptionDecision;
use App\Models\ClinicalExceptionRequest;
use App\Models\LaboratorySample;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalExceptionEligibilityTest extends TestCase
{
    use ClinicalExceptionScenario, RefreshDatabase;

    public static function motives(): array
    {
        return array_combine(range('1', '7'), array_map(fn ($n) => ['m0'.$n], range(1, 7)));
    }

    #[DataProvider('motives')]
    public function test_missing_minimum_evidence_prevents_request_without_side_effects(string $code): void
    {
        $s = $this->prepared($code);
        $this->postJson($this->endpoint($s), $this->requestBody($s))->assertConflict()->assertJsonPath('code', 'clinical_evidence_missing');
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertNull($s['line']->fresh()->processing_started_at);
    }

    #[DataProvider('motives')]
    public function test_valid_evidence_for_each_motive_allows_independent_resolution_without_closing_order(string $code): void
    {
        $s = $this->prepared($code);
        if (in_array($code, ['m01', 'm02'], true)) {
            $sample = app(LaboratorySampleService::class)->register($s['lab'], $s['actor'], $s['order']->id, 'receiveNew', ['idempotency_key' => 'ficticio', 'sample_type_id' => $s['type']->id, 'provenance' => 'patient_provided', 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $s['branch']->id])['data']['id'];
            $intent = $this->intention($s, $sample);
            $e = $this->evidence($s, 'sample_problem', ['sample_requirement_id' => $s['requirement']->id, 'sample_id' => $sample]);
            $this->assess($s, [$e], intention: $intent);
        } elseif ($code === 'm03') {
            $this->evidence($s, 'patient_refusal');
        } elseif ($code === 'm04') {
            $e = $this->evidence($s, 'follow_up');
            $this->assess($s, [$e], 'continuation');
        } elseif ($code === 'm05') {
            $this->evidence($s, 'medical_instruction');
        } else {
            $e = $this->evidence($s, 'technical_problem');
            $alternatives = $this->evidence($s, 'alternatives');
            $this->assess($s, [$e, $alternatives], 'technical_alternatives');
        }
        $id = $this->request($s);
        $this->approve($s, $id)->assertOk();
        $this->assertDatabaseHas('clinical_line_resolutions', ['resolution_type' => 'authorized_exception', 'laboratory_order_exam_id' => $s['line']->id]);
        $this->assertDatabaseCount('clinical_exception_decisions', 1);
        $this->assertDatabaseCount('clinical_order_finalizations', 0);
        $this->assertSame('pending', $s['order']->fresh()->status);
        $this->assertNull($s['line']->fresh()->processing_started_at);
        $this->assertDatabaseCount('laboratory_order_result_captures', 0);
    }

    public function test_macario_scenario_m03_request_dispensation_does_not_allow_pending_viable_replacement_to_resolve(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $intent = $this->intention($s);
        $e = $this->evidence($s, 'sample_problem');
        $this->assess($s, [$e], intention: $intent, outcome: 'viable');
        $id = $this->request($s);
        $this->approve($s, $id)->assertConflict()->assertJsonPath('code', 'clinical_replacement_pending_viable');
        $this->assertDatabaseHas('clinical_exception_requests', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertDatabaseCount('clinical_order_finalizations', 0);
    }

    public function test_successor_non_viable_requires_explicit_review_and_keeps_original_history(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $intent = $this->intention($s);
        $e = $this->evidence($s, 'sample_problem');
        $first = $this->assess($s, [$e], intention: $intent, outcome: 'viable');
        $id = $this->request($s);
        $original = DB::table('clinical_exception_evidence_reviews')->first();
        $this->assess($s, [$e], intention: $intent, previous: $first);
        $this->approve($s, $id)->assertConflict()->assertJsonPath('code', 'clinical_exception_review_required');
        $status = app(ClinicalExceptionEvidenceGuard::class)->status($s['lab'], $s['order']->id, $s['line']->id, $s['version'], ClinicalExceptionRequest::findOrFail($id), true);
        $this->postJson($this->endpoint($s)."/$id/review", ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1, 'dependency_token' => $status['dependency_token'], 'justification' => 'FICTICIO: dependencias revisadas'])->assertOk()->assertJsonPath('data.clinical_eligibility.requires_review', false);
        $this->approve($s, $id)->assertOk();
        $this->assertSame((array) $original, (array) DB::table('clinical_exception_evidence_reviews')->where('id', $original->id)->first());
        $this->assertDatabaseCount('clinical_viability_assessments', 2);
        $this->assertDatabaseCount('clinical_exception_evidence_reviews', 2);
    }

    public function test_incidence_without_processing_and_m03_without_assessment_remain_separate_from_approval(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $this->getJson("/api/v1/laboratory-orders/{$s['order']->id}/clinical-finalization")->assertOk()->assertJsonPath('data.lines.0.incidence.status', 'pending')->assertJsonPath('data.lines.0.incidence.processing_started', false);
        $id = $this->request($s);
        $this->assertDatabaseCount('clinical_viability_assessments', 0);
        $this->postJson($this->endpoint($s)."/$id/approve", ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1])->assertForbidden();
        $this->approve($s, $id)->assertOk();
    }

    public function test_received_replacement_without_acceptance_cannot_allow_terminal_approval(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $intent = $this->intention($s);
        $e = $this->evidence($s, 'sample_problem');
        $first = $this->assess($s, [$e], intention: $intent, outcome: 'viable');
        $sample = app(LaboratorySampleService::class)->register($s['lab'], $s['actor'], $s['order']->id, 'receiveNew', ['idempotency_key' => 'ficticio-recibida', 'sample_type_id' => $s['type']->id, 'provenance' => 'patient_provided', 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $s['branch']->id])['data']['id'];
        app(ClinicalReplacementService::class)->linkSample($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, $intent, ['expected_revision' => $this->revision($s), 'occurred_at' => now()->subMinute()->toISOString(), 'sample_id' => $sample, 'justification' => 'FICTICIO: material recibido']);
        $this->assess($s, [$e], intention: $intent, outcome: 'viable', previous: $first);
        $id = $this->request($s);
        $this->approve($s, $id)->assertConflict()->assertJsonPath('code', 'clinical_replacement_pending_viable');
        $this->assertSame('received', LaboratorySample::findOrFail($sample)->status);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_m01_viable_replacement_prevents_even_request(): void
    {
        $s = $this->prepared('m01');
        $intent = $this->intention($s);
        $e = $this->evidence($s, 'sample_problem');
        $this->assess($s, [$e], intention: $intent, outcome: 'viable');
        $this->postJson($this->endpoint($s), $this->requestBody($s))->assertConflict()->assertJsonPath('code', 'clinical_replacement_pending_viable');
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public function test_m03_can_request_with_unevaluated_intention_but_cannot_approve_it(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $this->intention($s);
        $id = $this->request($s);
        $this->approve($s, $id)->assertConflict()->assertJsonPath('code', 'clinical_evidence_missing');
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_new_evidence_marks_assessment_obsolete_and_cannot_be_reviewed_away(): void
    {
        $s = $this->prepared('m04');
        $e = $this->evidence($s, 'follow_up');
        $this->assess($s, [$e], 'continuation');
        $id = $this->request($s);
        $this->evidence($s, 'follow_up');
        $this->approve($s, $id)->assertConflict()->assertJsonPath('code', 'clinical_viability_obsolete');
        $status = app(ClinicalExceptionEvidenceGuard::class)->status($s['lab'], $s['order']->id, $s['line']->id, $s['version'], ClinicalExceptionRequest::findOrFail($id), true);
        $this->postJson($this->endpoint($s)."/$id/review", ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1, 'dependency_token' => $status['dependency_token'], 'justification' => 'FICTICIO'])->assertConflict()->assertJsonPath('code', 'clinical_viability_obsolete');
        $this->assertDatabaseCount('clinical_exception_evidence_reviews', 1);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
    }

    public function test_internal_service_cannot_approve_after_dependencies_change(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        $this->evidence($s, 'patient_refusal');
        setPermissionsTeamId($s['lab']->id);
        try {
            app(ClinicalResolutionService::class)->decideException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $id, true, ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1]);
            $this->fail('Internal approval must revalidate clinical dependencies.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('clinical_exception_review_required', $e->errorCode);
        }
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_unconfigured_reason_cannot_use_empty_requirements_to_bypass_evidence(): void
    {
        $s = $this->prepared('ficticio_sin_politica');
        $this->evidence($s, 'medical_instruction');
        $this->postJson($this->endpoint($s), $this->requestBody($s))->assertConflict()->assertJsonPath('code', 'clinical_evidence_policy_unconfigured');
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public function test_review_rejects_unobserved_token_and_requires_evaluator_permission(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        $this->evidence($s, 'patient_refusal');
        $body = ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1, 'dependency_token' => str_repeat('0', 64), 'justification' => 'FICTICIO'];
        $this->actingAs($s['approver'])->postJson($this->endpoint($s)."/$id/review", $body)->assertConflict()->assertJsonPath('code', 'clinical_exception_dependencies_conflict');
        setPermissionsTeamId($s['lab']->id);
        $s['approver']->revokePermissionTo('orders.clinical_replacements.evaluate');
        $this->postJson($this->endpoint($s)."/$id/review", $body)->assertForbidden();
        $this->assertDatabaseCount('clinical_exception_evidence_reviews', 1);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_review_and_approval_do_not_expose_another_tenants_request(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        [$other, $actor] = $this->replacementScenario();
        $body = ['clinical_revision' => 0, 'request_revision' => 1, 'dependency_token' => str_repeat('0', 64), 'justification' => 'FICTICIO'];
        $this->postJson($this->endpoint($s)."/$id/review", $body)->assertNotFound();
        $this->postJson($this->endpoint($s)."/$id/approve", array_intersect_key($body, array_flip(['clinical_revision', 'request_revision'])))->assertNotFound();
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_failed_approval_remains_rejectable_and_review_history_is_immutable(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        $this->evidence($s, 'patient_refusal');
        $this->approve($s, $id)->assertConflict();
        try {
            DB::transaction(fn () => DB::table('clinical_exception_evidence_reviews')->where('exception_request_id', $id)->update(['justification' => 'FICTICIO: sobrescritura']));
            $this->fail('Review history must be immutable.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->postJson($this->endpoint($s)."/$id/reject", ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1, 'reason' => 'FICTICIO: rechazo clínico'])->assertOk();
        $this->assertDatabaseHas('clinical_exception_requests', ['id' => $id, 'status' => 'rejected']);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_sql_cannot_insert_approved_decision_for_a_current_but_viable_request(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $intent = $this->intention($s);
        $e = $this->evidence($s, 'sample_problem');
        $this->assess($s, [$e], intention: $intent, outcome: 'viable');
        $id = $this->request($s);
        try {
            DB::transaction(fn () => ClinicalExceptionDecision::create(['laboratory_id' => $s['lab']->id, 'laboratory_order_id' => $s['order']->id, 'laboratory_order_exam_id' => $s['line']->id, 'exception_request_id' => $id, 'decision' => 'approved', 'decided_by' => $s['approver']->id, 'decided_at' => now(), 'evidence_snapshot' => ['synthetic' => true]]));
            $this->fail('A direct approved decision must not evade pending viability.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('Pending replacement requires a current non viable clinical assessment', $e->getMessage());
        }
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_approval_audit_failure_rolls_back_decision_resolution_and_request_status(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        AuditLog::creating(function ($event): void {
            if ($event->event === 'order_clinical.exception_approved') {
                throw new \RuntimeException('FICTICIO: fallo auditoría');
            }
        });
        setPermissionsTeamId($s['lab']->id);
        try {
            app(ClinicalResolutionService::class)->decideException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $id, true, ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1]);
            $this->fail('Audit failure must roll back approval.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FICTICIO: fallo auditoría', $e->getMessage());
        }
        $this->assertDatabaseHas('clinical_exception_requests', ['id' => $id, 'status' => 'pending', 'revision' => 1]);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertDatabaseCount('clinical_exception_evidence_reviews', 1);
    }

    public function test_exception_history_reports_current_terminal_blockers_for_the_viewer(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        $this->getJson($this->endpoint($s))->assertOk()->assertJsonPath('data.0.clinical_eligibility.eligible', false)->assertJsonPath('data.0.clinical_eligibility.blocking_reasons.0', 'clinical_exception_self_approval');
        setPermissionsTeamId($s['lab']->id);
        $this->assignDirectLaboratoryPermissions($s['approver'], $s['lab'], ['orders.clinical_exceptions.view']);
        $this->actingAs($s['approver'])->getJson($this->endpoint($s))->assertOk()->assertJsonPath('data.0.clinical_eligibility.eligible', true)->assertJsonPath('data.0.clinical_eligibility.requires_review', false);
        $this->evidence($s, 'patient_refusal');
        $this->getJson($this->endpoint($s))->assertOk()->assertJsonPath('data.0.clinical_eligibility.eligible', false)->assertJsonPath('data.0.clinical_eligibility.requires_review', true);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
    }

    public function test_openapi_documents_incidence_terminal_eligibility_and_explicit_dependency_review(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ClinicalExceptionEligibility', $document['components']['schemas']);
        $this->assertArrayHasKey('ClinicalIncidence', $document['components']['schemas']);
        $this->assertSame('be04reviewClinicalExceptionEvidence', $document['paths']['/api/v1/laboratory-orders/{order}/exams/{line}/exceptions/{exception}/review']['post']['operationId']);
        $this->assertFalse($document['components']['schemas']['ClinicalExceptionEvidenceReviewInput']['additionalProperties']);
    }

    public static function viableAlternatives(): array
    {
        return [['m04', 'continuation', ['follow_up']], ['m06', 'technical_alternatives', ['technical_problem', 'alternatives']], ['m07', 'technical_alternatives', ['technical_problem', 'alternatives']]];
    }

    #[DataProvider('viableAlternatives')]
    public function test_viable_continuation_or_technical_alternative_prevents_request(string $code, string $subject, array $kinds): void
    {
        $s = $this->prepared($code);
        $ids = array_map(fn ($kind) => $this->evidence($s, $kind), $kinds);
        $this->assess($s, $ids, $subject, outcome: 'viable');
        $this->postJson($this->endpoint($s), $this->requestBody($s))->assertConflict()->assertJsonPath('code', 'clinical_'.$subject.'_viable');
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_structured_pending_without_initial_evidence_snapshot_requires_explicit_current_review(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $request = ClinicalExceptionRequest::create(['laboratory_id' => $s['lab']->id, 'laboratory_order_id' => $s['order']->id, 'laboratory_order_exam_id' => $s['line']->id, 'reason' => $s['version']->name, 'status' => 'pending', 'revision' => 1, 'requested_by' => $s['actor']->id, 'requested_at' => now(), 'capture_revision' => 0, 'processing_started_at' => null, 'clinical_configuration_source' => $s['line']->clinical_configuration_source]);
        app(ClinicalExceptionReasonGuard::class)->remember($s['lab'], $s['actor'], $request, $s['reference'], 'FICTICIO: referencia original');
        $before = $request->fresh()->getRawOriginal();
        $this->approve($s, $request->id)->assertConflict()->assertJsonPath('code', 'clinical_exception_review_required');
        $status = app(ClinicalExceptionEvidenceGuard::class)->status($s['lab'], $s['order']->id, $s['line']->id, $s['version'], $request, true);
        $this->postJson($this->endpoint($s)."/$request->id/review", ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1, 'dependency_token' => $status['dependency_token'], 'justification' => 'FICTICIO: revisión actual sin reconstruir evidencia original'])->assertOk();
        $this->assertSame($before, $request->fresh()->getRawOriginal());
        $this->assertDatabaseHas('clinical_exception_evidence_reviews', ['exception_request_id' => $request->id, 'kind' => 'review']);
        $this->approve($s, $request->id)->assertOk();
    }

    public function test_explicit_review_advances_clinical_revision_so_an_older_approval_view_cannot_decide(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        $oldRevision = $s['order']->fresh()->clinical_revision;
        $this->evidence($s, 'patient_refusal');
        $status = app(ClinicalExceptionEvidenceGuard::class)->status($s['lab'], $s['order']->id, $s['line']->id, $s['version'], ClinicalExceptionRequest::findOrFail($id), true);
        $this->actingAs($s['approver'])->postJson($this->endpoint($s)."/$id/review", ['clinical_revision' => $oldRevision, 'request_revision' => 1, 'dependency_token' => $status['dependency_token'], 'justification' => 'FICTICIO: revisión explícita'])->assertOk()->assertJsonPath('data.clinical_revision', $oldRevision + 1);
        $this->postJson($this->endpoint($s)."/$id/approve", ['clinical_revision' => $oldRevision, 'request_revision' => 1])->assertConflict()->assertJsonPath('code', 'clinical_revision_conflict');
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->approve($s, $id)->assertOk();
    }
}
