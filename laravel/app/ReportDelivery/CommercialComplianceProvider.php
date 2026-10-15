<?php

namespace App\ReportDelivery;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;

interface CommercialComplianceProvider
{
    public function assess(Laboratory $laboratory, LaboratoryOrder $order): CommercialAssessment;
}
