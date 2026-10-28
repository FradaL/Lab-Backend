<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasCapability extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function assignments(): HasMany
    {
        return $this->hasMany(SaasCapabilityAssignment::class, 'capability_code', 'code');
    }
}
