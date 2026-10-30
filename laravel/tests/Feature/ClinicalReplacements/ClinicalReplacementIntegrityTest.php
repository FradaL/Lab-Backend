<?php

namespace Tests\Feature\ClinicalReplacements;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ClinicalReplacementIntegrityTest extends TestCase
{
    use RefreshDatabase, ReplacementScenario;

    private function assertSqlRejected(\Closure $operation, string $state = '23514'): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('The invalid SQL write must fail.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }

    #[DataProvider('invalidAssessments')]
    public function test_sql_cannot_insert_obsolete_or_misrelated_assessments(string $change): void
    {
        [$lab, $actor, $order, $line, $requirement, , , $url] = $this->replacementScenario();
        $service = app(ClinicalReplacementService::class);
        $i = $service->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement));
        $e = $service->recordEvidence($lab, $actor, $order->id, $line->id, $this->evidenceBody(2));
        $a = $service->assess($lab, $actor, $order->id, $line->id, $this->assessmentBody(3, $i['intention_id'], $e['evidence_id']));
        $record = (array) DB::table('clinical_viability_assessments')->where('id', $a['assessment_id'])->first();
        unset($record['id']);
        $record['previous_id'] = $a['assessment_id'];
        $record['revision'] = 2;
        switch ($change) {
            case 'predecessor': $record['previous_id'] = null;
                break;
            case 'snapshot': $record['dependency_snapshot'] = '{}';
                break;
            case 'evidence': $record['evidence_ids'] = '[999999]';
                break;
            case 'wrong_line_requirement':
                $otherLine = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $actor);
                $record['sample_requirement_id'] = $otherLine->sampleRequirements->sole()->id;
                break;
            case 'wrong_subject': $record['subject'] = 'continuation';
                break;
        }
        $this->assertSqlRejected(fn () => DB::table('clinical_viability_assessments')->insert($record));
        $this->assertDatabaseCount('clinical_viability_assessments', 1);
    }

    public static function invalidAssessments(): array
    {
        return [['predecessor'], ['snapshot'], ['evidence'], ['wrong_line_requirement'], ['wrong_subject']];
    }

    public function test_observed_physical_dependency_token_cannot_be_replaced_silently(): void
    {
        [$lab, $actor, $order, $line, $requirement, $branch, $type, $url] = $this->replacementScenario();
        $samples = app(LaboratorySampleService::class);
        $sample = $samples->register($lab, $actor, $order->id, 'receiveNew', ['idempotency_key' => 'token', 'sample_type_id' => $type->id,
            'provenance' => 'patient_provided', 'received_at' => now()->toISOString(), 'receiving_branch_id' => $branch->id])['data']['id'];
        $service = app(ClinicalReplacementService::class);
        $i = $service->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement, 0, $sample));
        $e = $service->recordEvidence($lab, $actor, $order->id, $line->id, $this->evidenceBody(2));
        $body = $this->assessmentBody(3, $i['intention_id'], $e['evidence_id']);
        $samples->evaluate($lab, $actor, $order->id, $sample, 'accepted');
        $this->postJson($url.'/assessments', $body)->assertConflict()->assertJsonPath('code', 'viability_dependencies_conflict');
        $this->assertDatabaseCount('clinical_viability_assessments', 0);
    }

    public function test_chain_cycles_and_mutations_of_material_links_are_blocked_and_rolled_back(): void
    {
        [$lab, $actor, $order, $line, $requirement, $branch, $type, $url] = $this->replacementScenario();
        $samples = app(LaboratorySampleService::class);
        $input = ['idempotency_key' => 'a', 'sample_type_id' => $type->id, 'provenance' => 'patient_provided', 'received_at' => now()->toISOString(), 'receiving_branch_id' => $branch->id];
        $a = $samples->register($lab, $actor, $order->id, 'receiveNew', $input)['data']['id'];
        $b = $samples->register($lab, $actor, $order->id, 'receiveNew', array_replace($input, ['idempotency_key' => 'b']))['data']['id'];
        $service = app(ClinicalReplacementService::class);
        $i = $service->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement, 0, $a));
        $service->linkSample($lab, $actor, $order->id, $line->id, $i['intention_id'], ['expected_revision' => 2, 'occurred_at' => now()->toISOString(), 'sample_id' => $b, 'justification' => 'FICTICIO: A-B']);
        $j = $service->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement, 4, $b));
        $this->postJson($url.'/intentions/'.$j['intention_id'].'/samples', ['expected_revision' => 6, 'occurred_at' => now()->toISOString(), 'sample_id' => $a, 'justification' => 'FICTICIO: B-A'])->assertConflict()->assertJsonPath('code', 'replacement_integrity_conflict');
        $this->assertDatabaseCount('clinical_replacement_links', 1);
        $this->assertDatabaseCount('laboratory_sample_associations', 1);
        $this->assertSqlRejected(fn () => DB::table('clinical_replacement_links')->update(['sample_id' => $a]));
        $this->assertSqlRejected(fn () => DB::table('clinical_replacement_links')->delete());
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_rejected_material_with_an_existing_association_cannot_be_linked_as_replacement(): void
    {
        [$lab, $actor, $order, $line, $requirement, $branch, $type, $url] = $this->replacementScenario();
        $samples = app(LaboratorySampleService::class);
        $sample = $samples->register($lab, $actor, $order->id, 'receiveNew', ['idempotency_key' => 'rejected', 'sample_type_id' => $type->id,
            'provenance' => 'patient_provided', 'received_at' => now()->toISOString(), 'receiving_branch_id' => $branch->id])['data']['id'];
        $samples->associate($lab, $actor, $order->id, $sample, ['sample_requirement_ids' => [$requirement->id]]);
        $samples->evaluate($lab, $actor, $order->id, $sample, 'rejected', ['reason' => 'FICTICIO: material no apto']);
        $i = app(ClinicalReplacementService::class)->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement));
        $this->postJson($url.'/intentions/'.$i['intention_id'].'/samples', ['expected_revision' => 2, 'occurred_at' => now()->toISOString(), 'sample_id' => $sample, 'justification' => 'FICTICIO: enlace rechazado'])
            ->assertConflict()->assertJsonPath('code', 'sample_requirement_incompatible');
        $this->assertDatabaseCount('clinical_replacement_links', 0);
        $this->assertSame(2, DB::table('clinical_replacement_contexts')->value('revision'));
    }

    public function test_line_with_replacement_evidence_cannot_be_removed_without_fabricating_processing(): void
    {
        [, , $order, $line, $requirement, , , $url] = $this->replacementScenario();
        $this->postJson($url.'/intentions', $this->intentionBody($requirement))->assertCreated();
        $this->deleteJson("/api/v1/laboratory-orders/$order->id/exams/$line->id")
            ->assertConflict()->assertJsonPath('code', 'order_exam_has_clinical_activity');
        $this->assertNotNull($line->fresh());
        $this->assertNull($line->fresh()->processing_started_at);
        $this->assertNull($line->fresh()->clinical_activity_started_at);
        $this->assertDatabaseCount('clinical_replacement_intents', 1);
    }

    public function test_material_link_audit_failure_rolls_back_be05_association_and_events(): void
    {
        [$lab, $actor, $order, $line, $requirement, $branch, $type] = $this->replacementScenario();
        $samples = app(LaboratorySampleService::class);
        $sample = $samples->register($lab, $actor, $order->id, 'receiveNew', ['idempotency_key' => 'rollback', 'sample_type_id' => $type->id,
            'provenance' => 'patient_provided', 'received_at' => now()->toISOString(), 'receiving_branch_id' => $branch->id])['data']['id'];
        $service = app(ClinicalReplacementService::class);
        $i = $service->createIntention($lab, $actor, $order->id, $line->id, $this->intentionBody($requirement));
        AuditLog::creating(function ($log): void {
            if ($log->event === 'clinical_replacement.recorded') {
                throw new RuntimeException('Synthetic link audit failure.');
            }
        });
        try {
            $service->linkSample($lab, $actor, $order->id, $line->id, $i['intention_id'], ['expected_revision' => 2, 'occurred_at' => now()->toISOString(), 'sample_id' => $sample, 'justification' => 'FICTICIO: enlace']);
            $this->fail('Audit failure must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic link audit failure.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('clinical_replacement_links', 0);
        $this->assertDatabaseCount('laboratory_sample_associations', 0);
        $this->assertDatabaseCount('laboratory_sample_events', 1);
        $this->assertNull($line->fresh()->clinical_activity_started_at);
        $this->assertSame(2, DB::table('clinical_replacement_contexts')->value('revision'));
    }
}
