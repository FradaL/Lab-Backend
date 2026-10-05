<?php

namespace App\Services\LaboratoryOrders;

final readonly class LaboratoryOrderEconomicTotals
{
    public function __construct(
        public string $subtotal,
        public string $discount,
        public string $taxes,
        public string $total,
    ) {}
}
