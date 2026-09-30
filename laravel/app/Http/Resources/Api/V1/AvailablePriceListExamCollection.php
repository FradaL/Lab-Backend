<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PriceList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AvailablePriceListExamCollection extends ResourceCollection
{
    /** @var class-string<AvailablePriceListExamResource> */
    public $collects = AvailablePriceListExamResource::class;

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
        $default['meta']['currency'] = $this->priceList->currency;

        return $default;
    }
}
