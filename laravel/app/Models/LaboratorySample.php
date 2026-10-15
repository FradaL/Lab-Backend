<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'identifier', 'sample_type_id', 'provenance', 'status', 'collected_at', 'collection_branch_id', 'collected_by', 'received_at', 'receiving_branch_id', 'received_by', 'facts'])]
class LaboratorySample extends Model
{
    use BelongsToLaboratory;
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['facts' => 'array', 'collected_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
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
