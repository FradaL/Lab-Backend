<?php

namespace App\ReportDelivery;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalReports\PrivateReportStorage;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryReportIssuance;
use Illuminate\Support\Facades\DB;

final class ReportOperationalGuard
{
    public function __construct(private PrivateReportStorage $storage) {}

    /** The caller holds the order and issuance locks throughout the operation. */
    public function assess(LaboratoryOrder $order, LaboratoryReportIssuance $report): array
    {
        $reason = DB::scalar('SELECT report_delivery_clinical_block(?, ?, ?)', [$order->laboratory_id, $order->id, $report->id]);
        if ($reason === null) {
            try {
                $bytes = $this->storage->read($report->private_path, $report->pdf_hash);
                if (strlen($bytes) !== $report->pdf_size) {
                    $reason = 'report_storage_unavailable';
                }
            } catch (\Throwable) {
                $reason = 'report_storage_unavailable';
            }
        }

        return ['eligible' => $reason === null, 'reason' => $reason ?? 'released_current_report'];
    }

    public function assertCurrent(LaboratoryOrder $order, LaboratoryReportIssuance $report): void
    {
        $assessment = $this->assess($order, $report);
        if (! $assessment['eligible']) {
            throw new ClinicalConflict($assessment['reason'], 'The report must have current definitive clinical evidence and an intact private document.', status: $assessment['reason'] === 'report_storage_unavailable' ? 503 : 409);
        }
    }
}
