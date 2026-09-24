<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
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
            'specialty' => $this->specialty,
            'phone' => $this->phone,
            'email' => $this->email,
            'license_number' => $this->license_number,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
