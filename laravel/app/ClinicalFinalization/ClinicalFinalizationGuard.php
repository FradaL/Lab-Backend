<?php

namespace App\ClinicalFinalization;

use App\ClinicalExams\ClinicalConflict;
use App\Models\ClinicalExceptionRequest;
use App\Models\ClinicalLineResolution;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;

final class ClinicalFinalizationGuard
{
    public static function line(LaboratoryOrder $order, LaboratoryOrderExam $line, bool $validation = false): void
    {
        // Old-schema migration fixtures have no clinical-finalization module.
        if (! array_key_exists('clinical_revision', $order->getAttributes())) {
            return;
        }
        if (ClinicalLineResolution::where('laboratory_order_exam_id', $line->id)->exists()) {
            throw new ClinicalConflict('clinical_line_resolved', 'Resolved lines require a future controlled correction procedure.');
        }
        if ($validation && ClinicalExceptionRequest::where('laboratory_order_exam_id', $line->id)->where('status', 'pending')->exists()) {
            throw new ClinicalConflict('clinical_exception_pending', 'Decide the pending exception before validating this line.');
        }
    }
}
