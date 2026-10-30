<?php

namespace App\ClinicalReplacements;

use App\ClinicalValidation\ValidationAuthority;
use App\Models\Laboratory;
use App\Models\User;

final class ReplacementAuthority
{
    public const VIEW = 'orders.clinical_replacements.view';

    public const RECORD = 'orders.clinical_replacements.record';

    public const EVALUATE = 'orders.clinical_replacements.evaluate';

    public const PERMISSIONS = [self::VIEW, self::RECORD, self::EVALUATE];

    public function __construct(private ValidationAuthority $authority) {}

    public function check(Laboratory $lab, User $actor, string $permission): void
    {
        $this->authority->access($lab, $actor, $permission);
    }
}
