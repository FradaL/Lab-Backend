<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\CommercialClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'laboratory_id',
    'name',
    'type',
    'tax_id',
    'phone',
    'email',
    'address',
    'notes',
    'status',
])]
class CommercialClient extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<CommercialClientFactory> */
    use HasFactory;

    public const TYPE_INSURANCE = 'insurance';

    public const TYPE_COMPANY = 'company';

    public const TYPE_AGREEMENT = 'agreement';

    public const TYPE_OTHER = 'other';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @return HasMany<CommercialClientPriceList, $this> */
    public function priceListAssignments(): HasMany
    {
        return $this->hasMany(CommercialClientPriceList::class);
    }

    /** @return HasMany<LaboratoryOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class);
    }
}
