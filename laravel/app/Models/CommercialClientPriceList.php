<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\CommercialClientPriceListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commercial configuration whose effective dates are inclusive.
 *
 * PostgreSQL prevents overlapping active periods for the same commercial
 * client. Active means enabled, not necessarily effective on today's date.
 */
#[Fillable([
    'laboratory_id',
    'commercial_client_id',
    'price_list_id',
    'starts_at',
    'ends_at',
    'status',
])]
class CommercialClientPriceList extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<CommercialClientPriceListFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @return BelongsTo<CommercialClient, $this> */
    public function commercialClient(): BelongsTo
    {
        return $this->belongsTo(CommercialClient::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }
}
