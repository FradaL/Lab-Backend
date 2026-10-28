<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['laboratory_id', 'reason_id', 'created_by'])]
class ClinicalReasonAdoption extends Model
{
    use BelongsToLaboratory, ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function reason(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReason::class, 'reason_id');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ClinicalReasonAdoptionVersion::class, 'adoption_id')->ofMany('revision', 'max');
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
