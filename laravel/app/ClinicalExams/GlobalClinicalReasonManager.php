<?php

namespace App\ClinicalExams;

use App\Audit\SaasAuditWriter;
use App\Authorization\SaasAccessAuthority;
use App\Models\GlobalClinicalReason;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\SaasAuditLog;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GlobalClinicalReasonManager
{
    public const VIEW = 'saas.clinical_reasons.view';

    public const CREATE = 'saas.clinical_reasons.create';

    public const PUBLISH = 'saas.clinical_reasons.publish';

    public const MANAGE = 'saas.clinical_reasons.manage';

    public function __construct(private SaasAccessAuthority $authority, private ClinicalReasonVersionStore $versions, private SaasAuditWriter $audit, private ClinicalReasonReviewStore $reviews) {}

    public function index(?User $actor): array
    {
        return $this->authorized($actor, self::VIEW, fn () => $this->page(GlobalClinicalReason::with('latestVersion')->orderBy('id')->paginate(50)));
    }

    public function show(?User $actor, int $reasonId): GlobalClinicalReason
    {
        return $this->authorized($actor, self::VIEW, fn () => GlobalClinicalReason::with('latestVersion')->findOrFail($reasonId));
    }

    public function history(?User $actor, int $reasonId): array
    {
        return $this->authorized($actor, self::VIEW, function () use ($reasonId): array {
            GlobalClinicalReason::findOrFail($reasonId);

            return $this->page(GlobalClinicalReasonVersion::where('reason_id', $reasonId)->orderByDesc('number')->paginate(50));
        });
    }

    public function events(?User $actor, int $reasonId): array
    {
        return $this->authorized($actor, self::VIEW, function () use ($reasonId): array {
            GlobalClinicalReason::findOrFail($reasonId);

            return $this->page(SaasAuditLog::where('global_reason_id', $reasonId)->orderByDesc('id')->paginate(50));
        });
    }

    public function create(?User $actor, array $input): array
    {
        return $this->authorized($actor, self::CREATE, function () use ($actor, $input): array {
            $this->input($input, ['code' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]{0,49}$/'], 'definition' => ['required', 'array']]);
            if (GlobalClinicalReason::where('code', $input['code'])->exists()) {
                throw new ClinicalConflict('global_clinical_reason_code_conflict', 'The global clinical reason code is already registered.');
            }
            $reason = GlobalClinicalReason::create(['code' => $input['code'], 'created_by' => $actor->id])->refresh();
            $version = $this->versions->appendGlobal($reason->id, $actor, 0, $input['definition']);

            return $this->result($actor, $reason, $version, null, self::CREATE, 'saas.clinical_reasons.created');
        });
    }

    public function publish(?User $actor, int $reasonId, array $input): array
    {
        return $this->authorized($actor, self::PUBLISH, function () use ($actor, $reasonId, $input): array {
            $this->input($input, ['expected_number' => ['required', 'integer', 'min:1'], 'definition' => ['required', 'array']]);
            $previous = $this->current($reasonId, $input['expected_number']);
            if (array_key_exists('is_active', $input['definition']) && $input['definition']['is_active'] !== $previous->is_active) {
                throw ValidationException::withMessages(['definition.is_active' => 'Use the explicit activation operation to change administrative activation.']);
            }
            $version = $this->versions->appendGlobal($reasonId, $actor, $previous->number, $input['definition']);

            return $this->result($actor, $previous->reason, $version, $previous, self::PUBLISH, 'saas.clinical_reasons.published');
        });
    }

    public function activation(?User $actor, int $reasonId, array $input): array
    {
        return $this->authorized($actor, self::MANAGE, function () use ($actor, $reasonId, $input): array {
            $this->input($input, ['expected_number' => ['required', 'integer', 'min:1'], 'is_active' => ['required', 'boolean:strict'], 'change_reason' => ['required', 'string', 'regex:/\S/']]);
            $previous = $this->current($reasonId, $input['expected_number']);
            if ($previous->is_active === $input['is_active']) {
                throw new ClinicalConflict('global_clinical_reason_activation_unchanged', 'The requested activation already matches the current version.');
            }
            $definition = $previous->only(['name', 'description', 'classification', 'evidence_requirements']);
            $version = $this->versions->appendGlobal($reasonId, $actor, $previous->number, $definition + ['is_active' => $input['is_active'], 'change_reason' => $input['change_reason']]);
            $this->reviews->forDeactivation($version, $actor);

            return $this->result($actor, $previous->reason, $version, $previous, self::MANAGE, 'saas.clinical_reasons.activation_changed');
        });
    }

    private function current(int $reasonId, mixed $expected): GlobalClinicalReasonVersion
    {
        if (! is_int($expected)) {
            throw ValidationException::withMessages(['expected_number' => 'Use a JSON integer version number.']);
        }
        $reason = GlobalClinicalReason::whereKey($reasonId)->lockForUpdate()->firstOrFail();
        $version = $reason->latestVersion;
        if ($version === null || $version->number !== $expected) {
            throw new ClinicalConflict('clinical_reason_version_conflict', 'Reload the current clinical reason version before appending.');
        }

        return $version;
    }

    private function result(User $actor, GlobalClinicalReason $reason, GlobalClinicalReasonVersion $version, ?GlobalClinicalReasonVersion $previous, string $capability, string $action): array
    {
        $event = $this->audit->recordGlobalReason($actor, $reason, $version, $previous, $capability, $action);

        return ['data' => ['reason' => $reason, 'version' => $version, 'audit_id' => $event->id, 'operation_id' => $event->operation_id]];
    }

    private function authorized(?User $actor, string $capability, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $capability, $operation): mixed {
            $this->authority->lock();
            $this->authority->authorize($actor, $capability);

            return $operation();
        });
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
