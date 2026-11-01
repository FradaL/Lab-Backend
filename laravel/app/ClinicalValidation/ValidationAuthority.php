<?php

namespace App\ClinicalValidation;

use App\Models\Laboratory;
use App\Models\User;
use App\Models\ValidationProfessional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final class ValidationAuthority
{
    /** Shared tenant access policy for internally invoked clinical services. */
    public function access(Laboratory $lab, User $actor, string $permission, bool $lock = true): void
    {
        $laboratories = Laboratory::whereKey($lab->id);
        if ($lock) {
            $laboratories->sharedLock();
        }
        $current = $laboratories->firstOrFail();
        abort_unless($current->is_active, 403, 'Active laboratory required.');
        $this->permission($lab, $actor, $permission, $lock);
        $subscriptions = $current->subscriptions()->active();
        if ($lock) {
            $subscriptions->sharedLock();
        }
        abort_unless($subscriptions->get()->contains(fn ($s) => $s->allowsAccess()), 403, 'Active subscription required.');
    }

    public function permission(Laboratory $lab, User $actor, string $permission, bool $lock = false): void
    {
        $users = User::whereKey($actor->id)->where('is_active', true);
        $membership = DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $actor->id)->where('is_active', true);
        if ($lock) {
            $users->sharedLock();
            $membership->sharedLock();
        }
        if ((int) getPermissionsTeamId() !== $lab->id || $users->first() === null || $membership->first() === null) {
            abort(403, 'Active actor and membership in the current laboratory required.');
        }
        // Lock effective grant rows so an overlapping revocation cannot invalidate this decision.
        if ($lock) {
            DB::table('model_has_roles')->where('model_id', $actor->id)->where('model_type', $actor->getMorphClass())->where('laboratory_id', $lab->id)->sharedLock()->get();
            DB::table('model_has_permissions')->where('model_id', $actor->id)->where('model_type', $actor->getMorphClass())->where('laboratory_id', $lab->id)->sharedLock()->get();
            $roles = DB::table('model_has_roles')->where('model_id', $actor->id)->where('model_type', $actor->getMorphClass())->where('laboratory_id', $lab->id)->pluck('role_id');
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->sharedLock()->get();
        }
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::forUser($actor)->authorize($permission);
    }

    public function clinical(Laboratory $lab, User $actor, string $permission): array
    {
        $current = Laboratory::whereKey($lab->id)->sharedLock()->firstOrFail();
        $this->permission($lab, $actor, $permission, true);
        $professional = null;
        if ($current->validation_policy === 'professional_required') {
            $professional = ValidationProfessional::forLaboratory($lab)->where('user_id', $actor->id)->sharedLock()->first();
            if ($professional?->status !== 'authorized') {
                abort(403, 'An authorized professional identity in this laboratory is required.');
            }
        }

        return ['validation_policy' => $current->validation_policy, 'professional_id' => $professional?->id,
            'professional_snapshot' => $professional === null ? null : ['id' => $professional->id, 'user_id' => $professional->user_id,
                'professional_name' => $professional->professional_name, 'profession' => $professional->profession, 'registration_number' => $professional->registration_number,
                'authorized_at' => $professional->authorized_at->toISOString(), 'authorized_by' => $professional->authorized_by]];
    }
}
