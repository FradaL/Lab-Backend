<?php

namespace App\Authorization;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\User;
use App\UserAdministration\UserAdministrationConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionManager
{
    public const array PROTECTED_ROLES = ['owner', 'administrator'];

    public function __construct(private AuditWriter $audit) {}

    public function show(Laboratory $laboratory, string $identifier): array
    {
        // A shared laboratory lock gives one consistent revision + pivot snapshot.
        return DB::transaction(function () use ($laboratory, $identifier): array {
            Laboratory::query()->whereKey($laboratory->id)->sharedLock()->firstOrFail();

            return $this->matrix($this->resolve($laboratory, $identifier));
        });
    }

    public function replace(Laboratory $laboratory, User $actor, string $identifier, array $input): array
    {
        return DB::transaction(function () use ($laboratory, $actor, $identifier, $input): array {
            // Same serialization point as membership changes: reauthorize after waiting.
            Laboratory::query()->whereKey($laboratory->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) getPermissionsTeamId() === (int) $laboratory->id
                && User::query()->whereKey($actor->id)->where('is_active', true)->exists()
                && LaboratoryUser::query()->where('laboratory_id', $laboratory->id)->where('user_id', $actor->id)->where('is_active', true)->exists(), 403);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($actor)->authorize('roles.permissions.manage');

            $role = $this->resolve($laboratory, $identifier, true);
            abort_if(in_array($role->name, self::PROTECTED_ROLES, true), 403, 'Los permisos de este rol están protegidos.');
            if ((int) $role->permissions_revision !== (int) $input['expected_revision']) {
                throw new UserAdministrationConflict('role_permissions_revision_conflict', 'La configuración cambió. Consulte la matriz e intente nuevamente.');
            }
            $before = $role->permissions()->orderBy('name')->pluck('name')->all();
            $after = $input['permissions'];
            sort($after);
            // Defense in depth for callers outside HTTP; never create arbitrary permissions.
            abort_if(count($after) !== count(array_unique($after)) || array_diff($after, RbacCatalog::PERMISSIONS) !== [], 422);
            $ids = Permission::query()->where('guard_name', RbacCatalog::GUARD)->whereIn('name', $after)->pluck('id')->all();
            abort_unless(count($ids) === count($after), 422, 'El catálogo operativo debe estar aprovisionado.');
            $revision = (int) $role->permissions_revision;
            // Use Spatie's relation, avoiding syncPermissions()/saved cache flush before commit.
            $role->permissions()->sync($ids);
            DB::table('roles')->where('id', $role->id)->update(['permissions_revision' => $revision + 1, 'updated_at' => now()]);
            $this->audit->record($laboratory, $actor, new AuditEvent(
                'role.permissions_replaced', 'role', $role->id,
                oldValues: ['revision' => $revision, 'permissions' => $before],
                newValues: ['revision' => $revision + 1, 'permissions' => $after],
                metadata: [
                    'role_name' => $role->name, 'reason' => trim($input['reason']),
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ],
            ));
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
            $actor->unsetRelation('roles')->unsetRelation('permissions');

            return $this->matrix($role->refresh());
        });
    }

    private function resolve(Laboratory $laboratory, string $identifier, bool $lock = false): Role
    {
        $query = Role::query()->where('laboratory_id', $laboratory->id)
            ->where('guard_name', RbacCatalog::GUARD)->whereIn('name', RbacCatalog::ROLES);
        ctype_digit($identifier) ? $query->whereKey($identifier) : $query->where('name', $identifier);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function matrix(Role $role): array
    {
        $protected = in_array($role->name, self::PROTECTED_ROLES, true);

        return [
            'id' => $role->id, 'name' => $role->name,
            'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all(),
            'revision' => (int) $role->permissions_revision,
            'protected' => $protected, 'editable' => ! $protected,
        ];
    }
}
