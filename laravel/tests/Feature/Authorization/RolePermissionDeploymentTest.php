<?php

namespace Tests\Feature\Authorization;

use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionDeploymentTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deployment_adds_management_to_protected_roles_without_replacing_existing_matrices(): void
    {
        $migration = require database_path('migrations/2026_10_30_000001_add_role_permission_revisions.php');
        $migration->down();
        $lab = Laboratory::factory()->create();
        setPermissionsTeamId($lab->id);
        Permission::findOrCreate('orders.view', RbacCatalog::GUARD);
        $roles = [];
        foreach (RbacCatalog::ROLES as $name) {
            $roles[$name] = Role::findOrCreate($name, RbacCatalog::GUARD);
            $roles[$name]->syncPermissions(['orders.view']);
        }
        $migration->up();
        foreach ($roles as $name => $role) {
            $codes = $role->permissions()->pluck('name')->all();
            $this->assertEqualsCanonicalizing(
                in_array($name, ['owner', 'administrator'], true) ? ['orders.view', 'roles.permissions.manage'] : ['orders.view'],
                $codes,
            );
            $this->assertSame(1, DB::table('roles')->where('id', $role->id)->value('permissions_revision'));
        }
        $migration->down();
        $this->assertDatabaseMissing('permissions', ['name' => 'roles.permissions.manage']);
        foreach ($roles as $role) {
            $this->assertSame(['orders.view'], $role->permissions()->pluck('name')->all());
        }
        // Restore schema so DatabaseMigrations can perform its normal rollback.
        $migration->up();
    }
}
