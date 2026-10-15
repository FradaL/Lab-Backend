<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'clinical_revision', 'finalized_by', 'finalized_at', 'lines_snapshot'])]
class ClinicalOrderFinalization extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['finalized_at' => 'immutable_datetime', 'lines_snapshot' => 'array', 'clinical_revision' => 'integer'];
    }
}
