<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'revision', 'status', 'reason', 'requested_by', 'requested_at', 'capture_revision', 'processing_started_at', 'clinical_configuration_source'])]
class ClinicalExceptionRequest extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'processing_started_at' => 'immutable_datetime', 'revision' => 'integer', 'capture_revision' => 'integer'];
    }
}
