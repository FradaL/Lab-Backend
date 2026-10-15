<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ReportDeliveryException extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function authorization(): HasOne
    {
        return $this->hasOne(ReportDeliveryExceptionAuthorization::class, 'exception_id');
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'policy_revision' => 'integer', 'evaluation_snapshot' => 'array', 'requested_at' => 'immutable_datetime'];
    }
}
