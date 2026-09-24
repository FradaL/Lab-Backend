<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->bothify('??##'),
            'price' => fake()->randomFloat(2, 0, 999999),
            'billing_period' => fake()->randomElement(['monthly', 'annual']),
            'status' => 'active',
        ];
    }
}
