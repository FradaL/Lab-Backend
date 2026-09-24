<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'plan_id',
    'laboratory_id',
    'status',
    'starts_at',
    'ends_at',
    'trial_ends_at',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public function isTrial(): bool
    {
        return $this->trial_ends_at !== null;
    }

    public function hasStarted(?CarbonInterface $at = null): bool
    {
        $at ??= Carbon::now();

        return $this->starts_at->lessThanOrEqualTo($at);
    }

    public function hasExpired(?CarbonInterface $at = null): bool
    {
        $at ??= Carbon::now();
        $accessEndsAt = $this->isTrial() ? $this->trial_ends_at : $this->ends_at;

        return $accessEndsAt !== null && $accessEndsAt->lessThan($at);
    }

    public function allowsAccess(?CarbonInterface $at = null): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->hasStarted($at)
            && ! $this->hasExpired($at);
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeExpiredAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where('starts_at', '<=', $at)
            ->where(function (Builder $query) use ($at): void {
                $query
                    ->where(function (Builder $query) use ($at): void {
                        $query
                            ->whereNotNull('trial_ends_at')
                            ->where('trial_ends_at', '<', $at);
                    })
                    ->orWhere(function (Builder $query) use ($at): void {
                        $query
                            ->whereNull('trial_ends_at')
                            ->whereNotNull('ends_at')
                            ->where('ends_at', '<', $at);
                    });
            });
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Laboratory, $this>
     */
    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
        ];
    }
}
