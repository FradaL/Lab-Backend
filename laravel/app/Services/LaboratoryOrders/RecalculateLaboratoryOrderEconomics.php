<?php

namespace App\Services\LaboratoryOrders;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;

final class RecalculateLaboratoryOrderEconomics
{
    public function __construct(
        private readonly LaboratoryOrderEconomicCalculator $calculator,
    ) {}

    /**
     * The caller must hold the order row lock inside its current transaction.
     */
    public function execute(Laboratory $laboratory, LaboratoryOrder $order): void
    {
        $lines = LaboratoryOrderExam::forLaboratory($laboratory)
            ->where('laboratory_order_id', $order->id)
            ->get(['id', 'unit_price']);
        $totals = $this->calculator->calculate($order, $lines);

        $order->update([
            'subtotal' => $totals->subtotal,
            'discount' => $totals->discount,
            'taxes' => $totals->taxes,
            'total' => $totals->total,
        ]);
    }
}
