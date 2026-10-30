<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('permissions_revision')->default(1);
        });
        // Additive deployment bootstrap: preserve all existing assignments.
        DB::table('permissions')->insertOrIgnore([
            'name' => 'roles.permissions.manage', 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $permission = DB::table('permissions')->where('name', 'roles.permissions.manage')->where('guard_name', 'web')->value('id');
        DB::table('roles')->whereNotNull('laboratory_id')->where('guard_name', 'web')
            ->whereIn('name', ['owner', 'administrator'])->orderBy('id')->each(function (object $role) use ($permission): void {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permission]);
            });
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'roles.permissions.manage')->where('guard_name', 'web')->delete();
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('permissions_revision'));
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }
};
