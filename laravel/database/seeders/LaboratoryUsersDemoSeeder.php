<?php

namespace Database\Seeders;

use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class LaboratoryUsersDemoSeeder extends Seeder
{
    public const PRIMARY_LABORATORY_NIT = '000000000001';

    public const SECONDARY_LABORATORY_NIT = 'DEMO-BE07-2';

    /** @var array<string, string> */
    public const USERS = [
        'owner@donqerlab.test' => 'Propietario Demo',
        'admin@donqerlab.test' => 'Administrador Demo',
        'reception@donqerlab.test' => 'Recepción Demo',
        'technician@donqerlab.test' => 'Técnico Demo',
        'professional@donqerlab.test' => 'Profesional Demo',
        'inactive@donqerlab.test' => 'Usuario Inactivo Demo',
    ];

    public function run(): void
    {
        $previousTeam = getPermissionsTeamId();
        $registrar = app(PermissionRegistrar::class);

        try {
            DemoDataset::run(function (): void {
                $password = $this->password();
                $primary = DemoDataset::owned(Laboratory::class, ['nit' => self::PRIMARY_LABORATORY_NIT]);
                $secondary = DemoDataset::owned(Laboratory::class, ['nit' => self::SECONDARY_LABORATORY_NIT]);

                $this->provisionRoles($primary);
                $this->provisionRoles($secondary);

                $users = [];
                foreach (self::USERS as $email => $name) {
                    $users[$email] = DemoDataset::create(
                        User::class,
                        ['email' => $email],
                        factory: function () use ($email, $name, $password): User {
                            $user = User::query()->create([
                                'name' => $name,
                                'email' => $email,
                                'password' => Hash::make($password),
                                'is_active' => true,
                            ]);
                            $user->forceFill(['email_verified_at' => now()])->save();

                            return $user;
                        },
                    );
                    $this->initializePassword($users[$email], $email, $password);
                }

                $memberships = [
                    [$primary, $users['owner@donqerlab.test'], ['owner'], true, true],
                    [$secondary, $users['owner@donqerlab.test'], ['laboratory_technician'], true, false],
                    [$primary, $users['admin@donqerlab.test'], ['administrator'], true, true],
                    [$primary, $users['reception@donqerlab.test'], ['receptionist', 'cashier'], true, true],
                    [$secondary, $users['technician@donqerlab.test'], ['laboratory_technician'], true, true],
                    [$primary, $users['professional@donqerlab.test'], ['laboratory_technician'], true, true],
                    [$primary, $users['inactive@donqerlab.test'], ['viewer'], false, false],
                ];

                foreach ($memberships as [$laboratory, $user, $roles, $active, $default]) {
                    DemoDataset::create(LaboratoryUser::class, [
                        'laboratory_id' => $laboratory->id,
                        'user_id' => $user->id,
                    ], [
                        'is_active' => $active,
                        'is_default' => $default,
                    ]);
                    $this->assignRoles($laboratory, $user, $roles);
                }
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    private function password(): string
    {
        $password = getenv('DONQER_DEMO_USER_PASSWORD');

        if ((! is_string($password) || mb_strlen($password) < 12) && app()->environment('testing')) {
            return bin2hex(random_bytes(24));
        }

        if (! is_string($password) || mb_strlen($password) < 12) {
            throw new LogicException('DONQER_DEMO_USER_PASSWORD must contain at least 12 characters.');
        }

        return $password;
    }

    private function initializePassword(User $user, string $email, string $password): void
    {
        DemoDataset::remember(
            'laboratory_users_demo_password',
            hash('sha256', $email),
            fn (int $id): ?User => User::query()->whereKey($id)->where('email', $email)->first(),
            function () use ($user, $password): User {
                $user->forceFill(['password' => Hash::make($password)])->save();

                return $user;
            },
        );
    }

    private function provisionRoles(Laboratory $laboratory): void
    {
        RbacCatalog::assertValid();
        foreach (RbacCatalog::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, RbacCatalog::GUARD);
        }

        setPermissionsTeamId($laboratory->id);
        foreach (RbacCatalog::rolePermissions() as $name => $permissions) {
            DemoDataset::create(
                Role::class,
                [
                    'laboratory_id' => $laboratory->id,
                    'name' => $name,
                    'guard_name' => RbacCatalog::GUARD,
                ],
                factory: function () use ($laboratory, $name, $permissions): Role {
                    $role = Role::query()->create([
                        'laboratory_id' => $laboratory->id,
                        'name' => $name,
                        'guard_name' => RbacCatalog::GUARD,
                    ]);
                    $role->syncPermissions($permissions);

                    return $role;
                },
            );
        }
    }

    /** @param list<string> $roles */
    private function assignRoles(Laboratory $laboratory, User $user, array $roles): void
    {
        DemoDataset::remember(
            'laboratory_users_demo_role',
            hash('sha256', $laboratory->id.':'.$user->id.':'.implode(',', $roles)),
            fn (int $id): ?User => User::query()->whereKey($user->id)->whereKey($id)->first(),
            function () use ($laboratory, $user, $roles): User {
                setPermissionsTeamId($laboratory->id);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncRoles(array_map(
                    fn (string $role): Role => Role::findByName($role, RbacCatalog::GUARD),
                    $roles,
                ));
                $user->unsetRelation('roles')->unsetRelation('permissions');

                return $user;
            },
        );
    }
}
