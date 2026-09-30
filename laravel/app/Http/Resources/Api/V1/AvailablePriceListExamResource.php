<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailablePriceListExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->laboratoryExam->id,
            'code' => $this->laboratoryExam->code,
            'name' => $this->laboratoryExam->name,
            'price' => $this->price,
            'laboratory_area' => [
                'id' => $this->laboratoryExam->laboratoryArea->id,
                'code' => $this->laboratoryExam->laboratoryArea->code,
                'name' => $this->laboratoryExam->laboratoryArea->name,
            ],
            'sample_type' => [
                'id' => $this->laboratoryExam->sampleType->id,
                'name' => $this->laboratoryExam->sampleType->name,
            ],
        ];
    }
}
