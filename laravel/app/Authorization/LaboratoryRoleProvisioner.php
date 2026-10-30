<?php

namespace App\Authorization;

use App\Models\Laboratory;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class LaboratoryRoleProvisioner
{
    /** Initialize missing roles only; never replace an existing matrix. */
    public function provision(Laboratory $laboratory): void
    {
        RbacCatalog::assertValid();
        $previous = getPermissionsTeamId();
        try {
            DB::transaction(function () use ($laboratory): void {
                Laboratory::query()->whereKey($laboratory->id)->lockForUpdate()->firstOrFail();
                setPermissionsTeamId($laboratory->id);
                foreach (RbacCatalog::PERMISSIONS as $code) {
                    Permission::findOrCreate($code, RbacCatalog::GUARD);
                }
                foreach (RbacCatalog::rolePermissions() as $name => $permissions) {
                    $role = Role::query()->firstOrCreate([
                        'laboratory_id' => $laboratory->id,
                        'name' => $name,
                        'guard_name' => RbacCatalog::GUARD,
                    ]);
                    if ($role->wasRecentlyCreated) {
                        $role->syncPermissions($permissions);
                    }
                }
                DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
            });
        } finally {
            setPermissionsTeamId($previous);
        }
    }
}
