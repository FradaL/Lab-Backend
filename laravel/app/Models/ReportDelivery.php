<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReportDelivery extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['idempotency_hash', 'request_hash'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(LaboratoryReportIssuance::class, 'report_id');
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(ReportDeliveryPreparation::class, 'preparation_id');
    }

    public function exceptionAuthorization(): BelongsTo
    {
        return $this->belongsTo(ReportDeliveryExceptionAuthorization::class, 'exception_authorization_id');
    }

    protected function casts(): array
    {
        return ['policy_revision' => 'integer', 'recipient_snapshot' => 'array', 'evaluation_snapshot' => 'array', 'delivered_at' => 'immutable_datetime'];
    }
}
