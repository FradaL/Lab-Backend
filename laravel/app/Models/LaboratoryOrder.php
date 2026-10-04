<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Database\Factories\LaboratoryOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'laboratory_id',
    'branch_id',
    'patient_id',
    'doctor_id',
    'commercial_client_id',
    'price_list_id',
    'code',
    'ordered_at',
    'status',
    'notes',
    'subtotal',
    'discount',
    'taxes',
    'total',
    'currency',
    'created_by',
])]
class LaboratoryOrder extends Model
{
    use BelongsToLaboratory;

    /** @use HasFactory<LaboratoryOrderFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROCESS = 'in_process';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROCESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public function canTransitionTo(string $status): bool
    {
        if ($this->status === $status) {
            return true;
        }

        return in_array($status, match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_IN_PROCESS, self::STATUS_CANCELLED],
            self::STATUS_IN_PROCESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED, self::STATUS_CANCELLED => [],
            default => [],
        }, true);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<CommercialClient, $this> */
    public function commercialClient(): BelongsTo
    {
        return $this->belongsTo(CommercialClient::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'taxes' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
