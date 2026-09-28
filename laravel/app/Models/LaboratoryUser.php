<?php

namespace App\Models;

use Database\Factories\LaboratoryUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['laboratory_id', 'user_id', 'is_active', 'is_default'])]
class LaboratoryUser extends Pivot
{
    /** @use HasFactory<LaboratoryUserFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'laboratory_user';

    /**
     * @return BelongsTo<Laboratory, $this>
     */
    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
