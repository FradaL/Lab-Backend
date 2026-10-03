<?php

namespace App\Services\Pricing;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\PriceList;
use Carbon\CarbonImmutable;

final readonly class PriceListResolution
{
    public const CONTEXT_PARTICULAR = 'particular';

    public const CONTEXT_COMMERCIAL_CLIENT = 'commercial_client';

    public const REASON_NO_DEFAULT_PRICE_LIST = 'NO_DEFAULT_PRICE_LIST';

    public const REASON_NO_EFFECTIVE_PRICE_LIST = 'NO_EFFECTIVE_PRICE_LIST';

    public const REASON_ASSIGNED_PRICE_LIST_INACTIVE = 'ASSIGNED_PRICE_LIST_INACTIVE';

    public function __construct(
        public string $context,
        public CarbonImmutable $effectiveDate,
        public bool $resolved,
        public ?string $reason,
        public ?CommercialClient $commercialClient,
        public ?PriceList $priceList,
        public ?CommercialClientPriceList $assignment,
    ) {}
}
