<?php

namespace Database\Seeders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Authorization\RbacCatalog;
use App\Cash\CashAuthority;
use App\Cash\CashPermissionManager;
use App\ClinicalValidation\ValidationAuthority;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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

                // Apply the approved demo role matrix even on an existing dataset.
                $admin = DemoDataset::owned(User::class, ['email' => 'admin@donqerlab.test']);
                Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
                app(ValidationAuthority::class)->access($lab, $admin, 'roles.permissions.manage');
                $role = DemoDataset::owned(Role::class, [
                    'laboratory_id' => $lab->id, 'name' => 'administrator', 'guard_name' => RbacCatalog::GUARD,
                ]);
                $before = $role->permissions()->orderBy('name')->pluck('name')->all();
                $rolePermissions = [
                    ...CashAuthority::PERMISSIONS,
                    'reports.delivery_policy.view', 'reports.delivery_policy.manage', 'results.validate',
                    'orders.clinical_finalization.view', 'orders.exams.clinical_finalize', 'orders.clinical_finalize',
                    'reports.issue', 'reports.view_history', 'reports.download',
                    'reports.delivery.evaluate', 'reports.delivery.prepare', 'reports.delivery.confirm',
                ];
                $added = array_values(array_diff($rolePermissions, $before));
                $reason = 'Daniel Lara solicita completar Caja, política de cobro, validación, cierre técnico, informes y entrega al rol administrador demo, 2026-11-20.';
                if ($added !== []) {
                    $revision = (int) $role->permissions_revision;
                    $ids = Permission::where('guard_name', RbacCatalog::GUARD)->whereIn('name', $added)->pluck('id')->all();
                    $role->permissions()->syncWithoutDetaching($ids);
                    DB::table('roles')->where('id', $role->id)->update(['permissions_revision' => $revision + 1, 'updated_at' => now()]);
                    $after = $role->permissions()->orderBy('name')->pluck('name')->all();
                    app(AuditWriter::class)->record($lab, $admin, new AuditEvent(
                        'role.permissions_replaced', 'role', $role->id,
                        oldValues: ['revision' => $revision, 'permissions' => $before],
                        newValues: ['revision' => $revision + 1, 'permissions' => $after],
                        metadata: ['role_name' => $role->name, 'reason' => $reason, 'added' => $added, 'removed' => [], 'source' => self::class],
                    ));
                }
                // Remove only the superseded direct cash grants of this demo user.
                foreach (CashAuthority::PERMISSIONS as $permission) {
                    app(CashPermissionManager::class)->set(
                        $lab, $admin, $admin, $permission, false, $reason,
                    );
                }
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
