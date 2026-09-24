<?php

namespace App\Models\Concerns;

use App\Models\Laboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToLaboratory
{
    /**
     * @return BelongsTo<Laboratory, $this>
     */
    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForLaboratory(Builder $query, Laboratory $laboratory): Builder
    {
        return $query->where(
            $query->qualifyColumn('laboratory_id'),
            $laboratory->getKey(),
        );
    }
}
