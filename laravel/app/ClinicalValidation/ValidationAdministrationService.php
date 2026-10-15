<?php

namespace App\ClinicalValidation;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\User;
use App\Models\ValidationProfessional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ValidationAdministrationService
{
    public function __construct(private AuditWriter $audit, private ValidationAuthority $authority) {}

    public function policy(Laboratory $lab, User $actor): array
    {
        $this->authority->permission($lab, $actor, 'results.validation_policy.manage');

        return ['validation_policy' => $lab->fresh()->validation_policy];
    }

    public function setPolicy(Laboratory $lab, User $actor, array $input): array
    {
        $this->fields($input, ['validation_policy' => ['required', 'in:professional_required,permission_based']]);

        return DB::transaction(function () use ($lab, $actor, $input) {
            $current = Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->permission($lab, $actor, 'results.validation_policy.manage', true);
            $old = $current->validation_policy;
            if ($old !== $input['validation_policy']) {
                $current->update($input);
                $this->audit->record($lab, $actor, new AuditEvent('result_validation.policy_changed', 'laboratory', $lab->id, oldValues: ['validation_policy' => $old], newValues: ['validation_policy' => $current->validation_policy]));
            }

            return ['validation_policy' => $current->validation_policy];
        });
    }

    public function professionals(Laboratory $lab, User $actor): array
    {
        $this->authority->permission($lab, $actor, 'results.professionals.manage');
        $page = ValidationProfessional::forLaboratory($lab)->orderBy('id')->paginate(50);

        return ['data' => $page->toArray()['data'], 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
    }

    public function register(Laboratory $lab, User $actor, array $input): ValidationProfessional
    {
        $this->fields($input, ['user_id' => ['required', 'integer', 'min:1'], 'professional_name' => ['required', 'string', 'max:150'], 'profession' => ['required', 'string', 'max:150'], 'registration_number' => ['sometimes', 'nullable', 'string', 'max:100']]);

        return DB::transaction(function () use ($lab, $actor, $input) {
            // Serialize registration and state administration with the policy/clinical decision lock.
            Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->permission($lab, $actor, 'results.professionals.manage', true);
            if (! User::whereKey($input['user_id'])->whereHas('laboratories', fn ($q) => $q->where('laboratories.id', $lab->id)->where('laboratory_user.is_active', true))->sharedLock()->exists()) {
                throw ValidationException::withMessages(['user_id' => 'An active membership in this laboratory is required.']);
            }
            if (ValidationProfessional::forLaboratory($lab)->where('user_id', $input['user_id'])->exists()) {
                throw new ClinicalConflict('professional_already_registered', 'This user already has a stable professional identity in this laboratory.');
            }
            $p = ValidationProfessional::create($input + ['laboratory_id' => $lab->id, 'status' => 'registered']);
            $this->audit->record($lab, $actor, new AuditEvent('result_validation.professional_registered', 'validation_professional', $p->id, newValues: ['user_id' => $p->user_id, 'status' => 'registered']));

            return $p;
        });
    }

    public function professionalState(Laboratory $lab, User $actor, int $id, bool $authorize, array $input = []): ValidationProfessional
    {
        $this->fields($input, $authorize ? [] : ['reason' => ['required', 'string', 'max:2000']]);
        if (! $authorize && trim($input['reason']) === '') {
            throw ValidationException::withMessages(['reason' => 'A revocation reason is required.']);
        }

        return DB::transaction(function () use ($lab, $actor, $id, $authorize, $input) {
            Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->permission($lab, $actor, 'results.professionals.manage', true);
            $p = ValidationProfessional::forLaboratory($lab)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($authorize && $p->user_id === $actor->id) {
                abort(403, 'Professional self-authorization is prohibited.');
            }
            $target = $authorize ? 'authorized' : 'revoked';
            if ($p->status === $target) {
                return $p;
            }
            if ($authorize && ! DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $p->user_id)->where('is_active', true)->sharedLock()->first()) {
                abort(403, 'An active membership is required for authorization.');
            }
            $old = $p->status;
            $p->update($authorize ? ['status' => $target, 'authorized_at' => now(), 'authorized_by' => $actor->id, 'revoked_at' => null, 'revoked_by' => null, 'revocation_reason' => null]
                : ['status' => $target, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $input['reason']]);
            $this->audit->record($lab, $actor, new AuditEvent('result_validation.professional_'.$target, 'validation_professional', $id, oldValues: ['status' => $old], newValues: ['status' => $target], metadata: ['reason' => $input['reason'] ?? null]));

            return $p;
        });
    }

    private function fields(array $input, array $rules): void
    {
        Validator::make($input, $rules)->validate();
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['input' => 'Unknown fields are prohibited.']);
        }
    }
}
