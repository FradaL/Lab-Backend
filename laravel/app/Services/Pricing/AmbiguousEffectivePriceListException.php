<?php

namespace App\Services\Pricing;

use LogicException;

final class AmbiguousEffectivePriceListException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Multiple active price-list assignments are effective for the same commercial context and date.');
    }
}
