<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryUser>
 */
class LaboratoryUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'user_id' => User::factory(),
            'is_active' => true,
        ];
    }
}
