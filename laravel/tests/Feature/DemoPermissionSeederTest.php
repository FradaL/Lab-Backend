<?php

namespace Tests\Feature;

use App\Authorization\RolePermissionManager;
use App\Cash\CashAuthority;
use App\Cash\CashPermissionManager;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\User;
use Database\Seeders\DemoPermissionSeeder;
use Database\Seeders\SaasFoundationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    private function demo(): array
    {
        $this->seed([UserSeeder::class, SaasFoundationSeeder::class]);

        return [
            Laboratory::where('nit', '000000000001')->firstOrFail(),
            User::where('email', 'admin@donqerlab.test')->firstOrFail(),
        ];
    }

    public function test_basic_seed_grants_cash_to_demo_administrator_role_and_repeats_without_duplicate_audits(): void
    {
        $rolePermissions = [...CashAuthority::PERMISSIONS, 'reports.delivery_policy.view', 'reports.delivery_policy.manage'];
        [$lab, $admin] = $this->demo();
        $otherLab = Laboratory::factory()->create();
        $admin->laboratories()->attach($otherLab, ['is_active' => true]);
        $otherAdmin = $this->createLaboratoryMember($lab);
        setPermissionsTeamId($otherLab->id);

        $this->seed(DemoPermissionSeeder::class);
        $this->assertSame($otherLab->id, getPermissionsTeamId());
        $matrix = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->seed(DemoPermissionSeeder::class);
        $this->assertSame($matrix, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertSame(1, AuditLog::where('event', 'role.permissions_replaced')->count());
        $this->assertDatabaseCount('model_has_permissions', 0);

        $this->assignLaboratoryRole($otherAdmin, $lab, 'administrator');
        setPermissionsTeamId($lab->id);
        $reception = User::where('email', 'reception@donqerlab.test')->firstOrFail();
        foreach ($rolePermissions as $permission) {
            $this->assertTrue($otherAdmin->fresh()->can($permission));
            $this->assertFalse($reception->fresh()->can($permission));
        }
        $permissions = $this->actingAs($admin)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->getJson('/api/v1/auth/authorization')->assertOk()->json('data.permissions');
        foreach ($rolePermissions as $permission) {
            $this->assertContains($permission, $permissions);
            $this->assertContains($permission, app(RolePermissionManager::class)->show($lab, 'administrator')['permissions']);
        }
        setPermissionsTeamId($lab->id);
        $this->assertTrue($admin->fresh()->can('reports.delivery_policy.manage'));
        $admin->removeRole('administrator');
        foreach ($rolePermissions as $permission) {
            $this->assertFalse($admin->fresh()->can($permission));
        }
        setPermissionsTeamId($otherLab->id);
        foreach ($rolePermissions as $permission) {
            $this->assertFalse($admin->fresh()->can($permission));
        }
    }

    public function test_reseeding_migrates_direct_cash_grants_to_the_role_and_preserves_other_permissions(): void
    {
        [$lab, $admin] = $this->demo();
        $this->seed(DemoPermissionSeeder::class);
        setPermissionsTeamId($lab->id);
        foreach (CashAuthority::PERMISSIONS as $permission) {
            app(CashPermissionManager::class)->set($lab, $admin, $admin, $permission, true, 'Concesión sintética anterior.');
        }
        $this->assignDirectLaboratoryPermission($admin, $lab, 'reports.issue');
        Role::findByName('administrator', 'web')->revokePermissionTo(CashAuthority::VIEW);

        $this->seed(DemoPermissionSeeder::class);

        $this->assertTrue($admin->fresh()->can(CashAuthority::VIEW));
        $this->assertSame(['reports.issue'], $admin->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->assertSame(2, AuditLog::where('event', 'role.permissions_replaced')->count());
        $this->assertSame(5, AuditLog::where('event', 'cash.permission_revoked')->count());
    }

    public function test_audit_failure_rolls_back_demo_roles_and_cash_grants(): void
    {
        $this->demo();
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->event === 'role.permissions_replaced') {
                throw new RuntimeException('Synthetic cash audit failure.');
            }
        });
        try {
            $this->seed(DemoPermissionSeeder::class);
            $this->fail('Audit failure must abort seeding.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic cash audit failure.', $error->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('model_has_permissions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_demo_seeder_still_refuses_production(): void
    {
        $this->demo();
        $previous = app()->environment();
        app()->instance('env', 'production');
        try {
            (new DemoPermissionSeeder)->run();
            $this->fail('Demo grants must refuse production.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseCount('model_has_permissions', 0);
    }
}
