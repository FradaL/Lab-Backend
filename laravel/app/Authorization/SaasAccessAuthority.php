<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SaasAccessAuthority
{
    public const MANAGE = 'saas.access.manage';

    /** Shared with the PostgreSQL statement triggers; always acquire before user/grant row locks. */
    public function lock(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() === 0) {
            throw new LogicException('SaaS access operations require a PostgreSQL transaction.');
        }
        DB::select('SELECT pg_advisory_xact_lock(182045,1)');
    }

    public function authorize(?User $actor, string $capability = self::MANAGE): void
    {
        abort_if($actor === null, 401, 'Authentication required.');
        $allowed = DB::table('saas_capability_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('u.id', $actor->id)->where('u.is_active', true)->where('a.capability_code', $capability)->whereNull('a.revoked_at')->exists();
        abort_unless($allowed, 403, 'An active user with an explicit SaaS capability is required.');
    }
}
