<?php

namespace App\Services\LaboratoryOrders;

use App\Cash\OrderFinancialState;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\User;

final class RecalculateLaboratoryOrderEconomics
{
    public function __construct(
        private readonly LaboratoryOrderEconomicCalculator $calculator,
        private readonly OrderFinancialState $financial,
    ) {}

    /**
     * The caller must hold the order row lock inside its current transaction.
     */
    public function execute(Laboratory $laboratory, LaboratoryOrder $order, ?User $actor = null): void
    {
        $lines = LaboratoryOrderExam::forLaboratory($laboratory)
            ->where('laboratory_order_id', $order->id)
            ->get(['id', 'unit_price']);
        $totals = $this->calculator->calculate($order, $lines);

        $previousTotal = $order->total;
        $order->update([
            'subtotal' => $totals->subtotal,
            'discount' => $totals->discount,
            'taxes' => $totals->taxes,
            'total' => $totals->total,
        ]);
        if ($previousTotal !== $order->total) {
            $this->financial->totalChanged($order, $actor);
        }
    }
}
