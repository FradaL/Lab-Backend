<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id', 'revision', 'status', 'captured_by', 'captured_at'])]
class LaboratoryOrderResultCapture extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrderExam::class, 'laboratory_order_exam_id');
    }
}
