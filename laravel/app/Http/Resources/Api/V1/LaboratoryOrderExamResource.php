<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryOrderExam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LaboratoryOrderExam */
final class LaboratoryOrderExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam' => [
                'id' => $this->laboratory_exam_id,
                'code' => $this->exam_code,
                'name' => $this->exam_name,
            ],
            'price_list' => [
                'id' => $this->price_list_id,
                'name' => $this->price_list_name,
            ],
            'unit_price' => $this->unit_price,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
