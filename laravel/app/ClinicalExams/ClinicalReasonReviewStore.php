<?php

namespace App\ClinicalExams;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonImpact;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\User;

final class ClinicalReasonReviewStore
{
    public function __construct(private AuditWriter $audit) {}

    /** All callers hold the shared SaaS transaction lock before creating obligations. */
    public function forImpact(GlobalClinicalReasonImpact $impact, User $actor): void
    {
        if ($impact->classification !== 'restrictive') {
            return;
        }
        ClinicalReasonAdoption::where('reason_id', $impact->reason_id)->with('latestVersion')->orderBy('id')->each(fn ($adoption) => $this->open($adoption, $actor, $impact->version_id, $impact));
    }

    public function forDeactivation(GlobalClinicalReasonVersion $version, User $actor): void
    {
        if (! $version->is_active) {
            ClinicalReasonAdoption::where('reason_id', $version->reason_id)->with('latestVersion')->orderBy('id')->each(fn ($adoption) => $this->open($adoption, $actor, $version->id));
        }
    }

    public function reconcile(ClinicalReasonAdoption $adoption, User $actor): void
    {
        $impacts = GlobalClinicalReasonImpact::where('reason_id', $adoption->reason_id)->whereNotIn('id', GlobalClinicalReasonImpact::select('previous_id')->whereNotNull('previous_id'))->where('classification', 'restrictive')->orderBy('id')->get();
        foreach ($impacts as $impact) {
            $this->open($adoption, $actor, $impact->version_id, $impact);
        }
        foreach (GlobalClinicalReasonVersion::where('reason_id', $adoption->reason_id)->where('is_active', false)->get() as $version) {
            if (GlobalClinicalReasonVersion::where('reason_id', $version->reason_id)->where('number', $version->number - 1)->where('is_active', true)->exists()) {
                $this->open($adoption, $actor, $version->id);
            }
        }
    }

    private function open(ClinicalReasonAdoption $adoption, User $actor, int $triggerVersion, ?GlobalClinicalReasonImpact $impact = null): void
    {
        $query = ClinicalReasonImpactReview::where('adoption_id', $adoption->id);
        $impact ? $query->where('impact_id', $impact->id) : $query->where('cause_type', 'deactivation')->where('trigger_version_id', $triggerVersion);
        if ($query->exists()) {
            return;
        }
        $version = GlobalClinicalReasonVersion::findOrFail($triggerVersion);
        $review = ClinicalReasonImpactReview::create([
            'laboratory_id' => $adoption->laboratory_id, 'reason_id' => $adoption->reason_id, 'adoption_id' => $adoption->id,
            'adopted_version_id' => $adoption->latestVersion->version_id, 'trigger_version_id' => $triggerVersion,
            'impact_id' => $impact?->id, 'cause_type' => $impact ? 'impact' : 'deactivation',
            'cause' => $impact?->justification ?? $version->change_reason, 'created_by' => $actor->id,
        ])->refresh();
        $this->audit->record(Laboratory::findOrFail($adoption->laboratory_id), $actor, new AuditEvent('clinical_reason.review_required', 'clinical_reason_impact_review', $review->id,
            newValues: ['review_id' => $review->id, 'reason_id' => $review->reason_id, 'adopted_version_id' => $review->adopted_version_id, 'trigger_version_id' => $triggerVersion, 'impact_id' => $impact?->id, 'status' => 'pending'],
            metadata: ['cause' => $review->cause, 'creation_source' => $impact ? 'impact_declaration' : 'global_deactivation', 'result' => 'succeeded']));
    }
}
