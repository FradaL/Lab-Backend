<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LaboratoryOrderSampleRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'laboratory_order_exam_id' => $this->laboratory_order_exam_id,
            'clinical_version_id' => $this->clinical_version_id,
            'requirement_key' => $this->requirement_key,
            'position' => $this->position,
            'status' => $this->relationLoaded('operationalSatisfaction') ? ($this->operationalSatisfaction?->status ?? 'pending') : $this->status,
            'configuration' => $this->configuration,
        ];
    }
}
