<?php

namespace App\ReportDelivery;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;

final class ReportCommercialEvaluator
{
    public function __construct(private CommercialComplianceProvider $provider) {}

    public function evaluate(Laboratory $lab, LaboratoryOrder $order, array $policy): array
    {
        if ($policy['mode'] === 'unrestricted') {
            $snapshot = (new CommercialAssessment('satisfied', 'none', 'delivery_policy', null, (string) $policy['revision']))->snapshot();

            return ['eligible' => true, 'reason' => 'policy_unrestricted', 'assessment' => $snapshot];
        }

        $snapshot = $this->provider->assess($lab, $order)->snapshot();
        $eligible = $snapshot['status'] === 'satisfied' && match ($policy['mode']) {
            'full_payment_required' => $snapshot['evidence_type'] === 'full_payment',
            'payment_or_credit' => in_array($snapshot['evidence_type'], ['full_payment', 'authorized_credit'], true),
            default => false,
        };

        return ['eligible' => $eligible, 'reason' => $eligible ? 'commercial_evidence_satisfied' : ($snapshot['status'] === 'unavailable' ? 'commercial_evidence_unavailable' : 'commercial_evidence_not_satisfied'), 'assessment' => $snapshot];
    }
}
