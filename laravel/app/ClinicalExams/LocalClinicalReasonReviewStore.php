<?php

namespace App\ClinicalExams;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\ClinicalExceptionRequest;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\LocalClinicalReasonReview;
use App\Models\User;

final class LocalClinicalReasonReviewStore
{
    public function __construct(private AuditWriter $audit) {}

    public function published(Laboratory $lab, User $actor, LaboratoryClinicalReasonVersion $version, ?LaboratoryClinicalReasonVersion $previous): void
    {
        $cause = $previous === null ? null : ($previous->classification !== $version->classification ? 'reclassification' : ($previous->is_active && ! $version->is_active ? 'deactivation' : null));
        if ($cause === null) {
            return;
        }
        $references = ClinicalExceptionLocalReasonReference::forLaboratory($lab)->where('reason_id', $version->reason_id)
            ->whereIn('exception_request_id', ClinicalExceptionRequest::forLaboratory($lab)->where('status', 'pending')->select('id'))->orderBy('id')->get();
        foreach ($references as $reference) {
            $review = LocalClinicalReasonReview::create(['laboratory_id' => $lab->id, 'exception_request_id' => $reference->exception_request_id,
                'reason_id' => $version->reason_id, 'original_version_id' => $reference->version_id, 'trigger_version_id' => $version->id,
                'cause_type' => $cause, 'cause' => $version->change_reason, 'created_by' => $actor->id])->refresh();
            $this->audit->record($lab, $actor, new AuditEvent('clinical_reason.local_review_required', 'local_clinical_reason_review', $review->id,
                newValues: ['exception_request_id' => $reference->exception_request_id, 'reason_id' => $version->reason_id, 'original_version_id' => $reference->version_id, 'trigger_version_id' => $version->id, 'status' => 'pending'],
                metadata: ['cause_type' => $cause, 'cause' => $version->change_reason, 'result' => 'succeeded']));
        }
    }
}
