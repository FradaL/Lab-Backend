<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableClinicalReasonRecord
{
    protected static function bootImmutableClinicalReasonRecord(): void
    {
        static::updating(fn () => throw new LogicException('Clinical reason records are immutable; append a version.'));
        static::deleting(fn () => throw new LogicException('Clinical reason history cannot be deleted.'));
    }
}
