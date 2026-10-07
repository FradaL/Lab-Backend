<?php

namespace Tests\Feature\Authorization;

use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SaasFoundationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    public function test_catalog_contains_exactly_the_ratified_permissions_and_roles(): void
    {
        RbacCatalog::assertValid();

        $this->assertCount(41, RbacCatalog::PERMISSIONS);
        $this->assertSame([
            'owner', 'administrator', 'receptionist', 'cashier', 'laboratory_technician', 'viewer',
        ], RbacCatalog::ROLES);
    }

    public function test_seeder_is_idempotent_for_every_laboratory_and_assigns_demo_roles(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(SaasFoundationSeeder::class);
        Laboratory::factory()->create();

        $this->seed(RolePermissionSeeder::class);
        $firstMappings = DB::table('role_has_permissions')
            ->orderBy('role_id')->orderBy('permission_id')
            ->get()->map(fn (object $mapping): array => (array) $mapping)->all();
        $this->seed(RolePermissionSeeder::class);

        $this->assertDatabaseCount('permissions', 41);
        $this->assertDatabaseCount('roles', 12);
        $this->assertSame($firstMappings, DB::table('role_has_permissions')
            ->orderBy('role_id')->orderBy('permission_id')
            ->get()->map(fn (object $mapping): array => (array) $mapping)->all());
        $this->assertSame(0, DB::table('roles')->whereNull('laboratory_id')->count());
        $this->assertSame(0, DB::table('roles')->where('guard_name', '!=', 'web')->count());
        $this->assertSame(0, DB::table('permissions')->where('guard_name', '!=', 'web')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());

        $demo = Laboratory::query()->where('nit', '000000000001')->firstOrFail();
        setPermissionsTeamId($demo->id);
        $admin = User::query()->where('email', 'admin@donqerlab.test')->firstOrFail();
        $reception = User::query()->where('email', 'reception@donqerlab.test')->firstOrFail();
        $administratorRoleId = DB::table('roles')
            ->where('laboratory_id', $demo->id)->where('name', 'administrator')->value('id');
        $this->assertDatabaseHas('model_has_roles', [
            'laboratory_id' => $demo->id,
            'role_id' => $administratorRoleId,
            'model_id' => $admin->id,
            'model_type' => User::class,
        ]);
        $this->assertTrue($admin->hasExactRoles(['administrator']));
        $this->assertTrue($reception->hasExactRoles(['receptionist', 'cashier']));
        $this->assertTrue($reception->can('orders.manage_discount'));
    }

    public function test_seeded_permission_matrix_matches_ratified_capabilities(): void
    {
        $laboratory = Laboratory::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        setPermissionsTeamId($laboratory->id);

        $owner = Role::findByName('owner', 'web');
        $administrator = Role::findByName('administrator', 'web');
        $receptionist = Role::findByName('receptionist', 'web');
        $cashier = Role::findByName('cashier', 'web');
        $technician = Role::findByName('laboratory_technician', 'web');
        $viewer = Role::findByName('viewer', 'web');

        $this->assertCount(41, $owner->permissions);
        $this->assertCount(41, $administrator->permissions);

        $this->assertTrue($receptionist->hasPermissionTo('orders.create'));
        $this->assertTrue($receptionist->hasPermissionTo('orders.add_exam'));
        $this->assertTrue($receptionist->hasPermissionTo('orders.remove_exam'));
        $this->assertFalse($receptionist->hasPermissionTo('orders.manage_discount'));
        $this->assertFalse($receptionist->hasPermissionTo('orders.change_status'));

        $this->assertTrue($cashier->hasPermissionTo('orders.manage_discount'));
        $this->assertFalse($cashier->hasPermissionTo('orders.create'));
        $this->assertFalse($cashier->hasPermissionTo('orders.change_status'));

        $this->assertTrue($technician->hasPermissionTo('orders.change_status'));
        $this->assertFalse($technician->hasPermissionTo('orders.manage_discount'));
        $this->assertFalse($technician->hasPermissionTo('orders.create'));

        $viewerPermissions = $viewer->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame([
            'branches.view',
            'commercial_clients.view',
            'commercial_price_assignments.view',
            'doctors.view',
            'exam_prices.view',
            'laboratory_areas.view',
            'laboratory_exams.view',
            'orders.view',
            'patients.view',
            'price_lists.view',
            'pricing.resolve',
            'sample_types.view',
        ], $viewerPermissions);

        $this->assertFalse($viewer->hasPermissionTo('orders.create'));
        $this->assertFalse($viewer->hasPermissionTo('exam_prices.manage'));
    }
}
