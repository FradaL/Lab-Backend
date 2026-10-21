<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'association_id', 'decision', 'created_at'])]
class LaboratorySampleProcessingDependency extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['decision' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
