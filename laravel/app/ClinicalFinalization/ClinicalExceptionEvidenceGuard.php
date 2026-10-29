<?php

namespace App\ClinicalFinalization;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\SampleCompatibility;
use App\Models\ClinicalExceptionRequest;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReasonVersion;
use Illuminate\Support\Facades\DB;

/** Clinical evidence is independent of catalogue eligibility and processing state. */
final class ClinicalExceptionEvidenceGuard
{
    private const PROFILES = [
        'm01' => ['sample_problem', 'replacement_assessment'],
        'm02' => ['sample_problem', 'replacement_assessment', 'affected_sample'],
        'm03' => ['patient_refusal'],
        'm04' => ['follow_up', 'continuation_assessment'],
        'm05' => ['medical_instruction'],
        'm06' => ['technical_problem', 'alternatives', 'technical_alternatives_assessment'],
        'm07' => ['technical_problem', 'alternatives', 'technical_alternatives_assessment'],
    ];

    public function __construct(private SampleCompatibility $compatibility) {}

    public function dependencies(int $lineId): string
    {
        return DB::scalar('SELECT clinical_exception_dependencies(?)::text', [$lineId]);
    }

    public function status(Laboratory $lab, int $orderId, int $lineId, GlobalClinicalReasonVersion|LaboratoryClinicalReasonVersion|null $version, ?ClinicalExceptionRequest $request = null, bool $terminal = false): array
    {
        $blockers = [];
        $missing = [];
        $code = null;
        $requirements = $version?->evidence_requirements['requirements'] ?? [];
        if ($version !== null) {
            $table = $version instanceof GlobalClinicalReasonVersion ? 'global_clinical_reasons' : 'laboratory_clinical_reasons';
            $code = DB::table($table)->where('id', $version->reason_id)->value('code');
            $requirements = self::PROFILES[$code] ?? $requirements;
        }
        foreach (['sample_problem' => ['replacement_assessment'], 'affected_sample' => ['sample_problem', 'replacement_assessment'], 'follow_up' => ['continuation_assessment'], 'technical_problem' => ['alternatives', 'technical_alternatives_assessment'], 'alternatives' => ['technical_problem', 'technical_alternatives_assessment']] as $kind => $required) {
            if (in_array($kind, $requirements, true)) {
                $requirements = array_values(array_unique(array_merge($requirements, $required)));
            }
        }
        $known = ['sample_problem', 'replacement_assessment', 'affected_sample', 'patient_refusal', 'follow_up', 'continuation_assessment', 'medical_instruction', 'technical_problem', 'alternatives', 'technical_alternatives_assessment'];
        if ($version === null || $requirements === [] || array_diff($requirements, $known) !== []) {
            $blockers[] = 'clinical_evidence_policy_unconfigured';
        }
        $evidence = DB::table('clinical_line_evidence')->where('laboratory_id', $lab->id)->where('laboratory_order_exam_id', $lineId)->get();
        $assessments = DB::table('clinical_viability_assessments as a')->where('a.laboratory_id', $lab->id)->where('a.laboratory_order_exam_id', $lineId)
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('clinical_viability_assessments as n')->whereColumn('n.laboratory_order_exam_id', 'a.laboratory_order_exam_id')->whereColumn('n.subject', 'a.subject')->whereRaw('n.intention_id IS NOT DISTINCT FROM a.intention_id')->whereColumn('n.revision', '>', 'a.revision');
            })->select('a.*')->selectRaw('a.dependency_snapshot IS DISTINCT FROM clinical_replacement_dependencies(a.laboratory_order_exam_id) AS obsolete')->orderBy('a.id')->get();
        foreach (array_intersect($requirements, ['sample_problem', 'patient_refusal', 'follow_up', 'medical_instruction', 'technical_problem', 'alternatives']) as $kind) {
            if (! $evidence->contains('kind', $kind)) {
                $missing[] = $kind;
            }
        }
        if (in_array('affected_sample', $requirements, true) && ! $evidence->contains(fn ($e) => $e->kind === 'sample_problem' && $e->sample_id !== null && $e->sample_requirement_id !== null)) {
            $missing[] = 'affected_sample';
        }
        foreach (['continuation_assessment' => 'continuation', 'technical_alternatives_assessment' => 'technical_alternatives'] as $requirement => $subject) {
            if (in_array($requirement, $requirements, true)) {
                $assessment = $assessments->firstWhere('subject', $subject);
                if ($assessment === null) {
                    $missing[] = $requirement;
                } elseif ($assessment->obsolete) {
                    $blockers[] = 'clinical_viability_obsolete';
                } elseif ($assessment->outcome !== 'non_viable') {
                    $blockers[] = 'clinical_'.$subject.'_viable';
                }
            }
        }
        $intents = DB::table('clinical_replacement_intents')->where('laboratory_id', $lab->id)->where('laboratory_order_exam_id', $lineId)->get();
        $replacementRequired = in_array('replacement_assessment', $requirements, true) && $code !== 'm03';
        if ($replacementRequired && $intents->isEmpty()) {
            $missing[] = 'replacement_assessment';
        }
        $adequate = $this->compatibility->qualifyingAssociations($lab, $orderId)->pluck('id');
        $pending = [];
        foreach ($intents as $intent) {
            $event = DB::table('clinical_replacement_events as e')->leftJoin('clinical_replacement_links as l', 'l.id', '=', 'e.link_id')->where('e.intention_id', $intent->id)->orderByDesc('e.id')->select('e.event', 'l.association_id')->first();
            $fulfilled = $event?->event === 'fulfilled' && $adequate->contains($event->association_id);
            $assessment = $assessments->first(fn ($a) => $a->subject === 'replacement' && $a->intention_id === $intent->id);
            if (! $replacementRequired && (! $terminal || $fulfilled)) {
                continue;
            }
            if (! $fulfilled) {
                $pending[] = $intent->id;
            }
            if ($assessment === null) {
                $missing[] = 'replacement_assessment:'.$intent->id;
            } elseif ($assessment->obsolete) {
                $blockers[] = 'clinical_viability_obsolete';
            } elseif ($assessment->outcome !== 'non_viable') {
                $blockers[] = 'clinical_replacement_pending_viable';
            }
        }
        if (in_array('affected_sample', $requirements, true) && ! $intents->contains(function ($intent) use ($assessments, $evidence): bool {
            $assessment = $assessments->first(fn ($a) => $a->subject === 'replacement' && $a->intention_id === $intent->id);
            $ids = $assessment === null ? [] : json_decode($assessment->evidence_ids, true, flags: JSON_THROW_ON_ERROR);

            return $intent->sample_id !== null && $evidence->contains(fn ($e) => $e->kind === 'sample_problem' && in_array($e->id, $ids, true) && $e->sample_id === $intent->sample_id && $e->sample_requirement_id === $intent->sample_requirement_id);
        })) {
            $missing[] = 'affected_sample_assessment';
        }
        if ($missing !== []) {
            $blockers[] = 'clinical_evidence_missing';
        }
        $dependencies = $this->dependencies($lineId);
        $review = $request === null ? null : DB::table('clinical_exception_evidence_reviews')->where('exception_request_id', $request->id)->orderByDesc('id')->first();
        $reviewRequired = $request !== null && ($review === null || $review->dependency_snapshot !== $dependencies);
        if ($reviewRequired) {
            $blockers[] = 'clinical_exception_review_required';
        }

        return ['eligible' => $blockers === [], 'blocking_reasons' => array_values(array_unique($blockers)), 'missing_evidence' => array_values(array_unique($missing)),
            'requires_review' => $reviewRequired, 'obsolete_assessment_ids' => $assessments->where('obsolete', true)->pluck('id')->all(),
            'pending_intention_ids' => $pending, 'evidence_ids' => $evidence->pluck('id')->all(), 'assessment_ids' => $assessments->pluck('id')->all(),
            'dependency_token' => hash('sha256', $dependencies), 'review_id' => $review?->id];
    }

    public function assertEligible(array $status): void
    {
        if (! $status['eligible']) {
            throw new ClinicalConflict($status['blocking_reasons'][0], 'Review the current clinical evidence and viability before continuing.', ['clinical_eligibility' => $status]);
        }
    }

    public function remember(Laboratory $lab, ClinicalExceptionRequest $request, int $actorId, string $justification, string $kind): int
    {
        return DB::table('clinical_exception_evidence_reviews')->insertGetId(['laboratory_id' => $lab->id, 'laboratory_order_id' => $request->laboratory_order_id,
            'laboratory_order_exam_id' => $request->laboratory_order_exam_id, 'exception_request_id' => $request->id, 'reviewed_by' => $actorId,
            'kind' => $kind, 'justification' => $justification, 'dependency_snapshot' => $this->dependencies($request->laboratory_order_exam_id), 'created_at' => now()]);
    }
}
