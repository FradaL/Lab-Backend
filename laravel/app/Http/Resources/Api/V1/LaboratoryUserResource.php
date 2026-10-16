<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaboratoryUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $professional = $this->validationProfessionals->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'membership' => [
                'id' => (int) $this->membership_id,
                'status' => $this->membership_is_active ? 'active' : 'inactive',
                'is_default' => (bool) $this->membership_is_default,
                'created_at' => $this->membership_created_at === null ? null : CarbonImmutable::parse($this->membership_created_at)->toISOString(),
                'updated_at' => $this->membership_updated_at === null ? null : CarbonImmutable::parse($this->membership_updated_at)->toISOString(),
            ],
            'roles' => $this->roles->pluck('name')->sort()->values()->all(),
            'validation_professional' => $professional === null ? null : [
                'id' => $professional->id,
                'status' => $professional->status,
            ],
            'can_register_validation_professional' => (bool) $this->membership_is_active && $professional === null,
        ];
    }
}
