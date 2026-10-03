<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommercialClientPriceListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commercial_client' => [
                'id' => $this->commercialClient->id,
                'name' => $this->commercialClient->name,
                'type' => $this->commercialClient->type,
            ],
            'price_list' => [
                'id' => $this->priceList->id,
                'name' => $this->priceList->name,
                'currency' => $this->priceList->currency,
            ],
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
