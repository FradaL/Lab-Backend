<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\LaboratoryAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'laboratory_id',
    'code',
    'name',
    'description',
    'status',
])]
class LaboratoryArea extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<LaboratoryAreaFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';
}
