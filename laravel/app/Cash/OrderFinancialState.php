<?php

namespace App\Cash;

use App\ClinicalExams\ClinicalConflict;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OrderFinancialState
{
    /** Called only at the end of atomic creation, with the definitive total. */
    public function initialize(LaboratoryOrder $order): void
    {
        $time = now()->toISOString();
        DB::table('laboratory_order_financial_states')->insert([
            'laboratory_id' => $order->laboratory_id, 'laboratory_order_id' => $order->id,
            'revision' => 1, 'updated_at' => $time,
        ]);
        $this->revision($order, 1, null, 'initialized', $order->created_by, $time);
    }

    /** The caller holds the order lock and has actually changed its total. */
    public function totalChanged(LaboratoryOrder $order, ?User $actor): void
    {
        $state = DB::table('laboratory_order_financial_states')->where('laboratory_id', $order->laboratory_id)
            ->where('laboratory_order_id', $order->id)->lockForUpdate()->first();
        if ($state === null) {
            return; // No historical backfill; composition is initialized once at the end.
        }
        $revision = $this->next($state->revision);
        $time = now()->toISOString();
        DB::table('laboratory_order_financial_states')->where('id', $state->id)->update(['revision' => $revision, 'updated_at' => $time]);
        $this->revision($order, $revision, $state->revision, 'order_total_changed', $actor?->id, $time);
    }

    public function next(int $current): int
    {
        if ($current === 2147483647) {
            throw new ClinicalConflict('cash_revision_exhausted', 'La revisión financiera alcanzó su límite.');
        }

        return $current + 1;
    }

    public function revision(LaboratoryOrder $order, int $revision, ?int $previous, string $cause, ?int $actor, string $time, ?int $movement = null): void
    {
        DB::table('laboratory_order_financial_revisions')->insert([
            'laboratory_id' => $order->laboratory_id, 'laboratory_order_id' => $order->id,
            'revision' => $revision, 'previous_revision' => $previous, 'cause' => $cause,
            'resource_type' => $movement === null ? null : 'cash_movement', 'resource_id' => $movement,
            'created_by' => $actor, 'recorded_at' => $time, 'total_snapshot' => $order->total,
        ]);
    }
}
