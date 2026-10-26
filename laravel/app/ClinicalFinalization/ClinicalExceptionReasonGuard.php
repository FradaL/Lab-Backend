<?php

namespace App\ClinicalFinalization;

use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonEligibilityPolicy;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\ClinicalExceptionReasonReference;
use App\Models\ClinicalExceptionRequest;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonImpact;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClinicalExceptionReasonGuard
{
    public function __construct(private SaasAccessAuthority $locks, private ClinicalReasonEligibilityPolicy $policy) {}

    public function lock(): void
    {
        $this->locks->lock();
    }

    public function select(Laboratory $lab, User $actor, array $reference): array
    {
        if ($reference['source'] === 'local') {
            foreach (['reason_id', 'version_id'] as $field) {
                if (! is_int($reference[$field])) {
                    throw ValidationException::withMessages(['reason_reference.'.$field => 'Use a positive JSON integer.']);
                }
            }
            $version = LaboratoryClinicalReasonVersion::forLaboratory($lab)->where('reason_id', $reference['reason_id'])->findOrFail($reference['version_id']);
            $eligibility = $this->policy->assertLocalAllowed($lab, $actor, $reference['reason_id'], $reference['version_id'], 'request');
            $this->terminalCandidate($version);

            return [$version, $eligibility];
        }
        foreach (['reason_id', 'version_id', 'adoption_id', 'adoption_version_id'] as $field) {
            if (! is_int($reference[$field])) {
                throw ValidationException::withMessages(['reason_reference.'.$field => 'Use a positive JSON integer.']);
            }
        }
        $adoption = ClinicalReasonAdoption::forLaboratory($lab)->where('reason_id', $reference['reason_id'])->whereKey($reference['adoption_id'])->with('latestVersion')->firstOrFail();
        $selection = $adoption->latestVersion;
        if ($selection->id !== $reference['adoption_version_id'] || $selection->version_id !== $reference['version_id']) {
            throw new ClinicalConflict('clinical_reason_selection_conflict', 'Reload the explicitly adopted version and selection.');
        }
        $version = GlobalClinicalReasonVersion::where('reason_id', $reference['reason_id'])->findOrFail($reference['version_id']);
        $this->policy->assertAllowed($lab, $actor, $adoption->id, 'request');
        $this->terminalCandidate($version);

        return [$version, $this->evidence($adoption->id, $adoption->reason_id)];
    }

    public function approve(Laboratory $lab, User $actor, ClinicalExceptionRequest $request): array
    {
        $reference = $this->reference($lab, $request->id);
        if ($reference instanceof ClinicalExceptionLocalReasonReference) {
            $eligibility = $this->policy->assertLocalAllowed($lab, $actor, $reference->reason_id, $reference->version_id, 'approve', $request->id);
            $this->terminalCandidate($reference->version);

            return ['reference' => $reference, 'eligibility' => $eligibility];
        }
        if ($reference === null) {
            if (! DB::table('clinical_exception_legacy_requests')->where('exception_request_id', $request->id)->exists()) {
                throw new ClinicalConflict('clinical_reason_reference_missing', 'A new clinical request must retain its original reason reference.');
            }

            return ['reference' => null, 'eligibility' => ['source' => 'pre_integration_request', 'exception_request_id' => $request->id]];
        }
        $this->policy->assertAllowed($lab, $actor, $reference->adoption_id, 'approve');
        $this->terminalCandidate($reference->version);

        return ['reference' => $reference, 'eligibility' => $this->evidence($reference->adoption_id, $reference->reason_id)];
    }

    public function remember(Laboratory $lab, User $actor, ClinicalExceptionRequest $request, array $reference, string $justification): ClinicalExceptionReasonReference|ClinicalExceptionLocalReasonReference
    {
        $class = $reference['source'] === 'local' ? ClinicalExceptionLocalReasonReference::class : ClinicalExceptionReasonReference::class;

        return $class::create($reference + ['laboratory_id' => $lab->id, 'laboratory_order_id' => $request->laboratory_order_id,
            'laboratory_order_exam_id' => $request->laboratory_order_exam_id, 'exception_request_id' => $request->id, 'justification' => $justification, 'created_by' => $actor->id])->refresh();
    }

    public function reference(Laboratory $lab, int $requestId): ClinicalExceptionReasonReference|ClinicalExceptionLocalReasonReference|null
    {
        return ClinicalExceptionReasonReference::forLaboratory($lab)->where('exception_request_id', $requestId)->with('version')->first()
            ?? ClinicalExceptionLocalReasonReference::forLaboratory($lab)->where('exception_request_id', $requestId)->with('version')->first();
    }

    private function terminalCandidate(GlobalClinicalReasonVersion|LaboratoryClinicalReasonVersion $version): void
    {
        if ($version->classification !== 'terminal_candidate') {
            throw new ClinicalConflict('clinical_reason_not_terminal', 'An incidence-only reason cannot request a definitive exception resolution.');
        }
    }

    private function evidence(int $adoptionId, int $reasonId): array
    {
        $impacts = GlobalClinicalReasonImpact::where('reason_id', $reasonId)->orderBy('revision')->get()->keyBy('version_id');
        $reviews = ClinicalReasonImpactReview::where('adoption_id', $adoptionId)->with('latestResolution')->orderBy('id')->get();

        return ['adoption_id' => $adoptionId, 'impact_declaration_ids' => $impacts->pluck('id')->values()->all(),
            'review_resolution_ids' => $reviews->map(fn ($review) => $review->latestResolution?->id)->filter()->values()->all(), 'result' => 'allowed'];
    }
}
