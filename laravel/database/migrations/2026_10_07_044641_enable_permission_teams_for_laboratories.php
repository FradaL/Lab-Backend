<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rolesHadTeamColumn = Schema::hasColumn('roles', 'laboratory_id');
        $modelRolesHadTeamColumn = Schema::hasColumn('model_has_roles', 'laboratory_id');
        $modelPermissionsHadTeamColumn = Schema::hasColumn('model_has_permissions', 'laboratory_id');

        $this->assertExistingDataCanBeMigrated(
            $rolesHadTeamColumn,
            $modelRolesHadTeamColumn,
            $modelPermissionsHadTeamColumn,
        );

        if (! $rolesHadTeamColumn) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropUnique('roles_name_guard_name_unique');
                $table->unsignedBigInteger('laboratory_id');
                $table->index('laboratory_id', 'roles_team_foreign_key_index');
                $table->unique(['laboratory_id', 'name', 'guard_name']);
            });
        } else {
            Schema::table('roles', function (Blueprint $table): void {
                $table->unsignedBigInteger('laboratory_id')->nullable(false)->change();
            });
        }

        $this->makeModelAssignmentTeamAware(
            'model_has_roles',
            'role_id',
            'model_has_roles_role_model_type_primary',
            'model_has_roles_team_foreign_key_index',
            $modelRolesHadTeamColumn,
        );
        $this->makeModelAssignmentTeamAware(
            'model_has_permissions',
            'permission_id',
            'model_has_permissions_permission_model_type_primary',
            'model_has_permissions_team_foreign_key_index',
            $modelPermissionsHadTeamColumn,
        );

        Schema::table('roles', function (Blueprint $table): void {
            $table->foreign('laboratory_id')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->unique(['laboratory_id', 'id'], 'roles_laboratory_id_id_unique');
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->foreign('laboratory_id')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->foreign(['laboratory_id', 'role_id'])
                ->references(['laboratory_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->foreign('laboratory_id')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertRollbackIsSafe();

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropForeign(['laboratory_id', 'role_id']);
            $table->dropForeign(['laboratory_id']);
        });
        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->dropForeign(['laboratory_id']);
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropForeign(['laboratory_id']);
            $table->dropUnique('roles_laboratory_id_id_unique');
        });

        $this->restoreGlobalModelAssignments(
            'model_has_roles',
            'role_id',
            'model_has_roles_role_model_type_primary',
            'model_has_roles_team_foreign_key_index',
        );
        $this->restoreGlobalModelAssignments(
            'model_has_permissions',
            'permission_id',
            'model_has_permissions_permission_model_type_primary',
            'model_has_permissions_team_foreign_key_index',
        );

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['laboratory_id', 'name', 'guard_name']);
            $table->dropIndex('roles_team_foreign_key_index');
            $table->dropColumn('laboratory_id');
            $table->unique(['name', 'guard_name']);
        });
    }

    private function assertExistingDataCanBeMigrated(
        bool $rolesHadTeamColumn,
        bool $modelRolesHadTeamColumn,
        bool $modelPermissionsHadTeamColumn,
    ): void {
        if (! $rolesHadTeamColumn && DB::table('roles')->exists()) {
            throw new LogicException('Cannot enable permission teams: existing roles have no safe laboratory mapping.');
        }

        if (! $modelRolesHadTeamColumn && DB::table('model_has_roles')->exists()) {
            throw new LogicException('Cannot enable permission teams: existing role assignments have no safe laboratory mapping.');
        }

        if (! $modelPermissionsHadTeamColumn && DB::table('model_has_permissions')->exists()) {
            throw new LogicException('Cannot enable permission teams: existing direct permissions have no safe laboratory mapping.');
        }

        if ($rolesHadTeamColumn && DB::table('roles')->whereNull('laboratory_id')->exists()) {
            throw new LogicException('Cannot enforce tenant roles while roles with a null laboratory_id exist.');
        }
    }

    private function makeModelAssignmentTeamAware(
        string $tableName,
        string $relatedKey,
        string $primaryName,
        string $teamIndexName,
        bool $hadTeamColumn,
    ): void {
        if ($hadTeamColumn) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use (
            $relatedKey,
            $primaryName,
            $teamIndexName,
        ): void {
            $table->dropPrimary($primaryName);
            $table->unsignedBigInteger('laboratory_id');
            $table->index('laboratory_id', $teamIndexName);
            $table->primary(
                ['laboratory_id', $relatedKey, 'model_id', 'model_type'],
                $primaryName,
            );
        });
    }

    private function restoreGlobalModelAssignments(
        string $tableName,
        string $relatedKey,
        string $primaryName,
        string $teamIndexName,
    ): void {
        Schema::table($tableName, function (Blueprint $table) use (
            $relatedKey,
            $primaryName,
            $teamIndexName,
        ): void {
            $table->dropPrimary($primaryName);
            $table->dropIndex($teamIndexName);
            $table->dropColumn('laboratory_id');
            $table->primary([$relatedKey, 'model_id', 'model_type'], $primaryName);
        });
    }

    private function assertRollbackIsSafe(): void
    {
        $duplicateRole = DB::table('roles')
            ->select(['name', 'guard_name'])
            ->groupBy(['name', 'guard_name'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateRole) {
            throw new LogicException('Cannot disable permission teams: tenant roles would violate the global role name constraint.');
        }

        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $key) {
            $duplicateAssignment = DB::table($table)
                ->select([$key, 'model_id', 'model_type'])
                ->groupBy([$key, 'model_id', 'model_type'])
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicateAssignment) {
                throw new LogicException("Cannot disable permission teams: {$table} contains assignments that would collide globally.");
            }
        }
    }
};
