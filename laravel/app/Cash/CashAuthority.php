<?php

namespace App\Cash;

use App\ClinicalValidation\ValidationAuthority;
use App\Models\Laboratory;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CashAuthority
{
    public const VIEW = 'payments.view';

    public const CREATE = 'payments.create';

    public const VOID = 'payments.void';

    public const REFUND = 'payments.refund';

    public const CREDIT = 'payments.credit.authorize';

    public const PERMISSIONS = [self::VIEW, self::CREATE, self::VOID, self::REFUND, self::CREDIT];

    public function __construct(private ValidationAuthority $authority) {}

    /** Execute resource reads/writes in this callback, scoped to the supplied laboratory. */
    public function run(Laboratory $lab, User $actor, string $permission, Closure $operation): mixed
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            throw new InvalidArgumentException('Unknown cash capability.');
        }

        return DB::transaction(function () use ($lab, $actor, $permission, $operation): mixed {
            $this->authority->access($lab, $actor, $permission);

            return $operation();
        }, 3);
    }
}
