<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ReportDeliveryExceptionAuthorization extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ReportDeliveryException::class, 'exception_id');
    }

    public function unusedAuthorizedException(): HasOne
    {
        return $this->hasOne(ReportDeliveryException::class, 'id', 'exception_id')->where('status', 'authorized');
    }

    protected function casts(): array
    {
        return ['policy_revision' => 'integer', 'evaluation_snapshot' => 'array', 'authorized_at' => 'immutable_datetime'];
    }
}
