<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'resolution_type', 'validation_id', 'exception_decision_id', 'resolved_by', 'resolved_at', 'evidence_snapshot'])]
class ClinicalLineResolution extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime', 'evidence_snapshot' => 'array'];
    }
}
