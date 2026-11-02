<?php

namespace Tests\Feature;

use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\AdminDemoPermissionSeeder;
use Database\Seeders\DemoDataset;
use Database\Seeders\LaboratoryUsersDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDemoPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    private function demo(bool $registeredUser = true): array
    {
        return DemoDataset::run(function () use ($registeredUser): array {
            $lab = DemoDataset::create(Laboratory::class, ['nit' => LaboratoryUsersDemoSeeder::PRIMARY_LABORATORY_NIT], factory: fn () => Laboratory::factory()->create([
                'nit' => LaboratoryUsersDemoSeeder::PRIMARY_LABORATORY_NIT, 'validation_policy' => 'permission_based',
            ]));
            $createUser = fn () => User::factory()->create(['email' => 'admin@donqerlab.test']);
            $admin = $registeredUser ? DemoDataset::create(User::class, ['email' => 'admin@donqerlab.test'], factory: $createUser) : $createUser();
            $admin->laboratories()->attach($lab, ['is_active' => true, 'is_default' => true]);
            Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

            return [$lab, $admin];
        });
    }

    public function test_seed_is_repeatable_scoped_and_visible_in_effective_authorization_without_changing_roles(): void
    {
        [$lab, $admin] = $this->demo();
        $otherLab = Laboratory::factory()->create();
        $admin->laboratories()->attach($otherLab, ['is_active' => true]);
        $otherUser = $this->createLaboratoryMember($lab);
        setPermissionsTeamId($lab->id);
        $role = Role::create(['name' => 'administrator', 'guard_name' => 'web', 'laboratory_id' => $lab->id]);
        $role->givePermissionTo(Permission::findOrCreate('orders.view', 'web'));
        $admin->assignRole($role);
        $roleBefore = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $admin->givePermissionTo(Permission::findOrCreate('results.capture', 'web'));
        setPermissionsTeamId($otherLab->id);

        $this->seed(AdminDemoPermissionSeeder::class);
        $this->assertSame($otherLab->id, getPermissionsTeamId());
        $before = DB::table('model_has_permissions')->orderBy('laboratory_id')->orderBy('permission_id')->orderBy('model_id')->get()->toJson();
        $this->seed(AdminDemoPermissionSeeder::class);
        $this->assertSame($before, DB::table('model_has_permissions')->orderBy('laboratory_id')->orderBy('permission_id')->orderBy('model_id')->get()->toJson());
        $this->assertSame($roleBefore, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());

        setPermissionsTeamId($lab->id);
        $admin->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertTrue($admin->can('results.validate'));
        foreach (['orders.clinical_finalization.view', 'orders.exams.clinical_finalize', 'orders.clinical_finalize', 'reports.issue', 'reports.view_history', 'reports.download'] as $permission) {
            $this->assertTrue($admin->can($permission));
            $this->assertFalse($otherUser->fresh()->can($permission));
        }
        $this->assertTrue($admin->can('results.capture'));
        $this->assertFalse($otherUser->fresh()->can('results.validate'));
        $this->actingAs($admin)->withHeader('X-Laboratory-ID', (string) $lab->id);
        $permissions = $this->getJson('/api/v1/auth/authorization')->assertOk()->json('data.permissions');
        $this->assertContains('results.validate', $permissions);
        $this->assertContains('reports.issue', $permissions);
        $this->assertContains('reports.download', $permissions);
        $this->assertContains('reports.view_history', $permissions);
        $this->assertContains('orders.clinical_finalize', $permissions);
        setPermissionsTeamId($otherLab->id);
        $this->assertFalse($admin->fresh()->can('results.validate'));
        foreach (['orders.clinical_finalization.view', 'orders.exams.clinical_finalize', 'orders.clinical_finalize', 'reports.issue', 'reports.view_history', 'reports.download'] as $permission) {
            $this->assertFalse($admin->fresh()->can($permission));
        }
    }

    public function test_seeder_rejects_an_unregistered_user_even_with_the_same_demo_email(): void
    {
        [, $admin] = $this->demo(false);
        try {
            $this->seed(AdminDemoPermissionSeeder::class);
            $this->fail('An unregistered identity must not receive demo grants.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('provenance', $error->getMessage());
        }
        $this->assertDatabaseMissing('model_has_permissions', ['model_id' => $admin->id]);
    }

    public function test_seeder_refuses_production_without_granting_permissions(): void
    {
        [, $admin] = $this->demo();
        $previous = app()->environment();
        app()->instance('env', 'production');
        try {
            (new AdminDemoPermissionSeeder)->run();
            $this->fail('Demo grants must not run in production.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseMissing('model_has_permissions', ['model_id' => $admin->id]);
    }
}
