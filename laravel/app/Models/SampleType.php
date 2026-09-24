<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\SampleTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'laboratory_id',
    'name',
    'status',
])]
class SampleType extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<SampleTypeFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';
}
