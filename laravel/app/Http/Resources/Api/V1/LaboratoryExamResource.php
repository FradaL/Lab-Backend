<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaboratoryExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'turnaround_time_minutes' => $this->turnaround_time_minutes,
            'status' => $this->status,
            'laboratory_area' => [
                'id' => $this->laboratoryArea->id,
                'code' => $this->laboratoryArea->code,
                'name' => $this->laboratoryArea->name,
            ],
            'sample_type' => [
                'id' => $this->sampleType->id,
                'name' => $this->sampleType->name,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
