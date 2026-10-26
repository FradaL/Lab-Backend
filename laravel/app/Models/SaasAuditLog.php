<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['operation_id', 'actor_type', 'actor_id', 'actor_label', 'affected_user_id', 'capability_code', 'action', 'source', 'result', 'justification', 'old_values', 'new_values', 'global_reason_id', 'global_reason_version_id', 'previous_global_reason_version_id', 'global_impact_declaration_id'])]
class SaasAuditLog extends Model
{
    public $timestamps = false;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function affectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affected_user_id');
    }

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('SaaS audit history is append-only.'));
        static::deleting(fn () => throw new LogicException('SaaS audit history is append-only.'));
    }
}
