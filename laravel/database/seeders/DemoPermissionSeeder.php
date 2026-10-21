<?php

namespace Database\Seeders;

use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class DemoPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $previousTeam = getPermissionsTeamId();
        $registrar = app(PermissionRegistrar::class);
        try {
            DemoDataset::run(function (): void {
                $lab = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);
                setPermissionsTeamId($lab->id);
                DemoDataset::remember('demo_role_setup', 'v1', fn ($id) => Laboratory::whereKey($lab->id)->whereKey($id)->first(), function () use ($lab) {
                    RbacCatalog::assertValid();
                    foreach (RbacCatalog::PERMISSIONS as $name) {
                        Permission::findOrCreate($name, RbacCatalog::GUARD);
                    }
                    foreach (RbacCatalog::rolePermissions() as $name => $permissions) {
                        DemoDataset::create(Role::class, ['laboratory_id' => $lab->id, 'name' => $name, 'guard_name' => RbacCatalog::GUARD], factory: function () use ($lab, $name, $permissions) {
                            $role = Role::create(['laboratory_id' => $lab->id, 'name' => $name, 'guard_name' => RbacCatalog::GUARD]);
                            $role->syncPermissions($permissions);

                            return $role;
                        });
                    }
                    foreach (['admin@donqerlab.test' => ['administrator'], 'reception@donqerlab.test' => ['receptionist', 'cashier']] as $email => $roles) {
                        $user = DemoDataset::owned(User::class, ['email' => $email]);
                        $user->assignRole($roles);
                    }

                    return $lab;
                });
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
