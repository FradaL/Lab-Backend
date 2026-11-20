<?php

namespace App\ReportDelivery;

use App\Cash\CashLedgerProjection;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use Illuminate\Support\Facades\DB;

final class OrderCashCommercialComplianceProvider implements CommercialComplianceProvider
{
    public function __construct(private CashLedgerProjection $projection) {}

    public function assess(Laboratory $laboratory, LaboratoryOrder $order): CommercialAssessment
    {
        $policy = $order->delivery_policy_snapshot;
        if (! is_array($policy) || ! isset($policy['mode'], $policy['source_revision'])) return $this->unavailable($order);
        $state = DB::table('laboratory_order_financial_states')->where('laboratory_id', $laboratory->id)->where('laboratory_order_id', $order->id)->first();
        $revision = $state === null ? null : DB::table('laboratory_order_financial_revisions')->where(['laboratory_id' => $laboratory->id, 'laboratory_order_id' => $order->id, 'revision' => $state->revision])->first();
        if ($state === null || $revision === null || $revision->total_snapshot !== $order->total) return $this->unavailable($order);
        try { $totals = $this->projection->totals(DB::table('laboratory_order_cash_movements')->where(['laboratory_id' => $laboratory->id, 'laboratory_order_id' => $order->id]), $order->total); } catch (\Throwable) { return $this->unavailable($order); }
        $version = 'policy:'.$policy['source_revision'].';financial:'.$state->revision;
        if ($totals['balance_due'] === '0.00') return new CommercialAssessment('satisfied', 'full_payment', 'order_cash_v1', (string) $order->id, $version);
        if ($policy['mode'] === 'payment_or_credit' && $state->active_credit_authorization_id !== null) {
            $credit = DB::table('laboratory_order_credit_events')->where(['id' => $state->active_credit_authorization_id, 'laboratory_id' => $laboratory->id, 'laboratory_order_id' => $order->id, 'type' => 'authorized'])->first();
            if ($credit !== null && $this->sufficient($credit->amount, $totals['balance_due'])) return new CommercialAssessment('satisfied', 'authorized_credit', 'order_cash_v1', (string) $order->id, $version);
        }
        return new CommercialAssessment('not_satisfied', 'none', 'order_cash_v1', (string) $order->id, $version);
    }

    private function unavailable(LaboratoryOrder $order): CommercialAssessment { return new CommercialAssessment('unavailable', 'none', 'order_cash_v1', (string) $order->id, null); }
    private function sufficient(string $amount, string $balance): bool { return strcmp(str_pad(str_replace('.', '', $amount), 18, '0', STR_PAD_LEFT), str_pad(str_replace('.', '', $balance), 18, '0', STR_PAD_LEFT)) >= 0; }
}
