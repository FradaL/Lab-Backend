<?php

namespace App\ReportDelivery;

use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\ReportDeliveryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OrderDeliveryPolicy
{
    /** El llamador conserva estos bloqueos hasta confirmar la creación completa. */
    public function capture(Laboratory $laboratory): array
    {
        Laboratory::whereKey($laboratory->id)->sharedLock()->firstOrFail();
        $policy = ReportDeliveryPolicy::forLaboratory($laboratory)->sharedLock()->first();
        if ($policy === null) {
            throw new ClinicalConflict('delivery_policy_not_configured', 'Configura la política de pago del laboratorio antes de crear una orden.');
        }
        $history = DB::table('report_delivery_policy_changes')
            ->where('laboratory_id', $laboratory->id)->where('revision', $policy->revision)
            ->sharedLock()->first();
        if ($history === null || $history->new_mode !== $policy->mode
            || (int) $history->changed_by !== (int) $policy->updated_by
            || ! CarbonImmutable::parse($history->changed_at)->equalTo($policy->updated_at)) {
            throw new ClinicalConflict('cash_evidence_unavailable', 'La procedencia de la política de pago no está disponible. Revisa su configuración antes de crear una orden.', status: 503);
        }

        return [
            'mode' => $policy->mode,
            'source_revision' => $policy->revision,
            'source' => 'configured',
            'configured_by' => $policy->updated_by,
            'configured_at' => $policy->updated_at->toISOString(),
            'captured_at' => now()->max($policy->updated_at)->toISOString(),
        ];
    }
}
