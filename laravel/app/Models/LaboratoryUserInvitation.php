<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'laboratory_id',
    'email',
    'name',
    'role',
    'token_hash',
    'invited_by',
    'expires_at',
    'accepted_at',
    'accepted_by',
    'revoked_at',
])]
class LaboratoryUserInvitation extends Model
{
    use BelongsToLaboratory;

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
