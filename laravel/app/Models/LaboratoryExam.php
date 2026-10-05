<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\LaboratoryExamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'laboratory_id',
    'laboratory_area_id',
    'sample_type_id',
    'code',
    'name',
    'description',
    'turnaround_time_minutes',
    'status',
])]
class LaboratoryExam extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<LaboratoryExamFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @return HasMany<PriceListExam, $this>
     */
    public function priceListExams(): HasMany
    {
        return $this->hasMany(PriceListExam::class);
    }

    /** @return HasMany<LaboratoryOrderExam, $this> */
    public function orderExams(): HasMany
    {
        return $this->hasMany(LaboratoryOrderExam::class);
    }

    /**
     * @return BelongsTo<LaboratoryArea, $this>
     */
    public function laboratoryArea(): BelongsTo
    {
        return $this->belongsTo(LaboratoryArea::class);
    }

    /**
     * @return BelongsTo<SampleType, $this>
     */
    public function sampleType(): BelongsTo
    {
        return $this->belongsTo(SampleType::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'turnaround_time_minutes' => 'integer',
        ];
    }
}
