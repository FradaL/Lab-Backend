<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id', 'approved_revision', 'transition_revision', 'results_snapshot', 'reference_snapshot', 'context_snapshot', 'validation_policy', 'validated_by', 'professional_id', 'professional_snapshot', 'validated_at', 'comment'])]
class LaboratoryResultValidation extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['results_snapshot' => 'array', 'reference_snapshot' => 'array', 'context_snapshot' => 'array', 'professional_snapshot' => 'array', 'validated_at' => 'immutable_datetime'];
    }
}
