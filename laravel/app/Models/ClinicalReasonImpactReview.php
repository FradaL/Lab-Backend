<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Appends(['status'])]
#[Fillable(['laboratory_id', 'reason_id', 'adoption_id', 'adopted_version_id', 'trigger_version_id', 'impact_id', 'cause_type', 'cause', 'created_by'])]
class ClinicalReasonImpactReview extends Model
{
    use BelongsToLaboratory, ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function adoption(): BelongsTo
    {
        return $this->belongsTo(ClinicalReasonAdoption::class, 'adoption_id');
    }

    public function impact(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReasonImpact::class, 'impact_id');
    }

    public function latestResolution(): HasOne
    {
        return $this->hasOne(ClinicalReasonImpactResolution::class, 'review_id')->ofMany('revision', 'max');
    }

    public function getStatusAttribute(): string
    {
        return $this->latestResolution?->outcome === 'favorable' ? 'resolved' : 'pending';
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
