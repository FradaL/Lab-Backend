<?php

namespace App\UserAdministration;

use App\Audit\SaasAuditWriter;
use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\Models\SaasAuditLog;
use App\Models\SaasCapability;
use App\Models\SaasCapabilityAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SaasAccessManager
{
    public function __construct(private SaasAccessAuthority $authority, private SaasAuditWriter $audit) {}

    public function bootstrap(): SaasCapabilityAssignment
    {
        $configured = config('saas.bootstrap.user_id');
        if (config('saas.bootstrap.enabled') !== true || ! is_scalar($configured) || ! preg_match('/^[1-9][0-9]*$/', (string) $configured)
            || (app()->isProduction() && config('saas.bootstrap.allow_production') !== true)) {
            throw new RuntimeException('Explicit enabled bootstrap configuration and production opt-in when applicable are required.');
        }

        return DB::transaction(function () use ($configured): SaasCapabilityAssignment {
            $this->authority->lock();
            $user = User::whereKey($configured)->where('is_active', true)->first();
            if ($user === null) {
                throw new RuntimeException('The configured existing bootstrap user must be active.');
            }
            $previous = SaasAuditLog::where('source', 'bootstrap')->first();
            if ($previous !== null) {
                if ($previous->affected_user_id !== $user->id) {
                    throw new RuntimeException('Bootstrap was already completed for another user.');
                }

                return SaasCapabilityAssignment::where('grant_audit_id', $previous->id)->firstOrFail();
            }
            if (SaasCapabilityAssignment::exists()) {
                throw new RuntimeException('SaaS access is already initialized; use authorized grant management.');
            }
            $audit = $this->audit->record(null, $user, SaasAccessAuthority::MANAGE, 'saas.access.granted', 'Provisionamiento inicial explícito mediante SaasAccessBootstrapSeeder.');

            return $this->insertGrant($user, null, SaasAccessAuthority::MANAGE, $audit);
        });
    }

    public function capabilities(?User $actor): array
    {
        return $this->authorized($actor, fn () => ['data' => SaasCapability::orderBy('code')->get()->toArray()]);
    }

    public function users(?User $actor): array
    {
        return $this->authorized($actor, fn () => $this->page(User::select(['id', 'name', 'email', 'is_active'])
            ->whereIn('id', SaasCapabilityAssignment::select('user_id')->whereNull('revoked_at'))->orderBy('id')->paginate(50)));
    }

    public function assignments(?User $actor, int $userId): array
    {
        return $this->authorized($actor, function () use ($userId): array {
            User::whereKey($userId)->firstOrFail();

            return $this->page(SaasCapabilityAssignment::where('user_id', $userId)->whereNull('revoked_at')->orderBy('id')->paginate(50));
        });
    }

    public function history(?User $actor): array
    {
        return $this->authorized($actor, fn () => $this->page(SaasAuditLog::whereNull('global_reason_id')->whereNull('global_impact_declaration_id')->orderByDesc('id')->paginate(50)));
    }

    public function grant(?User $actor, int $userId, array $input): SaasCapabilityAssignment
    {
        return $this->authorized($actor, function () use ($actor, $userId, $input): SaasCapabilityAssignment {
            $this->input($input, ['capability' => ['required', 'string', Rule::exists('saas_capabilities', 'code')], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/']]);
            abort_if($userId === $actor->id, 403, 'Self-service SaaS grants are prohibited.');
            $user = User::whereKey($userId)->firstOrFail();
            if (! $user->is_active) {
                throw new ClinicalConflict('saas_target_inactive', 'The target account must be active.');
            }
            if (SaasCapabilityAssignment::where('user_id', $userId)->where('capability_code', $input['capability'])->whereNull('revoked_at')->exists()) {
                throw new ClinicalConflict('saas_capability_already_granted', 'The target already has this active capability.');
            }
            $audit = $this->audit->record($actor, $user, $input['capability'], 'saas.access.granted', $input['reason']);

            return $this->insertGrant($user, $actor, $input['capability'], $audit);
        });
    }

    public function revoke(?User $actor, int $userId, int $assignmentId, array $input): SaasCapabilityAssignment
    {
        return $this->authorized($actor, function () use ($actor, $userId, $assignmentId, $input): SaasCapabilityAssignment {
            $this->input($input, ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/']]);
            if (! is_int($input['revision'])) {
                throw ValidationException::withMessages(['revision' => 'Use a JSON integer revision.']);
            }
            $grant = SaasCapabilityAssignment::where('user_id', $userId)->whereKey($assignmentId)->lockForUpdate()->firstOrFail();
            if ($grant->revoked_at !== null || $grant->revision !== $input['revision']) {
                throw new ClinicalConflict('saas_assignment_revision_conflict', 'Reload the active assignment.');
            }
            if ($grant->capability_code === SaasAccessAuthority::MANAGE && ! DB::table('saas_capability_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('u.is_active', true)->where('a.capability_code', SaasAccessAuthority::MANAGE)->whereNull('a.revoked_at')->where('a.id', '<>', $grant->id)->exists()) {
                throw new ClinicalConflict('saas_last_access_manager', 'The last active SaaS access manager cannot be revoked.');
            }
            $audit = $this->audit->record($actor, $grant->user, $grant->capability_code, 'saas.access.revoked', $input['reason'], ['assignment_id' => $grant->id, 'revision' => $grant->revision]);
            $grant->update(['revoked_by' => $actor->id, 'revoked_at' => $audit->getRawOriginal('created_at'), 'revocation_reason' => $input['reason'], 'revocation_audit_id' => $audit->id, 'revision' => 2]);

            return $grant->refresh();
        });
    }

    private function insertGrant(User $target, ?User $actor, string $capability, SaasAuditLog $audit): SaasCapabilityAssignment
    {
        return SaasCapabilityAssignment::create(['user_id' => $target->id, 'capability_code' => $capability, 'granted_by' => $actor?->id,
            'granted_at' => $audit->getRawOriginal('created_at'), 'grant_reason' => $audit->justification, 'grant_audit_id' => $audit->id, 'revision' => 1])->refresh();
    }

    private function authorized(?User $actor, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation): mixed {
            $this->authority->lock();
            $this->authority->authorize($actor);

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

    private function page($page): array
    {
        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
    }
}
