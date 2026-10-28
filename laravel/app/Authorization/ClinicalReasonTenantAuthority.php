<?php

namespace App\Authorization;

use App\Models\Laboratory;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final class ClinicalReasonTenantAuthority
{
    public const GLOBAL_VIEW = 'clinical_reasons.global.view';

    public const VIEW = 'clinical_reasons.adoptions.view';

    public const ADOPT = 'clinical_reasons.adoptions.create';

    public const UPDATE = 'clinical_reasons.adoptions.update';

    public const HISTORY = 'clinical_reasons.adoptions.history';

    public const REVIEW = 'clinical_reasons.review_impact';

    public const LOCAL_VIEW = 'clinical_reasons.local.view';

    public const LOCAL_CREATE = 'clinical_reasons.local.create';

    public const LOCAL_PUBLISH = 'clinical_reasons.local.publish';

    public const LOCAL_MANAGE = 'clinical_reasons.local.manage';

    public const LOCAL_HISTORY = 'clinical_reasons.local.history';

    public const PERMISSIONS = [self::GLOBAL_VIEW, self::VIEW, self::ADOPT, self::UPDATE, self::HISTORY, self::REVIEW, self::LOCAL_VIEW, self::LOCAL_CREATE, self::LOCAL_PUBLISH, self::LOCAL_MANAGE, self::LOCAL_HISTORY];

    public function __construct(private SaasAccessAuthority $locks) {}

    public function run(Laboratory $lab, ?User $actor, string|array $permission, Closure $operation, bool $owner = false): mixed
    {
        return DB::transaction(function () use ($lab, $actor, $permission, $operation, $owner): mixed {
            $this->locks->lock();
            abort_if($actor === null, 401);
            abort_unless((int) getPermissionsTeamId() === (int) $lab->id, 403, 'The laboratory permission context is required.');
            abort_unless(Laboratory::whereKey($lab->id)->where('is_active', true)->sharedLock()->exists(), 403);
            abort_unless(User::whereKey($actor->id)->where('is_active', true)->sharedLock()->exists(), 403);
            abort_unless(DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $actor->id)->where('is_active', true)->sharedLock()->exists(), 403);
            $subscription = $lab->subscriptions()->active()->first();
            abort_unless($subscription && $subscription->hasStarted() && ! $subscription->hasExpired(), 403, 'An active subscription is required.');
            DB::table('model_has_roles')->where('laboratory_id', $lab->id)->where('model_id', $actor->id)->where('model_type', $actor->getMorphClass())->sharedLock()->get();
            DB::table('model_has_permissions')->where('laboratory_id', $lab->id)->where('model_id', $actor->id)->where('model_type', $actor->getMorphClass())->sharedLock()->get();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            if (is_array($permission)) {
                abort_unless(Gate::forUser($actor)->any($permission), 403);
            } else {
                Gate::forUser($actor)->authorize($permission);
            }
            abort_if($owner && ! $actor->hasRole('owner'), 403, 'Explicit adoption belongs to the laboratory owner.');

            return $operation();
        });
    }
}
