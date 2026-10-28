<?php

namespace App\Models;

use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reason_id', 'number', 'name', 'description', 'classification', 'evidence_requirements', 'is_active', 'change_reason', 'created_by'])]
class GlobalClinicalReasonVersion extends Model
{
    use ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function reason(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReason::class, 'reason_id');
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
