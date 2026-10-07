<?php

namespace Tests\Concerns;

use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait InteractsWithPermissionTeams
{
    protected function createLaboratoryMember(Laboratory $laboratory, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => $active]);

        return $user;
    }

    protected function assignLaboratoryRole(User $user, Laboratory $laboratory, string $roleName): Role
    {
        $this->setPermissionTeam($laboratory);
        $role = Role::findOrCreate($roleName, RbacCatalog::GUARD);
        $user->assignRole($role);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $role;
    }

    protected function assignDirectLaboratoryPermission(
        User $user,
        Laboratory $laboratory,
        string $permissionName,
    ): Permission {
        $permission = Permission::findOrCreate($permissionName, RbacCatalog::GUARD);
        $this->setPermissionTeam($laboratory);
        $user->givePermissionTo($permission);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $permission;
    }

    /** @param array<int, string> $permissionNames */
    protected function assignDirectLaboratoryPermissions(
        User $user,
        Laboratory $laboratory,
        array $permissionNames,
    ): void {
        foreach ($permissionNames as $permissionName) {
            $this->assignDirectLaboratoryPermission($user, $laboratory, $permissionName);
        }
    }

    protected function assignAllOrderPermissions(User $user, Laboratory $laboratory): void
    {
        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'orders.view',
            'orders.create',
            'orders.add_exam',
            'orders.remove_exam',
            'orders.manage_discount',
            'orders.change_status',
        ]);
    }

    protected function setPermissionTeam(Laboratory $laboratory): void
    {
        setPermissionsTeamId($laboratory->getKey());
    }

    protected function clearPermissionTeam(): void
    {
        setPermissionsTeamId(null);
    }
}
