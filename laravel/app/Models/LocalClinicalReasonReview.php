<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Appends(['status'])]
#[Fillable(['laboratory_id', 'exception_request_id', 'reason_id', 'original_version_id', 'trigger_version_id', 'cause_type', 'cause', 'created_by'])]
class LocalClinicalReasonReview extends Model
{
    use BelongsToLaboratory, ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function latestResolution(): HasOne
    {
        return $this->hasOne(LocalClinicalReasonResolution::class, 'review_id')->ofMany('revision', 'max');
    }

    public function getStatusAttribute(): string
    {
        return $this->latestResolution?->outcome === 'favorable' ? 'resolved' : 'pending';
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
