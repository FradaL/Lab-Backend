<?php

namespace App\Models;

use Database\Factories\LaboratoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'legal_name',
    'nit',
    'phone',
    'email',
    'address',
    'timezone',
    'currency',
    'is_active',
])]
class Laboratory extends Model
{
    /** @use HasFactory<LaboratoryFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(LaboratoryUser::class)
            ->withPivot(['id', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Patient, $this>
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /**
     * @return HasMany<Doctor, $this>
     */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    /**
     * @return HasMany<LaboratoryArea, $this>
     */
    public function laboratoryAreas(): HasMany
    {
        return $this->hasMany(LaboratoryArea::class);
    }

    /**
     * @return HasMany<SampleType, $this>
     */
    public function sampleTypes(): HasMany
    {
        return $this->hasMany(SampleType::class);
    }

    /**
     * @return HasMany<LaboratoryExam, $this>
     */
    public function laboratoryExams(): HasMany
    {
        return $this->hasMany(LaboratoryExam::class);
    }

    /**
     * @return HasMany<PriceList, $this>
     */
    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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
        ];
    }
}
