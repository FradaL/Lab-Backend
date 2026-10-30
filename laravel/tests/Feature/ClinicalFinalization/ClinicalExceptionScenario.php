<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonAdoptionVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ClinicalReplacements\ReplacementScenario;
use Tests\Support\StructuredClinicalReasonFixture;

trait ClinicalExceptionScenario
{
    use ReplacementScenario;

    protected function prepared(string $code = 'm03'): array
    {
        [$lab, $actor, $order, $line, $requirement, $branch, $type, $url] = $this->replacementScenario();
        [$reason, $version] = StructuredClinicalReasonFixture::global('FICTICIO: motivo clínico', $code);
        $adoption = ClinicalReasonAdoption::create(['laboratory_id' => $lab->id, 'reason_id' => $reason->id, 'created_by' => $actor->id]);
        $selection = ClinicalReasonAdoptionVersion::create(['laboratory_id' => $lab->id, 'reason_id' => $reason->id, 'adoption_id' => $adoption->id, 'version_id' => $version->id, 'revision' => 1, 'created_by' => $actor->id, 'justification' => 'FICTICIO']);
        $reference = ['source' => 'global', 'reason_id' => $reason->id, 'version_id' => $version->id, 'adoption_id' => $adoption->id, 'adoption_version_id' => $selection->id];
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve', 'orders.clinical_replacements.evaluate']);

        return compact('lab', 'actor', 'order', 'line', 'requirement', 'branch', 'type', 'url', 'reference', 'approver', 'version');
    }

    protected function revision(array $s): int
    {
        return (int) DB::table('clinical_replacement_contexts')->where('laboratory_order_exam_id', $s['line']->id)->value('revision');
    }

    protected function evidence(array $s, string $kind, array $extra = []): int
    {
        setPermissionsTeamId($s['lab']->id);

        return app(ClinicalReplacementService::class)->recordEvidence($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, $extra + $this->evidenceBody($this->revision($s), $kind))['evidence_id'];
    }

    protected function intention(array $s, ?int $sample = null): int
    {
        setPermissionsTeamId($s['lab']->id);

        return app(ClinicalReplacementService::class)->createIntention($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, $this->intentionBody($s['requirement'], $this->revision($s), $sample))['intention_id'];
    }

    protected function assess(array $s, array $evidenceIds, string $subject = 'replacement', ?int $intention = null, string $outcome = 'non_viable', ?int $previous = null): int
    {
        $body = ['expected_revision' => $this->revision($s), 'expected_dependency_token' => hash('sha256', DB::scalar('SELECT clinical_replacement_dependencies(?)::text', [$s['line']->id])),
            'occurred_at' => now()->subMinute()->toISOString(), 'subject' => $subject, 'previous_id' => $previous, 'outcome' => $outcome, 'justification' => 'FICTICIO: evaluación', 'evidence_ids' => $evidenceIds];
        if ($intention !== null) {
            $body['intention_id'] = $intention;
        }
        setPermissionsTeamId($s['lab']->id);

        return app(ClinicalReplacementService::class)->assess($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, $body)['assessment_id'];
    }

    protected function requestBody(array $s): array
    {
        return ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $s['reference'], 'justification' => 'FICTICIO: revisión independiente'];
    }

    protected function request(array $s): int
    {
        setPermissionsTeamId($s['lab']->id);

        return app(ClinicalResolutionService::class)->requestException($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, $this->requestBody($s))['exception']['id'];
    }

    protected function endpoint(array $s): string
    {
        return "/api/v1/laboratory-orders/{$s['order']->id}/exams/{$s['line']->id}/exceptions";
    }

    protected function approve(array $s, int $id): TestResponse
    {
        return $this->actingAs($s['approver'])->postJson($this->endpoint($s)."/$id/approve", ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1]);
    }
}
