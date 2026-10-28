<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['laboratory_id', 'reason_id', 'number', 'name', 'description', 'classification', 'evidence_requirements', 'is_active', 'change_reason', 'created_by'])]
class LaboratoryClinicalReasonVersion extends Model
{
    use BelongsToLaboratory, ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function reason(): BelongsTo
    {
        return $this->belongsTo(LaboratoryClinicalReason::class, 'reason_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['number' => 'integer', 'evidence_requirements' => 'array', 'is_active' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
