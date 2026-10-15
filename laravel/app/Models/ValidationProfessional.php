<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'user_id', 'professional_name', 'profession', 'registration_number', 'status', 'authorized_at', 'authorized_by', 'revoked_at', 'revoked_by', 'revocation_reason'])]
class ValidationProfessional extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['authorized_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
