<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PriceList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PriceListExamCollection extends ResourceCollection
{
    /** @var class-string<PriceListExamResource> */
    public $collects = PriceListExamResource::class;

    public function __construct(
        mixed $resource,
        private readonly PriceList $priceList,
    ) {
        parent::__construct($resource);
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        $default['meta']['price_list'] = [
            'id' => $this->priceList->id,
            'name' => $this->priceList->name,
            'currency' => $this->priceList->currency,
        ];

        return $default;
    }
}
