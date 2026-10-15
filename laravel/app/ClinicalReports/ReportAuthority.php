<?php

namespace App\ClinicalReports;

use App\ClinicalValidation\ValidationAuthority;
use App\Models\Laboratory;
use App\Models\User;

final class ReportAuthority
{
    public function __construct(private ValidationAuthority $authority) {}

    public function check(Laboratory $lab, User $actor, string $permission, bool $lock = true): void
    {
        $current = Laboratory::whereKey($lab->id)->sharedLock()->firstOrFail();
        abort_unless($current->is_active, 403, 'Active laboratory required.');
        $this->authority->permission($lab, $actor, $permission, $lock);
        $subscriptions = $current->subscriptions()->active();
        if ($lock) {
            $subscriptions->sharedLock();
        }
        abort_unless($subscriptions->get()->contains(fn ($s) => $s->allowsAccess()), 403, 'Active subscription required.');
    }
}
