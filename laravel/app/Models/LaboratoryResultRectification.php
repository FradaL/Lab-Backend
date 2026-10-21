<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_order_exam_id', 'validation_id', 'from_revision', 'to_revision', 'requested_by', 'requested_at', 'reason', 'validation_policy', 'professional_id', 'professional_snapshot'])]
class LaboratoryResultRectification extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'professional_snapshot' => 'array'];
    }
}
