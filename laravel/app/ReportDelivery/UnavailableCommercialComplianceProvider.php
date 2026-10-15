<?php

namespace App\ReportDelivery;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;

final class UnavailableCommercialComplianceProvider implements CommercialComplianceProvider
{
    public function assess(Laboratory $laboratory, LaboratoryOrder $order): CommercialAssessment
    {
        return new CommercialAssessment('unavailable', 'none', 'not_configured', null, null);
    }
}
