<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'laboratory_exam' => [
                'id' => $this->laboratoryExam->id,
                'code' => $this->laboratoryExam->code,
                'name' => $this->laboratoryExam->name,
                'laboratory_area' => [
                    'id' => $this->laboratoryExam->laboratoryArea->id,
                    'code' => $this->laboratoryExam->laboratoryArea->code,
                    'name' => $this->laboratoryExam->laboratoryArea->name,
                ],
                'sample_type' => [
                    'id' => $this->laboratoryExam->sampleType->id,
                    'name' => $this->laboratoryExam->sampleType->name,
                ],
            ],
            'price' => $this->price,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
