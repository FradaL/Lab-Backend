<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id',
    'laboratory_exam_id', 'clinical_version_id', 'requirement_key', 'position',
    'sample_type_id', 'configuration', 'status', 'clinical_activity_started_at',
])]
class LaboratoryOrderSampleRequirement extends Model
{
    use BelongsToLaboratory;

    /** @return BelongsTo<LaboratoryOrderExam, $this> */
    public function orderExam(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrderExam::class, 'laboratory_order_exam_id');
    }

    public function operationalSatisfaction(): HasOne
    {
        return $this->hasOne(LaboratorySampleRequirementSatisfaction::class, 'sample_requirement_id');
    }

    protected function casts(): array
    {
        return ['configuration' => 'array', 'clinical_activity_started_at' => 'immutable_datetime'];
    }
}
