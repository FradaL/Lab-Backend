<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id', 'decision', 'reason', 'decided_by', 'decided_at', 'evidence_snapshot'])]
class ClinicalExceptionDecision extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'evidence_snapshot' => 'array'];
    }
}
