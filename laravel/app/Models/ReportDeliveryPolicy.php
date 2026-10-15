<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Model;

final class ReportDeliveryPolicy extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
