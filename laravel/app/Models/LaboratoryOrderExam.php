<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\LaboratoryOrderExamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'laboratory_id',
    'laboratory_order_id',
    'laboratory_exam_id',
    'price_list_id',
    'unit_price',
    'exam_code',
    'exam_name',
    'price_list_name',
])]
class LaboratoryOrderExam extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<LaboratoryOrderExamFactory> */
    use HasFactory;

    /** @return BelongsTo<LaboratoryOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class, 'laboratory_order_id');
    }

    /** @return BelongsTo<LaboratoryExam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExam::class, 'laboratory_exam_id');
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }
}
