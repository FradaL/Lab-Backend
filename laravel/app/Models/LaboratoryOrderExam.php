<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\LaboratoryOrderExamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'laboratory_id',
    'laboratory_order_id',
    'laboratory_exam_id',
    'price_list_id',
    'unit_price',
    'exam_code',
    'exam_name',
    'price_list_name',
    'clinical_version_id',
    'clinical_configuration_source',
    'clinical_activity_started_at',
    'processing_started_at', 'processing_started_by',
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

    /** @return BelongsTo<LaboratoryExamVersion, $this> */
    public function clinicalVersion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExamVersion::class, 'clinical_version_id');
    }

    /** @return HasMany<LaboratoryOrderSampleRequirement, $this> */
    public function sampleRequirements(): HasMany
    {
        return $this->hasMany(LaboratoryOrderSampleRequirement::class)->orderBy('position')->orderBy('id');
    }

    public function resultCapture(): HasOne
    {
        return $this->hasOne(LaboratoryOrderResultCapture::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(LaboratoryOrderResult::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'clinical_activity_started_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
        ];
    }
}
