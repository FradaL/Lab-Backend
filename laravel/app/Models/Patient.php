<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'laboratory_id',
    'first_names',
    'last_names',
    'birth_date',
    'gender',
    'phone',
    'mobile',
    'email',
    'address',
    'affiliation_number',
    'weight',
    'height',
    'status',
    'notes',
])]
class Patient extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'weight' => 'decimal:2',
            'height' => 'decimal:2',
        ];
    }
}
