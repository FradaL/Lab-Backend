<?php

namespace Database\Seeders;

use App\Authorization\ClinicalReasonTenantAuthority as Tenant;
use App\Authorization\RbacCatalog;
use App\Authorization\SaasAccessAuthority;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class DemoLaboratorySeeder extends Seeder
{
    public function run(): void
    {
        app(DemoClinicalReasonProvisioner::class)->database();
        $team = getPermissionsTeamId();
        try {
            DB::transaction(function (): void {
                app(SaasAccessAuthority::class)->lock();
                DemoDataset::run(function (): void {
                    $primary = DemoDataset::owned(Laboratory::class, ['nit' => 'DEMO-EXC-01']);
                    $secondary = DemoDataset::create(Laboratory::class, ['nit' => 'DEMO-DEV-02'], [
                        'name' => 'DEMO FICTICIO SEGUNDO', 'legal_name' => 'DEMO FICTICIO SEGUNDO',
                        'timezone' => 'America/Guatemala', 'currency' => 'GTQ', 'is_active' => true,
                    ]);
                    $plan = DemoDataset::owned(Plan::class, ['code' => 'EXCD']);
                    DemoDataset::create(Subscription::class, ['laboratory_id' => $secondary->id, 'plan_id' => $plan->id], [
                        'status' => 'active', 'starts_at' => '2026-01-01 00:00:00',
                    ]);
                    foreach ([$primary, $secondary] as $lab) {
                        $subscription = DemoDataset::owned(Subscription::class, ['laboratory_id' => $lab->id, 'plan_id' => $plan->id]);
                        if (! $lab->is_active || $plan->status !== 'active' || ! $subscription->allowsAccess()) {
                            throw new RuntimeException('DEMO laboratory/subscription changed; automatic repair is forbidden.');
                        }
                        setPermissionsTeamId($lab->id);
                        $permissions = [Tenant::GLOBAL_VIEW, Tenant::VIEW, Tenant::ADOPT, Tenant::UPDATE, Tenant::HISTORY];
                        $role = DemoDataset::create(Role::class, ['name' => 'owner', 'guard_name' => RbacCatalog::GUARD, 'laboratory_id' => $lab->id], [], function () use ($lab, $permissions): Role {
                            $role = Role::create(['name' => 'owner', 'guard_name' => RbacCatalog::GUARD, 'laboratory_id' => $lab->id]);
                            foreach ($permissions as $permission) {
                                $role->givePermissionTo(Permission::findOrCreate($permission, RbacCatalog::GUARD));
                            }

                            return $role;
                        });
                        sort($permissions);
                        if ($role->permissions()->orderBy('name')->pluck('name')->all() !== $permissions) {
                            throw new RuntimeException('DEMO role changed; automatic repair is forbidden.');
                        }
                        $profiles = $lab->id === $primary->id ? ['exc-demo-owner', 'demo-saas-mixed'] : ['exc-demo-owner'];
                        foreach ($profiles as $profile) {
                            $user = DemoDataset::owned(User::class, ['email' => $profile.'@donqerlab.test']);
                            $default = $lab->id === $primary->id;
                            $membership = DemoDataset::create(LaboratoryUser::class, ['laboratory_id' => $lab->id, 'user_id' => $user->id], [], function () use ($lab, $user, $role, $default): LaboratoryUser {
                                $membership = LaboratoryUser::create(['laboratory_id' => $lab->id, 'user_id' => $user->id, 'is_active' => true, 'is_default' => $default]);
                                $user->assignRole($role);

                                return $membership;
                            });
                            $user->unsetRelation('roles');
                            if (! $membership->is_active || $membership->is_default !== $default || ! $user->hasRole($role)) {
                                throw new RuntimeException('DEMO membership/role changed; automatic repair is forbidden.');
                            }
                        }
                    }
                    foreach (DemoSaasUsersSeeder::profiles() as $profile => $_) {
                        $user = DemoDataset::owned(User::class, ['email' => $profile.'@donqerlab.test']);
                        $expected = match ($profile) {
                            'exc-demo-owner' => [$primary->id, $secondary->id],
                            'demo-saas-mixed' => [$primary->id],
                            default => [],
                        };
                        sort($expected);
                        if (LaboratoryUser::where('user_id', $user->id)->orderBy('laboratory_id')->pluck('laboratory_id')->all() !== $expected) {
                            throw new RuntimeException('DEMO laboratory memberships changed; automatic repair is forbidden.');
                        }
                    }
                });
            });
        } finally {
            setPermissionsTeamId($team);
        }
    }
}
