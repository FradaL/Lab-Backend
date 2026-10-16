<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * @return BelongsToMany<Laboratory, $this>
     */
    public function laboratories(): BelongsToMany
    {
        return $this->belongsToMany(Laboratory::class)
            ->using(LaboratoryUser::class)
            ->withPivot(['id', 'is_active', 'is_default'])
            ->withTimestamps();
    }

    /** @return HasMany<LaboratoryOrder, $this> */
    public function createdLaboratoryOrders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class, 'created_by');
    }

    /** @return HasMany<ValidationProfessional, $this> */
    public function validationProfessionals(): HasMany
    {
        return $this->hasMany(ValidationProfessional::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'membership_is_active' => 'boolean',
            'membership_is_default' => 'boolean',
            'membership_created_at' => 'datetime',
            'membership_updated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
