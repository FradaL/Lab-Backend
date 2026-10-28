<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['laboratory_id', 'reason_id', 'adoption_id', 'version_id', 'revision', 'previous_id', 'justification', 'created_by'])]
class ClinicalReasonAdoptionVersion extends Model
{
    use BelongsToLaboratory, ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function adoption(): BelongsTo
    {
        return $this->belongsTo(ClinicalReasonAdoption::class, 'adoption_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReasonVersion::class, 'version_id');
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
