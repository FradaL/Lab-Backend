<?php

namespace Tests\Feature\ClinicalReplacements;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\ClinicalReplacements\ReplacementAuthority;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratorySample;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ClinicalReplacementTest extends TestCase
{
    use RefreshDatabase, ReplacementScenario;

    public function test_intention_without_container_preserves_processing_order_and_terminal_state(): void
    {
        [$lab, $actor, $order, $line, $requirement, , , $url] = $this->replacementScenario();
        $before = $order->fresh()->getRawOriginal();
        $this->postJson($url.'/intentions', $this->intentionBody($requirement))->assertCreated()->assertJsonPath('data.revision', 2);
        $state = $this->getJson($url)->assertOk()->assertJsonPath('data.intention_states.0.status', 'pending')->assertJsonPath('data.intention_states.0.current_outcome', null);
        $this->assertSame(1, $state->json('data.events.meta.total'));
        $this->assertDatabaseCount('laboratory_samples', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('clinical_order_finalizations', 0);
        $this->assertNull($line->fresh()->processing_started_at);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseHas('audit_logs', ['event' => 'clinical_replacement.recorded', 'user_id' => $actor->id]);
        $this->getJson($url.'?page=0')->assertUnprocessable();
    }

    public function test_rejected_sample_and_later_replacement_reuse_be05_without_automatic_acceptance(): void
    {
        [$lab, $actor, $order, $line, $requirement, $branch, $type, $url] = $this->replacementScenario();
        $samples = app(LaboratorySampleService::class);
        $input = ['idempotency_key' => 'old', 'sample_type_id' => $type->id, 'provenance' => 'patient_provided', 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id];
        $old = $samples->register($lab, $actor, $order->id, 'receiveNew', $input)['data']['id'];
        $samples->evaluate($lab, $actor, $order->id, $old, 'rejected', ['reason' => 'FICTICIO: deteriorada']);
        $intention = $this->postJson($url.'/intentions', $this->intentionBody($requirement, 0, $old))->assertCreated()->json('data.intention_id');
        $e = $this->postJson($url.'/evidence', $this->evidenceBody(2))->assertCreated()->json('data.evidence_id');
        $this->postJson($url.'/assessments', $this->assessmentBody(3, $intention, $e))->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.pending_viable_intention_ids.0', $intention);
        setPermissionsTeamId($lab->id);
        $new = $samples->register($lab, $actor, $order->id, 'receiveNew', array_replace($input, ['idempotency_key' => 'new']))['data']['id'];
        $link = $this->postJson($url.'/intentions/'.$intention.'/samples', ['expected_revision' => 4, 'occurred_at' => now()->toISOString(), 'sample_id' => $new, 'justification' => 'FICTICIO: nuevo recipiente'])->assertCreated()->json('data.link_id');
        $this->assertSame('rejected', LaboratorySample::findOrFail($old)->status);
        $this->assertSame('received', LaboratorySample::findOrFail($new)->status);
        $this->assertNotSame(LaboratorySample::findOrFail($old)->identifier, LaboratorySample::findOrFail($new)->identifier);
        $this->assertDatabaseHas('laboratory_sample_events', ['laboratory_sample_id' => $new, 'event' => 'associated']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.latest_assessments.0.requires_review', true)->assertJsonPath('data.intention_states.0.current_outcome', null);
        $body = ['expected_revision' => 6, 'occurred_at' => now()->toISOString(), 'link_id' => $link, 'justification' => 'FICTICIO: material disponible'];
        $this->postJson($url.'/intentions/'.$intention.'/fulfill', $body)->assertConflict()->assertJsonPath('code', 'replacement_material_not_adequate');
        setPermissionsTeamId($lab->id);
        $samples->evaluate($lab, $actor, $order->id, $new, 'accepted');
        $this->postJson($url.'/intentions/'.$intention.'/fulfill', $body)->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.intention_states.0.status', 'fulfilled');
        setPermissionsTeamId($lab->id);
        $samples->evaluate($lab, $actor, $order->id, $new, 'rejected', ['reason' => 'FICTICIO: revisión']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.intention_states.0.status', 'pending');
        $this->assertNull($line->fresh()->processing_started_at);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_viable_to_non_viable_uses_immutable_successors_and_never_resolves(): void
    {
        [$lab, $actor, $order, $line, $requirement, , , $url] = $this->replacementScenario();
        $i = $this->postJson($url.'/intentions', $this->intentionBody($requirement))->json('data.intention_id');
        $e = $this->postJson($url.'/evidence', $this->evidenceBody(2))->json('data.evidence_id');
        $a = $this->postJson($url.'/assessments', $this->assessmentBody(3, $i, $e))->assertCreated()->json('data.assessment_id');
        $before = DB::table('clinical_viability_assessments')->where('id', $a)->first();
        $this->postJson($url.'/assessments', $this->assessmentBody(3, $i, $e, $a, 'non_viable'))->assertConflict()->assertJsonPath('code', 'replacement_revision_conflict');
        $this->postJson($url.'/assessments', $this->assessmentBody(4, $i, $e, null, 'non_viable'))->assertConflict()->assertJsonPath('code', 'viability_predecessor_conflict');
        $b = $this->postJson($url.'/assessments', $this->assessmentBody(4, $i, $e, $a, 'non_viable'))->assertCreated()->json('data.assessment_id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.latest_assessments.0.id', $b)->assertJsonPath('data.latest_assessments.0.requires_review', false)->assertJsonPath('data.intention_states.0.current_outcome', 'non_viable')->assertJsonCount(0, 'data.pending_viable_intention_ids');
        $this->assertEquals($before, DB::table('clinical_viability_assessments')->where('id', $a)->first());
        $this->assertDatabaseCount('clinical_viability_assessments', 2);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
    }

    #[DataProvider('evidenceKinds')]
    public function test_structured_evidence_for_all_base_motives_is_independent_of_assessment_and_processing(string $kind): void
    {
        [, , , $line, , , , $url] = $this->replacementScenario();
        $this->postJson($url.'/evidence', $this->evidenceBody(0, $kind))->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.evidence.data.0.kind', $kind)->assertJsonCount(0, 'data.latest_assessments');
        $this->assertNull($line->fresh()->processing_started_at);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertDatabaseCount('laboratory_samples', 0);
    }

    public static function evidenceKinds(): array
    {
        return array_map(fn ($kind) => [$kind], array_keys(ClinicalReplacementService::EVIDENCE_FIELDS));
    }

    #[DataProvider('invalidEvidence')]
    public function test_evidence_does_not_accept_prose_in_place_of_typed_facts_or_fabricated_actors(array $overrides): void
    {
        [, , , , , , , $url] = $this->replacementScenario();
        $this->postJson($url.'/evidence', array_replace($this->evidenceBody(), $overrides))->assertUnprocessable();
        $this->assertDatabaseCount('clinical_line_evidence', 0);
        $this->assertDatabaseCount('clinical_replacement_contexts', 0);
    }

    public static function invalidEvidence(): array
    {
        return [[['kind' => 'unknown']], [['facts' => ['justification' => 'FICTICIO']]], [['facts' => ['problem' => ' ']]], [['recorded_by' => 1]], [['expected_revision' => '0']], [['kind' => 'patient_refusal', 'facts' => ['express_refusal' => false, 'observations' => 'FICTICIO']]], [['kind' => 'patient_refusal', 'facts' => ['express_refusal' => 'true', 'observations' => 'FICTICIO']]], [['occurred_at' => '2999-01-01T00:00:00Z']]];
    }

    public function test_continuation_and_technical_alternatives_have_separate_predecessor_chains_without_samples(): void
    {
        [, , , , , , , $url] = $this->replacementScenario();
        $e = $this->postJson($url.'/evidence', $this->evidenceBody(0, 'follow_up'))->json('data.evidence_id');
        $body = $this->assessmentBody(1, 1, $e);
        unset($body['intention_id']);
        $body['subject'] = 'continuation';
        $this->postJson($url.'/assessments', $body)->assertCreated();
        $technical = $this->postJson($url.'/evidence', $this->evidenceBody(2, 'technical_problem'))->assertCreated()->json('data.evidence_id');
        $alternatives = $this->postJson($url.'/evidence', $this->evidenceBody(3, 'alternatives'))->assertCreated()->json('data.evidence_id');
        $body = $this->assessmentBody(4, 1, $technical);
        unset($body['intention_id']);
        $body['subject'] = 'technical_alternatives';
        $body['evidence_ids'] = [$technical, $alternatives];
        $this->postJson($url.'/assessments', $body)->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.latest_assessments')->assertJsonPath('data.latest_assessments.0.requires_review', true)->assertJsonPath('data.latest_assessments.1.requires_review', false);
        $this->assertDatabaseCount('laboratory_samples', 0);
    }

    public function test_tenant_order_line_and_requirement_boundaries_apply_to_http_and_service(): void
    {
        [$lab, $actor, $order, $line, $requirement, , , $url] = $this->replacementScenario();
        $secondLine = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $actor);
        $this->postJson($url.'/intentions', $this->intentionBody($secondLine->sampleRequirements->sole()))->assertNotFound();
        $other = Laboratory::factory()->create();
        Subscription::factory()->for($other)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $foreign = $this->createLaboratoryMember($other);
        $this->assignDirectLaboratoryPermissions($foreign, $other, ReplacementAuthority::PERMISSIONS);
        $this->actingAs($foreign)->withHeader('X-Laboratory-ID', (string) $other->id)->getJson($url)->assertNotFound();
        $this->postJson($url.'/intentions', $this->intentionBody($requirement))->assertNotFound();
        setPermissionsTeamId($other->id);
        try {
            app(ClinicalReplacementService::class)->createIntention($other, $foreign, $order->id, $line->id, $this->intentionBody($requirement));
            $this->fail('Foreign tenant must fail.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('clinical_replacement_intents', 0);
        }
    }

    public function test_separate_permissions_and_owner_do_not_imply_clinical_authority(): void
    {
        [$lab, , , , $requirement, , , $url] = $this->replacementScenario();
        $owner = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($owner, $lab, 'owner');
        $this->actingAs($owner)->postJson($url.'/intentions', $this->intentionBody($requirement))->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->assignDirectLaboratoryPermissions($owner, $lab, [ReplacementAuthority::RECORD, ReplacementAuthority::VIEW]);
        $i = $this->postJson($url.'/intentions', $this->intentionBody($requirement))->assertCreated()->json('data.intention_id');
        $e = $this->postJson($url.'/evidence', $this->evidenceBody(2))->assertCreated()->json('data.evidence_id');
        $this->postJson($url.'/assessments', $this->assessmentBody(3, $i, $e))->assertForbidden();
        $this->assignDirectLaboratoryPermissions($owner, $lab, [ReplacementAuthority::EVALUATE]);
        $this->postJson($url.'/assessments', $this->assessmentBody(3, $i, $e))->assertCreated();
        $this->assertFalse($owner->can('orders.clinical_exceptions.approve'));
        $this->getJson($url)->assertOk();
    }

    #[DataProvider('immutableTables')]
    public function test_sql_cannot_update_or_delete_history_or_forge_the_revision_projection(string $table): void
    {
        [, , , , $requirement, , , $url] = $this->replacementScenario();
        $i = $this->postJson($url.'/intentions', $this->intentionBody($requirement))->json('data.intention_id');
        $e = $this->postJson($url.'/evidence', $this->evidenceBody(2))->json('data.evidence_id');
        $this->postJson($url.'/assessments', $this->assessmentBody(3, $i, $e))->assertCreated();
        foreach (['update', 'delete'] as $operation) {
            DB::beginTransaction();
            try {
                $q = DB::table($table);
                $operation === 'delete' ? $q->delete() : ($table === 'clinical_replacement_contexts' ? $q->update(['revision' => 999]) : $q->update(['recorded_by' => DB::raw('recorded_by')]));
                $this->fail('SQL history mutation must fail.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
        }
    }

    public static function immutableTables(): array
    {
        return array_map(fn ($table) => [$table], ['clinical_replacement_contexts', 'clinical_replacement_intents', 'clinical_replacement_events', 'clinical_line_evidence', 'clinical_viability_assessments']);
    }

    public function test_new_evidence_invalidates_old_dependencies_and_pagination_preserves_latest_assessment(): void
    {
        [$lab, $actor, $order, $line, $requirement, , , $url] = $this->replacementScenario();
        $service = app(ClinicalReplacementService::class);
        $i = $service->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement));
        $e = $service->recordEvidence($lab, $actor, $order->id, $line->id, $this->evidenceBody(2));
        $a = $service->assess($lab, $actor, $order->id, $line->id, $this->assessmentBody(3, $i['intention_id'], $e['evidence_id']));
        for ($revision = 4; $revision < 55; $revision++) {
            $service->recordEvidence($lab, $actor, $order->id, $line->id, $this->evidenceBody($revision));
        }
        $this->getJson($url.'?page=2')->assertOk()->assertJsonPath('data.evidence.meta.total', 52)->assertJsonCount(2, 'data.evidence.data')
            ->assertJsonPath('data.latest_assessments.0.id', $a['assessment_id'])->assertJsonPath('data.latest_assessments.0.requires_review', true)->assertJsonPath('data.intention_states.0.current_outcome', null);
    }

    public function test_late_audit_failure_rolls_back_records_and_revision(): void
    {
        [, , , , $requirement, , , $url] = $this->replacementScenario();
        AuditLog::creating(function ($log): void {
            if ($log->event === 'clinical_replacement.recorded') {
                throw new RuntimeException('Synthetic audit failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson($url.'/intentions', $this->intentionBody($requirement));
            $this->fail('Audit failure must abort the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('clinical_replacement_intents', 0);
        $this->assertDatabaseCount('clinical_replacement_events', 0);
        $this->assertDatabaseCount('clinical_replacement_contexts', 0);
    }
}
