<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['laboratory_id', 'code', 'created_by'])]
class LaboratoryClinicalReason extends Model
{
    use BelongsToLaboratory, ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function versions(): HasMany
    {
        return $this->hasMany(LaboratoryClinicalReasonVersion::class, 'reason_id');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(LaboratoryClinicalReasonVersion::class, 'reason_id')->ofMany('number', 'max');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
