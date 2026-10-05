<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LaboratoryOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'ordered_at' => $this->ordered_at?->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'notes' => $this->notes,
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
            'price_list' => [
                'id' => $this->price_list_id,
                'name' => $this->price_list_name,
                'currency' => $this->currency,
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ],
            'subtotal' => $this->subtotal,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount' => $this->discount,
            'taxes' => $this->taxes,
            'total' => $this->total,
            'currency' => $this->currency,
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
