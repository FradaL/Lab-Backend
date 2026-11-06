<?php

namespace App\Cash;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Authorization\RbacCatalog;
use App\ClinicalValidation\ValidationAuthority;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class CashPermissionManager
{
    public function __construct(private ValidationAuthority $authority, private AuditWriter $audit) {}

    /** Change one direct assignment only. Role-derived permissions retain their separate matrix. */
    public function set(Laboratory $lab, User $actor, User $target, string $permission, bool $grant, string $reason): void
    {
        abort_unless(in_array($permission, CashAuthority::PERMISSIONS, true) && trim($reason) !== '' && mb_strlen($reason) <= 1000, 422);

        DB::transaction(function () use ($lab, $actor, $target, $permission, $grant, $reason): void {
            // Same serialization point as role and membership administration; authorize after waiting.
            Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->access($lab, $actor, 'roles.permissions.manage');
            abort_unless(User::whereKey($target->id)->where('is_active', true)->sharedLock()->exists(), 403);
            abort_unless(DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $target->id)->where('is_active', true)->sharedLock()->exists(), 404);
            $target->unsetRelation('roles')->unsetRelation('permissions');
            $definition = Permission::where('guard_name', RbacCatalog::GUARD)->where('name', $permission)->firstOrFail();
            $query = DB::table('model_has_permissions')->where('laboratory_id', $lab->id)
                ->where('model_id', $target->id)->where('model_type', $target->getMorphClass())->where('permission_id', $definition->id);
            $before = $query->exists();
            if ($before === $grant) {
                return;
            }
            if ($grant) {
                DB::table('model_has_permissions')->insert(['laboratory_id' => $lab->id, 'model_id' => $target->id, 'model_type' => $target->getMorphClass(), 'permission_id' => $definition->id]);
            } else {
                $query->delete();
            }
            $this->audit->record($lab, $actor, new AuditEvent(
                $grant ? 'cash.permission_granted' : 'cash.permission_revoked', 'user', $target->id,
                oldValues: ['direct_assignment' => $before], newValues: ['direct_assignment' => $grant],
                metadata: ['permission' => $permission, 'reason' => trim($reason)],
            ));
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
            $target->unsetRelation('roles')->unsetRelation('permissions');
        });
    }
}
