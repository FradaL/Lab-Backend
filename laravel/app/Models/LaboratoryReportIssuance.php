<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryReportIssuance extends Model
{
    use BelongsToLaboratory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['private_path', 'verification_hash', 'idempotency_hash', 'request_hash', 'clinical_snapshot', 'pending_snapshot', 'identity_snapshot', 'presentation_snapshot'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function deliveryExceptions(): HasMany
    {
        return $this->hasMany(ReportDeliveryException::class, 'report_id');
    }

    public function deliveryPreparations(): HasMany
    {
        return $this->hasMany(ReportDeliveryPreparation::class, 'report_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class, 'report_id');
    }

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'identity_snapshot' => 'array', 'clinical_snapshot' => 'array', 'pending_snapshot' => 'array', 'presentation_snapshot' => 'array'];
    }
}
