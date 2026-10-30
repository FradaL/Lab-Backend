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
        $this->authority->access($lab, $actor, $permission, $lock);
    }
}
