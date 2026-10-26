<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use App\Models\Concerns\ImmutableClinicalReasonRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id', 'source', 'reason_id', 'version_id', 'adoption_id', 'adoption_version_id', 'justification', 'created_by'])]
class ClinicalExceptionReasonReference extends Model
{
    use BelongsToLaboratory, ImmutableClinicalReasonRecord;

    public $timestamps = false;

    public function version(): BelongsTo
    {
        return $this->belongsTo(GlobalClinicalReasonVersion::class, 'version_id');
    }

    public function representation(): array
    {
        return $this->only(['id', 'source', 'reason_id', 'version_id', 'adoption_id', 'adoption_version_id', 'justification']) + [
            'definition' => $this->version->only(['name', 'description', 'classification', 'evidence_requirements', 'is_active']),
        ];
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
