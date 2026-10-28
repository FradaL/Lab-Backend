<?php

namespace Database\Seeders;

use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class AdminDemoPermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'users.view',
        'users.create',
        'users.assign_role',
        'users.change_status',
        'roles.view',
        'reports.settings.view',
        'reports.settings.edit',
    ];

    public function run(): void
    {
        $previousTeam = getPermissionsTeamId();
        $registrar = app(PermissionRegistrar::class);

        try {
            DemoDataset::run(function (): void {
                $laboratory = DemoDataset::owned(Laboratory::class, [
                    'nit' => LaboratoryUsersDemoSeeder::PRIMARY_LABORATORY_NIT,
                ]);
                $admin = DemoDataset::owned(User::class, ['email' => 'admin@donqerlab.test']);

                $hasActiveMembership = LaboratoryUser::query()
                    ->where('laboratory_id', $laboratory->getKey())
                    ->where('user_id', $admin->getKey())
                    ->where('is_active', true)
                    ->exists();

                if (! $hasActiveMembership) {
                    throw new LogicException('The demo administrator requires an active membership in the primary laboratory.');
                }

                if (array_diff(self::PERMISSIONS, RbacCatalog::PERMISSIONS) !== []) {
                    throw new LogicException('Admin demo permissions must belong to the RBAC catalog.');
                }

                setPermissionsTeamId($laboratory->getKey());
                $permissions = array_map(
                    fn (string $name): Permission => Permission::findOrCreate($name, RbacCatalog::GUARD),
                    self::PERMISSIONS,
                );

                $admin->unsetRelation('roles')->unsetRelation('permissions');
                $admin->givePermissionTo($permissions);
                $admin->unsetRelation('roles')->unsetRelation('permissions');
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
