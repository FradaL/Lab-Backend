<?php

namespace App\Models;

use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reason_id', 'version_id', 'from_version_id', 'revision', 'previous_id', 'classification', 'justification', 'created_by'])]
class GlobalClinicalReasonImpact extends Model
{
    use ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function reason(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReason::class, 'reason_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReasonVersion::class, 'version_id');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReasonImpact::class, 'previous_id');
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
