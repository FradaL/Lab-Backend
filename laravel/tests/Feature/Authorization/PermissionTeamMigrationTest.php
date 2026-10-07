<?php

namespace Tests\Feature\Authorization;

use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class PermissionTeamMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_can_roll_back_and_reapply_before_tenant_data_exists(): void
    {
        $migration = $this->migration();

        $migration->down();

        $this->assertFalse(Schema::hasColumn('roles', 'laboratory_id'));
        $this->assertFalse(Schema::hasColumn('model_has_roles', 'laboratory_id'));
        $this->assertFalse(Schema::hasColumn('model_has_permissions', 'laboratory_id'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('roles', 'laboratory_id'));
        $this->assertTrue(Schema::hasColumn('model_has_roles', 'laboratory_id'));
        $this->assertTrue(Schema::hasColumn('model_has_permissions', 'laboratory_id'));
    }

    public function test_rollback_rejects_homonymous_tenant_roles_before_mutating_schema(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        DB::table('roles')->insert([
            ['laboratory_id' => $laboratoryA->id, 'name' => 'administrator', 'guard_name' => 'web'],
            ['laboratory_id' => $laboratoryB->id, 'name' => 'administrator', 'guard_name' => 'web'],
        ]);

        try {
            $this->migration()->down();
            $this->fail('Unsafe rollback was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot disable permission teams: tenant roles would violate the global role name constraint.',
                $exception->getMessage(),
            );
            $this->assertTrue(Schema::hasColumn('roles', 'laboratory_id'));
            $this->assertDatabaseCount('roles', 2);
        }
    }

    public function test_enabling_teams_rejects_unmapped_existing_roles(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('roles')->insert([
            'name' => 'legacy-role',
            'guard_name' => 'web',
        ]);

        try {
            $migration->up();
            $this->fail('An unmapped legacy role was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot enable permission teams: existing roles have no safe laboratory mapping.',
                $exception->getMessage(),
            );
            $this->assertFalse(Schema::hasColumn('roles', 'laboratory_id'));
        } finally {
            DB::table('roles')->delete();
            $migration->up();
        }
    }

    public function test_postgresql_enforces_team_constraints_and_cross_tenant_role_integrity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint verification.');
        }

        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $user = User::factory()->create();
        $roleA = DB::table('roles')->insertGetId([
            'laboratory_id' => $laboratoryA->id,
            'name' => 'administrator',
            'guard_name' => 'web',
        ]);
        DB::table('roles')->insert([
            'laboratory_id' => $laboratoryB->id,
            'name' => 'administrator',
            'guard_name' => 'web',
        ]);
        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'patients.view',
            'guard_name' => 'web',
        ]);

        $this->assertConstraintViolation(fn () => DB::table('roles')->insert([
            'laboratory_id' => null,
            'name' => 'viewer',
            'guard_name' => 'web',
        ]));
        $this->assertConstraintViolation(fn () => DB::table('roles')->insert([
            'laboratory_id' => $laboratoryA->id,
            'name' => 'administrator',
            'guard_name' => 'web',
        ]));
        $this->assertConstraintViolation(fn () => DB::table('roles')->insert([
            'laboratory_id' => 999999,
            'name' => 'viewer',
            'guard_name' => 'web',
        ]));
        $this->assertConstraintViolation(fn () => DB::table('model_has_roles')->insert([
            'laboratory_id' => $laboratoryB->id,
            'role_id' => $roleA,
            'model_id' => $user->id,
            'model_type' => User::class,
        ]));

        DB::table('model_has_roles')->insert([
            'laboratory_id' => $laboratoryA->id,
            'role_id' => $roleA,
            'model_id' => $user->id,
            'model_type' => User::class,
        ]);
        $this->assertConstraintViolation(fn () => DB::table('model_has_roles')->insert([
            'laboratory_id' => $laboratoryA->id,
            'role_id' => $roleA,
            'model_id' => $user->id,
            'model_type' => User::class,
        ]));

        $this->assertConstraintViolation(fn () => DB::table('model_has_permissions')->insert([
            'laboratory_id' => null,
            'permission_id' => $permissionId,
            'model_id' => $user->id,
            'model_type' => User::class,
        ]));
        DB::table('model_has_permissions')->insert([
            'laboratory_id' => $laboratoryA->id,
            'permission_id' => $permissionId,
            'model_id' => $user->id,
            'model_type' => User::class,
        ]);
        $this->assertConstraintViolation(fn () => DB::table('model_has_permissions')->insert([
            'laboratory_id' => $laboratoryA->id,
            'permission_id' => $permissionId,
            'model_id' => $user->id,
            'model_type' => User::class,
        ]));

        $this->assertDatabaseHas('roles', [
            'laboratory_id' => $laboratoryA->id,
            'name' => 'administrator',
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_07_044641_enable_permission_teams_for_laboratories.php');
    }

    private function assertConstraintViolation(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL accepted data that violates permission-team constraints.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
