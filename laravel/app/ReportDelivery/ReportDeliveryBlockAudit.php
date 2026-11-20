<?php

namespace App\ReportDelivery;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Models\Laboratory;
use App\Models\LaboratoryReportIssuance;
use App\Models\User;

final class ReportDeliveryBlockAudit
{
    public function __construct(private AuditWriter $audit) {}

    public function record(Laboratory $lab, User $actor, int $orderId, int $reportId, string $operation, string $reason): void
    {
        if (LaboratoryReportIssuance::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($reportId)->exists()) {
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivery_blocked', 'laboratory_report_issuance', $reportId,
                metadata: ['laboratory_order_id' => $orderId, 'report_id' => $reportId, 'operation' => $operation,
                    'purpose' => 'external_delivery', 'reason' => $reason]));
        }
    }
}
