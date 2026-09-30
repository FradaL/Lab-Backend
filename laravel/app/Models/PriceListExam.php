<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\PriceListExamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'laboratory_id',
    'price_list_id',
    'laboratory_exam_id',
    'price',
    'status',
])]
class PriceListExam extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<PriceListExamFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<LaboratoryExam, $this> */
    public function laboratoryExam(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExam::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }
}
