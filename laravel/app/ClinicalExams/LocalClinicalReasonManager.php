<?php

namespace App\ClinicalExams;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Authorization\ClinicalReasonTenantAuthority as Authority;
use App\Models\AuditLog;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReason;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\LocalClinicalReasonResolution;
use App\Models\LocalClinicalReasonReview;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LocalClinicalReasonManager
{
    public function __construct(private Authority $authority, private ClinicalReasonVersionStore $versions, private AuditWriter $audit, private LocalClinicalReasonReviewStore $reviews) {}

    public function index(Laboratory $lab, ?User $actor): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_VIEW, fn () => $this->page(LaboratoryClinicalReason::forLaboratory($lab)->with('latestVersion')->orderBy('id')->paginate(50)));
    }

    public function show(Laboratory $lab, ?User $actor, int $reasonId): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_VIEW, fn () => ['data' => $this->reason($lab, $reasonId)->load('latestVersion')]);
    }

    public function history(Laboratory $lab, ?User $actor, int $reasonId): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_HISTORY, function () use ($lab, $reasonId): array {
            $this->reason($lab, $reasonId);

            return $this->page(LaboratoryClinicalReasonVersion::forLaboratory($lab)->where('reason_id', $reasonId)->orderByDesc('number')->paginate(50));
        });
    }

    public function events(Laboratory $lab, ?User $actor, int $reasonId): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_HISTORY, function () use ($lab, $reasonId): array {
            $this->reason($lab, $reasonId);

            return $this->page(AuditLog::forLaboratory($lab)->where('auditable_type', 'laboratory_clinical_reason')->where('auditable_id', $reasonId)->orderByDesc('id')->paginate(50));
        });
    }

    public function create(Laboratory $lab, ?User $actor, array $input): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_CREATE, function () use ($lab, $actor, $input): array {
            $this->input($input, ['code' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]{0,49}$/'], 'definition' => ['required', 'array']]);
            if (LaboratoryClinicalReason::forLaboratory($lab)->where('code', $input['code'])->exists()) {
                throw new ClinicalConflict('local_clinical_reason_code_conflict', 'The clinical reason code already exists in this laboratory.');
            }
            $reason = LaboratoryClinicalReason::create(['laboratory_id' => $lab->id, 'code' => $input['code'], 'created_by' => $actor->id])->refresh();
            $version = $this->versions->appendLocal($lab, $reason->id, $actor, 0, $input['definition']);

            return $this->result($lab, $actor, $reason, $version, null, 'created');
        }, owner: true);
    }

    public function publish(Laboratory $lab, ?User $actor, int $reasonId, array $input): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_PUBLISH, function () use ($lab, $actor, $reasonId, $input): array {
            $this->input($input, ['expected_number' => ['required', 'integer', 'min:1'], 'definition' => ['required', 'array']]);
            $previous = $this->current($lab, $reasonId, $input['expected_number']);
            if (array_key_exists('is_active', $input['definition']) && $input['definition']['is_active'] !== $previous->is_active) {
                throw ValidationException::withMessages(['definition.is_active' => 'Use the explicit activation operation.']);
            }
            $version = $this->versions->appendLocal($lab, $reasonId, $actor, $previous->number, $input['definition']);
            $this->reviews->published($lab, $actor, $version, $previous);

            return $this->result($lab, $actor, $previous->reason, $version, $previous, 'published');
        }, owner: true);
    }

    public function activation(Laboratory $lab, ?User $actor, int $reasonId, array $input): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_MANAGE, function () use ($lab, $actor, $reasonId, $input): array {
            $this->input($input, ['expected_number' => ['required', 'integer', 'min:1'], 'is_active' => ['required', 'boolean:strict'], 'change_reason' => ['required', 'string', 'regex:/\S/']]);
            $previous = $this->current($lab, $reasonId, $input['expected_number']);
            if ($previous->is_active === $input['is_active']) {
                throw new ClinicalConflict('local_clinical_reason_activation_unchanged', 'The requested activation already matches.');
            }
            $version = $this->versions->appendLocal($lab, $reasonId, $actor, $previous->number, $previous->only(['name', 'description', 'classification', 'evidence_requirements']) + ['is_active' => $input['is_active'], 'change_reason' => $input['change_reason']]);
            $this->reviews->published($lab, $actor, $version, $previous);

            return $this->result($lab, $actor, $previous->reason, $version, $previous, 'activation_changed');
        }, owner: true);
    }

    public function reviews(Laboratory $lab, ?User $actor, int $reasonId, ?int $requestId = null): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_VIEW, function () use ($lab, $reasonId, $requestId): array {
            $this->reason($lab, $reasonId);
            if ($requestId !== null) {
                ClinicalExceptionLocalReasonReference::forLaboratory($lab)->where('reason_id', $reasonId)->where('exception_request_id', $requestId)->firstOrFail();
            }

            return $this->page(LocalClinicalReasonReview::forLaboratory($lab)->where('reason_id', $reasonId)->when($requestId !== null, fn ($query) => $query->where('exception_request_id', $requestId))->with('latestResolution')->orderBy('id')->paginate(50));
        });
    }

    public function resolutions(Laboratory $lab, ?User $actor, int $reasonId, int $reviewId): array
    {
        return $this->authority->run($lab, $actor, Authority::LOCAL_HISTORY, function () use ($lab, $reasonId, $reviewId): array {
            $this->review($lab, $reasonId, $reviewId);

            return $this->page(LocalClinicalReasonResolution::forLaboratory($lab)->where('review_id', $reviewId)->orderByDesc('revision')->paginate(50));
        });
    }

    public function resolve(Laboratory $lab, ?User $actor, int $reasonId, int $reviewId, array $input): array
    {
        return $this->authority->run($lab, $actor, Authority::REVIEW, function () use ($lab, $actor, $reasonId, $reviewId, $input): array {
            $this->input($input, ['expected_revision' => ['required', 'integer', 'min:0'], 'outcome' => ['required', Rule::in(['favorable', 'non_favorable'])], 'assessment' => ['required', 'string', 'max:10000', 'regex:/\S/'], 'justification' => ['required', 'string', 'max:2000', 'regex:/\S/']]);
            if (! is_int($input['expected_revision'])) {
                throw ValidationException::withMessages(['expected_revision' => 'Use a JSON integer.']);
            }
            $review = $this->review($lab, $reasonId, $reviewId);
            $previous = $review->latestResolution;
            if (($previous?->revision ?? 0) !== $input['expected_revision'] || $previous?->outcome === 'favorable') {
                throw new ClinicalConflict('clinical_reason_review_revision_conflict', 'Reload the unresolved review.');
            }
            $resolution = LocalClinicalReasonResolution::create(['laboratory_id' => $lab->id, 'review_id' => $review->id, 'revision' => $input['expected_revision'] + 1, 'previous_id' => $previous?->id,
                'outcome' => $input['outcome'], 'assessment' => $input['assessment'], 'justification' => $input['justification'], 'created_by' => $actor->id])->refresh();
            $this->audit->record($lab, $actor, new AuditEvent('clinical_reason.local_review_evaluated', 'local_clinical_reason_review', $review->id,
                oldValues: ['resolution_id' => $previous?->id], newValues: ['resolution_id' => $resolution->id, 'outcome' => $resolution->outcome, 'exception_request_id' => $review->exception_request_id],
                metadata: ['justification' => $input['justification'], 'result' => 'succeeded']));

            return ['data' => $resolution, 'review_status' => $resolution->outcome === 'favorable' ? 'resolved' : 'pending'];
        });
    }

    private function reason(Laboratory $lab, int $id): LaboratoryClinicalReason
    {
        return LaboratoryClinicalReason::forLaboratory($lab)->findOrFail($id);
    }

    private function current(Laboratory $lab, int $id, mixed $expected): LaboratoryClinicalReasonVersion
    {
        if (! is_int($expected)) {
            throw ValidationException::withMessages(['expected_number' => 'Use a JSON integer version number.']);
        }
        $reason = LaboratoryClinicalReason::forLaboratory($lab)->whereKey($id)->lockForUpdate()->firstOrFail();
        $version = $reason->latestVersion;
        if ($version === null || $version->number !== $expected) {
            throw new ClinicalConflict('clinical_reason_version_conflict', 'Reload the current version.');
        }

        return $version;
    }

    private function review(Laboratory $lab, int $reasonId, int $id): LocalClinicalReasonReview
    {
        $this->reason($lab, $reasonId);

        return LocalClinicalReasonReview::forLaboratory($lab)->where('reason_id', $reasonId)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function result(Laboratory $lab, User $actor, LaboratoryClinicalReason $reason, LaboratoryClinicalReasonVersion $version, ?LaboratoryClinicalReasonVersion $previous, string $action): array
    {
        $event = $this->audit->record($lab, $actor, new AuditEvent('clinical_reason.local_'.$action, 'laboratory_clinical_reason', $reason->id,
            oldValues: $previous ? ['version_id' => $previous->id, 'number' => $previous->number] : null,
            newValues: ['version_id' => $version->id, 'number' => $version->number, 'is_active' => $version->is_active],
            metadata: ['source' => 'local', 'justification' => $version->change_reason, 'result' => 'succeeded']));

        return ['data' => ['reason' => $reason, 'version' => $version, 'audit_id' => $event->id]];
    }

    private function input(array $input, array $rules): void
    {
        Validator::make($input, $rules)->validate();
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['input' => 'Only documented fields are accepted.']);
        }
    }

    private function page(LengthAwarePaginator $page): array
    {
        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
    }
}
