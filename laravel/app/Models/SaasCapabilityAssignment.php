<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'capability_code', 'granted_by', 'granted_at', 'grant_reason', 'grant_audit_id', 'revoked_by', 'revoked_at', 'revocation_reason', 'revocation_audit_id', 'revision'])]
class SaasCapabilityAssignment extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(SaasCapability::class, 'capability_code', 'code');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'granted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
