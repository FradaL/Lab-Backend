<?php

namespace App\ClinicalExams;

use App\Authorization\ClinicalReasonTenantAuthority as Authority;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonImpact;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReason;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\LocalClinicalReasonReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClinicalReasonEligibilityPolicy
{
    public function __construct(private Authority $authority, private ClinicalReasonReviewStore $reviews) {}

    public function eligibility(Laboratory $lab, ?User $actor, int $adoptionId): array
    {
        return $this->authority->run($lab, $actor, Authority::VIEW, fn () => $this->evaluate($lab, $adoptionId));
    }

    /** Future structured clinical commands must call inside their outer transaction before writing. */
    public function assertAllowed(Laboratory $lab, ?User $actor, int $adoptionId, string $operation): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Clinical authorization must share the transaction of the protected write.');
        }
        if (! in_array($operation, ['request', 'approve'], true)) {
            throw ValidationException::withMessages(['operation' => 'Use request or approve.']);
        }
        $permission = $operation === 'request' ? 'orders.clinical_exceptions.request' : 'orders.clinical_exceptions.approve';
        $this->authority->run($lab, $actor, $permission, function () use ($lab, $adoptionId, $operation): void {
            $status = $this->evaluate($lab, $adoptionId);
            if (! $status['can_'.$operation]) {
                throw new ClinicalConflict('clinical_reason_use_blocked', 'The selected clinical reason has unresolved dependencies.');
            }
        });
    }

    public function assertLocalAllowed(Laboratory $lab, ?User $actor, int $reasonId, int $versionId, string $operation, ?int $requestId = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Clinical authorization must share the transaction of the protected write.');
        }
        if (! in_array($operation, ['request', 'approve'], true)) {
            throw ValidationException::withMessages(['operation' => 'Use request or approve.']);
        }
        $permission = $operation === 'request' ? 'orders.clinical_exceptions.request' : 'orders.clinical_exceptions.approve';

        return $this->authority->run($lab, $actor, $permission, function () use ($lab, $reasonId, $versionId, $operation, $requestId): array {
            $reason = LaboratoryClinicalReason::forLaboratory($lab)->with('latestVersion')->findOrFail($reasonId);
            $version = LaboratoryClinicalReasonVersion::forLaboratory($lab)->where('reason_id', $reasonId)->findOrFail($versionId);
            if ($operation === 'request') {
                if ($reason->latestVersion->id !== $versionId) {
                    throw new ClinicalConflict('clinical_reason_selection_conflict', 'Select the current local version.');
                }
                if (! $version->is_active) {
                    throw new ClinicalConflict('clinical_reason_use_blocked', 'The local clinical reason is inactive.');
                }

                return ['source' => 'local', 'reason_id' => $reasonId, 'version_id' => $versionId, 'review_resolution_ids' => [], 'result' => 'allowed'];
            }
            ClinicalExceptionLocalReasonReference::forLaboratory($lab)->where('reason_id', $reasonId)->where('version_id', $versionId)->where('exception_request_id', $requestId)->firstOrFail();
            $versions = LaboratoryClinicalReasonVersion::forLaboratory($lab)->where('reason_id', $reasonId)->orderBy('number')->get();
            $required = [];
            $previous = null;
            foreach ($versions as $candidate) {
                if ($candidate->number > $version->number && $previous !== null && ($candidate->classification !== $previous->classification || $previous->is_active && ! $candidate->is_active)) {
                    $required[] = $candidate->id;
                }
                $previous = $candidate;
            }
            $reviews = LocalClinicalReasonReview::forLaboratory($lab)->where('exception_request_id', $requestId)->where('reason_id', $reasonId)->with('latestResolution')->get();
            $resolved = $reviews->filter(fn ($review) => $review->latestResolution?->outcome === 'favorable');
            if (array_diff($required, $resolved->pluck('trigger_version_id')->all()) !== [] || $reviews->count() !== $resolved->count()) {
                throw new ClinicalConflict('clinical_reason_use_blocked', 'Local clinical review is pending or not favorable.');
            }

            return ['source' => 'local', 'reason_id' => $reasonId, 'version_id' => $versionId, 'review_ids' => $reviews->pluck('id')->all(),
                'review_resolution_ids' => $resolved->map(fn ($review) => $review->latestResolution->id)->values()->all(), 'result' => 'allowed'];
        });
    }

    private function evaluate(Laboratory $lab, int $adoptionId): array
    {
        $adoption = ClinicalReasonAdoption::forLaboratory($lab)->with('latestVersion.version')->findOrFail($adoptionId);
        $versions = GlobalClinicalReasonVersion::where('reason_id', $adoption->reason_id)->orderBy('number')->get();
        $impacts = GlobalClinicalReasonImpact::where('reason_id', $adoption->reason_id)->orderBy('revision')->get()->keyBy('version_id');
        $unclassified = $versions->filter(fn ($version) => ($impacts->get($version->id)?->classification ?? 'unclassified') === 'unclassified')->pluck('id')->all();
        $pending = ClinicalReasonImpactReview::forLaboratory($lab)->where('adoption_id', $adoptionId)->whereDoesntHave('latestResolution', fn ($q) => $q->where('outcome', 'favorable'))->orderBy('id')->pluck('id')->all();
        $common = [];
        if ($unclassified !== []) {
            $common[] = 'impact_unclassified';
        }
        if ($pending !== []) {
            $common[] = 'clinical_review_pending';
        }
        $request = $common;
        if (! $versions->last()->is_active || ! $adoption->latestVersion->version->is_active) {
            $request[] = 'reason_inactive';
        }

        return ['adoption_id' => $adoptionId, 'reason_id' => $adoption->reason_id, 'adopted_version_id' => $adoption->latestVersion->version_id,
            'unclassified_version_ids' => $unclassified, 'pending_review_ids' => $pending,
            'can_request' => $request === [], 'can_approve' => $common === [],
            'request_blockers' => $request, 'approval_blockers' => $common];
    }
}
