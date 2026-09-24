<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_names' => $this->first_names,
            'last_names' => $this->last_names,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'affiliation_number' => $this->affiliation_number,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
