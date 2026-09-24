<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'laboratory_id',
    'first_names',
    'last_names',
    'specialty',
    'phone',
    'email',
    'license_number',
    'status',
    'notes',
])]
class Doctor extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<DoctorFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';
}
