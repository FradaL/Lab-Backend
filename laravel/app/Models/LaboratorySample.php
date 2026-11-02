<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'identifier', 'sample_type_id', 'provenance', 'status', 'collected_at', 'collection_branch_id', 'collected_by', 'received_at', 'receiving_branch_id', 'received_by', 'facts'])]
class LaboratorySample extends Model
{
    use BelongsToLaboratory;
    use HasFactory;

    public const DETAIL_RELATIONS = [
        'events.actor:id,name', 'associations.associatedBy:id,name', 'associations.releasedBy:id,name',
        'sampleType', 'collectionBranch', 'receivingBranch', 'collectedBy:id,name', 'receivedBy:id,name',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['facts' => 'array', 'collected_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function sampleType(): BelongsTo
    {
        return $this->belongsTo(SampleType::class);
    }

    public function collectionBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'collection_branch_id');
    }

    public function receivingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'receiving_branch_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LaboratorySampleEvent::class)->orderBy('id');
    }

    public function associations(): HasMany
    {
        return $this->hasMany(LaboratorySampleAssociation::class)->orderBy('id');
    }
}
