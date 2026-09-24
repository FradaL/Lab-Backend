<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Laboratory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
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
            'code' => fake()->unique()->bothify('BR-###'),
            'name' => fake()->company().' Branch',
            'phone' => fake()->numerify('+502########'),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->streetAddress(),
            'is_main' => false,
            'status' => 'active',
        ];
    }
}
