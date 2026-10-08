<?php

namespace Database\Seeders;

use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        RbacCatalog::assertValid();

        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();

        foreach (RbacCatalog::PERMISSIONS as $permissionName) {
            Permission::findOrCreate($permissionName, RbacCatalog::GUARD);
        }

        $permissionRegistrar->forgetCachedPermissions();

        try {
            Laboratory::query()->orderBy('id')->each(function (Laboratory $laboratory): void {
                setPermissionsTeamId($laboratory->getKey());

                foreach (RbacCatalog::rolePermissions() as $roleName => $permissions) {
                    Role::findOrCreate($roleName, RbacCatalog::GUARD)
                        ->syncPermissions($permissions);
                }
            });

            $this->assignDemoRoles();
        } finally {
            setPermissionsTeamId(null);
            $permissionRegistrar->forgetCachedPermissions();
        }
    }

    private function assignDemoRoles(): void
    {
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->first();

        if ($laboratory === null) {
            return;
        }

        setPermissionsTeamId($laboratory->getKey());

        foreach ([
            'admin@donqerlab.test' => ['administrator'],
            'reception@donqerlab.test' => ['receptionist', 'cashier'],
        ] as $email => $roleNames) {
            $user = User::query()->where('email', $email)->first();
            $hasActiveMembership = $user?->laboratories()
                ->whereKey($laboratory->getKey())
                ->wherePivot('is_active', true)
                ->exists() ?? false;

            if ($user !== null && $hasActiveMembership) {
                $roles = array_map(
                    fn (string $roleName): Role => Role::findByName($roleName, RbacCatalog::GUARD),
                    $roleNames,
                );
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncRoles($roles);
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }
}
