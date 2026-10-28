<?php

namespace App\ClinicalExams;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Authorization\ClinicalReasonTenantAuthority as Authority;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonAdoptionVersion;
use App\Models\ClinicalReasonImpactResolution;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReason;
use App\Models\GlobalClinicalReasonImpact;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClinicalReasonAdoptionManager
{
    public function __construct(private Authority $authority, private AuditWriter $audit, private ClinicalReasonReviewStore $reviews) {}

    public function catalog(Laboratory $lab, ?User $actor, ?int $reasonId = null, bool $versions = false): array
    {
        return $this->authority->run($lab, $actor, Authority::GLOBAL_VIEW, function () use ($reasonId, $versions): array {
            if ($reasonId === null) {
                return $this->page(GlobalClinicalReason::with('latestVersion')->orderBy('id')->paginate(50));
            }
            $reason = GlobalClinicalReason::with('latestVersion')->findOrFail($reasonId);
            if ($versions) {
                return $this->page(GlobalClinicalReasonVersion::where('reason_id', $reasonId)->orderByDesc('number')->paginate(50));
            }

            return ['data' => $reason];
        });
    }

    public function index(Laboratory $lab, ?User $actor): array
    {
        return $this->authority->run($lab, $actor, Authority::VIEW, fn () => $this->page(ClinicalReasonAdoption::forLaboratory($lab)->with('latestVersion.version')->orderBy('id')->paginate(50)));
    }

    public function history(Laboratory $lab, ?User $actor, int $adoptionId): array
    {
        return $this->authority->run($lab, $actor, Authority::HISTORY, function () use ($lab, $adoptionId): array {
            $this->adoption($lab, $adoptionId);

            return $this->page(ClinicalReasonAdoptionVersion::forLaboratory($lab)->where('adoption_id', $adoptionId)->with('version')->orderByDesc('revision')->paginate(50));
        });
    }

    public function updates(Laboratory $lab, ?User $actor, int $adoptionId): array
    {
        return $this->authority->run($lab, $actor, Authority::VIEW, function () use ($lab, $adoptionId): array {
            $adoption = $this->adoption($lab, $adoptionId)->load('latestVersion.version');
            $adopted = $adoption->latestVersion->version;
            $page = GlobalClinicalReasonVersion::where('reason_id', $adoption->reason_id)->where('number', '>', $adopted->number)->orderBy('number')->paginate(50);
            $impacts = GlobalClinicalReasonImpact::whereIn('version_id', collect($page->items())->pluck('id'))->orderBy('revision')->get()->keyBy('version_id');
            $items = [];
            foreach ($page->items() as $version) {
                $impact = $impacts->get($version->id);
                $differences = [];
                foreach (['name', 'description', 'classification', 'evidence_requirements', 'is_active'] as $field) {
                    if ($adopted->$field !== $version->$field) {
                        $differences[$field] = ['adopted' => $adopted->$field, 'available' => $version->$field];
                    }
                }
                $items[] = ['version' => $version, 'declared_impact' => $impact?->classification ?? 'unclassified', 'declaration_id' => $impact?->id, 'differences' => $differences];
            }
            $result = $this->page($page);
            $result['data'] = $items;
            $result['adopted_version_id'] = $adopted->id;

            return $result;
        });
    }

    public function adopt(Laboratory $lab, ?User $actor, array $input): array
    {
        return $this->authority->run($lab, $actor, Authority::ADOPT, function () use ($lab, $actor, $input): array {
            $this->input($input, ['reason_id' => ['required', 'integer', 'min:1'], 'version_id' => ['required', 'integer', 'min:1'], 'justification' => ['required', 'string', 'max:2000', 'regex:/\S/']]);
            $version = GlobalClinicalReasonVersion::where('reason_id', $input['reason_id'])->whereKey($input['version_id'])->firstOrFail();
            if (ClinicalReasonAdoption::forLaboratory($lab)->where('reason_id', $version->reason_id)->exists()) {
                throw new ClinicalConflict('clinical_reason_already_adopted', 'This laboratory already selected the global reason.');
            }
            $adoption = ClinicalReasonAdoption::create(['laboratory_id' => $lab->id, 'reason_id' => $version->reason_id, 'created_by' => $actor->id])->refresh();

            return $this->append($lab, $actor, $adoption, $version, null, $input['justification']);
        }, owner: true);
    }

    public function update(Laboratory $lab, ?User $actor, int $adoptionId, array $input): array
    {
        return $this->authority->run($lab, $actor, Authority::UPDATE, function () use ($lab, $actor, $adoptionId, $input): array {
            $this->input($input, ['version_id' => ['required', 'integer', 'min:1'], 'expected_revision' => ['required', 'integer', 'min:1'], 'justification' => ['required', 'string', 'max:2000', 'regex:/\S/']]);
            $adoption = $this->adoption($lab, $adoptionId);
            $previous = $adoption->latestVersion;
            if ($previous->revision !== $input['expected_revision']) {
                throw new ClinicalConflict('clinical_reason_adoption_revision_conflict', 'Reload the current adoption.');
            }
            $version = GlobalClinicalReasonVersion::where('reason_id', $adoption->reason_id)->whereKey($input['version_id'])->firstOrFail();
            if ($version->number <= $previous->version->number) {
                throw new ClinicalConflict('clinical_reason_adoption_version_conflict', 'Choose a later global version explicitly.');
            }

            return $this->append($lab, $actor, $adoption, $version, $previous, $input['justification']);
        }, owner: true);
    }

    public function reviews(Laboratory $lab, ?User $actor, int $adoptionId): array
    {
        return $this->authority->run($lab, $actor, Authority::VIEW, function () use ($lab, $adoptionId): array {
            $this->adoption($lab, $adoptionId);

            return $this->page(ClinicalReasonImpactReview::forLaboratory($lab)->where('adoption_id', $adoptionId)->with(['latestResolution', 'impact'])->orderBy('id')->paginate(50));
        });
    }

    public function resolve(Laboratory $lab, ?User $actor, int $adoptionId, int $reviewId, array $input): array
    {
        return $this->authority->run($lab, $actor, Authority::REVIEW, function () use ($lab, $actor, $adoptionId, $reviewId, $input): array {
            $this->input($input, ['expected_revision' => ['required', 'integer', 'min:0'], 'outcome' => ['required', Rule::in(['favorable', 'non_favorable'])], 'assessment' => ['required', 'string', 'max:10000', 'regex:/\S/'], 'justification' => ['required', 'string', 'max:2000', 'regex:/\S/']]);
            $this->adoption($lab, $adoptionId);
            $review = ClinicalReasonImpactReview::forLaboratory($lab)->where('adoption_id', $adoptionId)->whereKey($reviewId)->lockForUpdate()->firstOrFail();
            $previous = $review->latestResolution;
            if (($previous?->revision ?? 0) !== $input['expected_revision'] || $previous?->outcome === 'favorable') {
                throw new ClinicalConflict('clinical_reason_review_revision_conflict', 'Reload the unresolved review.');
            }
            $resolution = ClinicalReasonImpactResolution::create([
                'laboratory_id' => $lab->id, 'review_id' => $review->id, 'revision' => $input['expected_revision'] + 1, 'previous_id' => $previous?->id,
                'outcome' => $input['outcome'], 'assessment' => $input['assessment'], 'justification' => $input['justification'], 'created_by' => $actor->id,
            ])->refresh();
            $this->audit->record($lab, $actor, new AuditEvent('clinical_reason.review_evaluated', 'clinical_reason_impact_review', $review->id,
                oldValues: ['resolution_id' => $previous?->id], newValues: ['resolution_id' => $resolution->id, 'outcome' => $resolution->outcome, 'impact_id' => $review->impact_id],
                metadata: ['justification' => $input['justification'], 'result' => 'succeeded']));

            return ['data' => $resolution, 'review_status' => $resolution->outcome === 'favorable' ? 'resolved' : 'pending'];
        });
    }

    public function resolutions(Laboratory $lab, ?User $actor, int $adoptionId, int $reviewId): array
    {
        return $this->authority->run($lab, $actor, Authority::HISTORY, function () use ($lab, $adoptionId, $reviewId): array {
            $this->adoption($lab, $adoptionId);
            ClinicalReasonImpactReview::forLaboratory($lab)->where('adoption_id', $adoptionId)->findOrFail($reviewId);

            return $this->page(ClinicalReasonImpactResolution::forLaboratory($lab)->where('review_id', $reviewId)->orderByDesc('revision')->paginate(50));
        });
    }

    private function append(Laboratory $lab, User $actor, ClinicalReasonAdoption $adoption, GlobalClinicalReasonVersion $version, ?ClinicalReasonAdoptionVersion $previous, string $justification): array
    {
        $selection = ClinicalReasonAdoptionVersion::create(['laboratory_id' => $lab->id, 'reason_id' => $version->reason_id, 'adoption_id' => $adoption->id,
            'version_id' => $version->id, 'revision' => ($previous?->revision ?? 0) + 1, 'previous_id' => $previous?->id, 'justification' => $justification, 'created_by' => $actor->id])->refresh();
        $this->audit->record($lab, $actor, new AuditEvent($previous ? 'clinical_reason.adoption_updated' : 'clinical_reason.adopted', 'clinical_reason_adoption', $adoption->id,
            oldValues: ['version_id' => $previous?->version_id], newValues: ['reason_id' => $version->reason_id, 'version_id' => $version->id, 'revision' => $selection->revision],
            metadata: ['justification' => $justification, 'result' => 'succeeded']));
        $adoption->unsetRelation('latestVersion');
        $this->reviews->reconcile($adoption, $actor);

        return ['data' => ['adoption' => $adoption->withoutRelations(), 'selection' => $selection]];
    }

    private function adoption(Laboratory $lab, int $id): ClinicalReasonAdoption
    {
        return ClinicalReasonAdoption::forLaboratory($lab)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function input(array $input, array $rules): void
    {
        Validator::make($input, $rules)->validate();
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['input' => 'Only documented fields are accepted.']);
        }
        foreach (['expected_revision', 'reason_id', 'version_id'] as $field) {
            if (isset($input[$field]) && ! is_int($input[$field])) {
                throw ValidationException::withMessages([$field => 'Use a JSON integer.']);
            }
        }
    }

    private function page(LengthAwarePaginator $page): array
    {
        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
    }
}
