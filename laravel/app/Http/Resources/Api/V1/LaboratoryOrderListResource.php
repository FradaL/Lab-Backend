<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LaboratoryOrder */
final class LaboratoryOrderListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'ordered_at' => $this->ordered_at?->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'clinical_revision' => $this->clinical_revision,
            'clinical_finalization' => $this->whenLoaded('clinicalFinalization', fn () => $this->clinicalFinalization === null ? null : ['id' => $this->clinicalFinalization->id, 'finalized_by' => $this->clinicalFinalization->finalized_by, 'finalized_at' => $this->clinicalFinalization->finalized_at->toISOString()]),
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ],
            'patient' => [
                'id' => $this->patient->id,
                'first_names' => $this->patient->first_names,
                'last_names' => $this->patient->last_names,
            ],
            'doctor' => $this->doctor === null ? null : [
                'id' => $this->doctor->id,
                'first_names' => $this->doctor->first_names,
                'last_names' => $this->doctor->last_names,
            ],
            'commercial_client' => $this->commercial_client_id === null ? null : [
                'id' => $this->commercial_client_id,
                'name' => $this->commercial_client_name,
                'type' => $this->commercial_client_type,
            ],
            'exam_count' => (int) $this->order_exams_count,
            'currency' => $this->currency,
            'total' => $this->total,
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ],
        ];
    }
}
