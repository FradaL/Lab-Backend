<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['laboratory_id', 'laboratory_order_id', 'laboratory_sample_id', 'event', 'actor_id', 'occurred_at', 'recorded_at', 'context'])]
class LaboratorySampleEvent extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
