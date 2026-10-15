<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_sample_id', 'sample_requirement_id', 'associated_by', 'associated_at', 'released_by', 'released_at', 'release_reason'])]
class LaboratorySampleAssociation extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['associated_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(LaboratorySample::class, 'laboratory_sample_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrderSampleRequirement::class, 'sample_requirement_id');
    }
}
