<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicalExamVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'laboratory_exam_id' => $this->laboratory_exam_id, 'number' => $this->number,
            'revision' => $this->revision, 'status' => $this->status, 'base_version_id' => $this->base_version_id,
            'configuration' => $this->configuration, 'configuration_hash' => $this->configuration_hash,
            'published_at' => $this->published_at?->toIso8601String(), 'published_by' => $this->published_by,
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
