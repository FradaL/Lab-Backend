<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Pricing\PriceListResolution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceListResolution */
class PriceListResolutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'context' => $this->context,
            'commercial_client' => $this->commercialClient === null ? null : [
                'id' => $this->commercialClient->id,
                'name' => $this->commercialClient->name,
                'type' => $this->commercialClient->type,
            ],
            'effective_date' => $this->effectiveDate->toDateString(),
            'resolved' => $this->resolved,
            'reason' => $this->reason,
            'price_list' => $this->priceList === null ? null : [
                'id' => $this->priceList->id,
                'name' => $this->priceList->name,
                'currency' => $this->priceList->currency,
                'is_default' => $this->priceList->is_default,
            ],
            'assignment' => $this->assignment === null ? null : [
                'id' => $this->assignment->id,
                'starts_at' => $this->assignment->starts_at?->toDateString(),
                'ends_at' => $this->assignment->ends_at?->toDateString(),
            ],
        ];
    }
}
