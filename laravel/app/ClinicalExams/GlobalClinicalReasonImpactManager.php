<?php

namespace App\ClinicalExams;

use App\Audit\SaasAuditWriter;
use App\Authorization\SaasAccessAuthority;
use App\Models\GlobalClinicalReasonImpact;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GlobalClinicalReasonImpactManager
{
    public const CAPABILITY = 'saas.clinical_reasons.classify_impact';

    public function __construct(private SaasAccessAuthority $authority, private SaasAuditWriter $audit, private ClinicalReasonReviewStore $reviews) {}

    public function show(?User $actor, int $reason, int $version): array
    {
        return $this->authorized($actor, function () use ($reason, $version): array {
            $this->version($reason, $version);
            $latest = GlobalClinicalReasonImpact::where('version_id', $version)->orderByDesc('revision')->first();

            return ['data' => ['classification' => $latest?->classification ?? 'unclassified', 'declaration' => $latest]];
        });
    }

    public function history(?User $actor, int $reason, int $version): array
    {
        return $this->authorized($actor, function () use ($reason, $version): array {
            $this->version($reason, $version);
            $page = GlobalClinicalReasonImpact::where('version_id', $version)->orderByDesc('revision')->paginate(50);

            return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
        });
    }

    public function declare(?User $actor, int $reason, int $version, array $input): array
    {
        return $this->authorized($actor, function () use ($actor, $reason, $version, $input): array {
            Validator::make($input, ['expected_revision' => ['required', 'integer', 'min:0'], 'classification' => ['required', Rule::in(['unclassified', 'non_restrictive', 'restrictive'])], 'justification' => ['required', 'string', 'max:2000', 'regex:/\S/']])->validate();
            if (! is_int($input['expected_revision']) || array_diff(array_keys($input), ['expected_revision', 'classification', 'justification']) !== []) {
                throw ValidationException::withMessages(['input' => 'Use documented fields and a JSON integer expected_revision.']);
            }
            $target = $this->version($reason, $version)->lockForUpdate()->firstOrFail();
            $previous = GlobalClinicalReasonImpact::where('version_id', $version)->orderByDesc('revision')->first();
            if (($previous?->revision ?? 0) !== $input['expected_revision']) {
                throw new ClinicalConflict('clinical_reason_impact_revision_conflict', 'Reload the latest impact declaration.');
            }
            $impact = GlobalClinicalReasonImpact::create([
                'reason_id' => $reason, 'version_id' => $version, 'from_version_id' => GlobalClinicalReasonVersion::where('reason_id', $reason)->where('number', $target->number - 1)->value('id'),
                'revision' => $input['expected_revision'] + 1, 'previous_id' => $previous?->id, 'classification' => $input['classification'], 'justification' => $input['justification'], 'created_by' => $actor->id,
            ])->refresh();
            $audit = $this->audit->recordImpact($impact, $actor);
            $this->reviews->forImpact($impact, $actor);

            return ['data' => $impact, 'audit_id' => $audit->id, 'operation_id' => $audit->operation_id];
        });
    }

    private function version(int $reason, int $version): Builder
    {
        $query = GlobalClinicalReasonVersion::where('reason_id', $reason)->whereKey($version);
        abort_unless($query->exists(), 404);

        return $query;
    }

    private function authorized(?User $actor, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation): mixed {
            $this->authority->lock();
            $this->authority->authorize($actor, self::CAPABILITY);

            return $operation();
        });
    }
}
