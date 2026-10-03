<?php

namespace App\Services\Pricing;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class EffectivePriceListResolver
{
    public function resolve(
        Laboratory $laboratory,
        ?CommercialClient $commercialClient,
        CarbonImmutable $effectiveDate,
    ): PriceListResolution {
        if ($commercialClient === null) {
            return $this->resolveParticular($laboratory, $effectiveDate);
        }

        return $this->resolveCommercialClient($laboratory, $commercialClient, $effectiveDate);
    }

    private function resolveParticular(
        Laboratory $laboratory,
        CarbonImmutable $effectiveDate,
    ): PriceListResolution {
        $priceList = PriceList::forLaboratory($laboratory)
            ->where('is_default', true)
            ->where('status', PriceList::STATUS_ACTIVE)
            ->first();

        return new PriceListResolution(
            context: PriceListResolution::CONTEXT_PARTICULAR,
            effectiveDate: $effectiveDate,
            resolved: $priceList !== null,
            reason: $priceList === null ? PriceListResolution::REASON_NO_DEFAULT_PRICE_LIST : null,
            commercialClient: null,
            priceList: $priceList,
            assignment: null,
        );
    }

    private function resolveCommercialClient(
        Laboratory $laboratory,
        CommercialClient $commercialClient,
        CarbonImmutable $effectiveDate,
    ): PriceListResolution {
        $assignments = CommercialClientPriceList::forLaboratory($laboratory)
            ->where('commercial_client_id', $commercialClient->getKey())
            ->where('status', CommercialClientPriceList::STATUS_ACTIVE)
            ->where('starts_at', '<=', $effectiveDate)
            ->where(function (Builder $query) use ($effectiveDate): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $effectiveDate);
            })
            ->with('priceList')
            ->limit(2)
            ->get();

        if ($assignments->count() > 1) {
            throw new AmbiguousEffectivePriceListException;
        }

        /** @var ?CommercialClientPriceList $assignment */
        $assignment = $assignments->first();

        if ($assignment === null) {
            return new PriceListResolution(
                context: PriceListResolution::CONTEXT_COMMERCIAL_CLIENT,
                effectiveDate: $effectiveDate,
                resolved: false,
                reason: PriceListResolution::REASON_NO_EFFECTIVE_PRICE_LIST,
                commercialClient: $commercialClient,
                priceList: null,
                assignment: null,
            );
        }

        $priceList = $assignment->priceList;

        if ($priceList === null) {
            throw new LogicException('An effective assignment references a missing price list.');
        }

        $isPriceListActive = $priceList->status === PriceList::STATUS_ACTIVE;

        return new PriceListResolution(
            context: PriceListResolution::CONTEXT_COMMERCIAL_CLIENT,
            effectiveDate: $effectiveDate,
            resolved: $isPriceListActive,
            reason: $isPriceListActive ? null : PriceListResolution::REASON_ASSIGNED_PRICE_LIST_INACTIVE,
            commercialClient: $commercialClient,
            priceList: $isPriceListActive ? $priceList : null,
            assignment: $assignment,
        );
    }
}
