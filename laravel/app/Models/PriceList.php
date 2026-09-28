<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'laboratory_id',
    'name',
    'description',
    'currency',
    'is_default',
    'status',
])]
class PriceList extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
