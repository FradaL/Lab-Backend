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
            'commercial_client' => $this->commercialClient === null ? null : [
                'id' => $this->commercialClient->id,
                'name' => $this->commercialClient->name,
                'type' => $this->commercialClient->type,
            ],
            'price_list' => [
                'id' => $this->priceList->id,
                'name' => $this->priceList->name,
                'currency' => $this->priceList->currency,
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ],
            'subtotal' => $this->subtotal,
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
