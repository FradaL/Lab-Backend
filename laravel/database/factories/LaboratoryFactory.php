<?php

namespace Database\Factories;

use App\Models\Laboratory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Laboratory>
 */
class LaboratoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' S.A.',
            'nit' => fake()->unique()->numerify('############'),
            'phone' => fake()->numerify('+502########'),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->streetAddress(),
            'timezone' => 'America/Guatemala',
            'currency' => 'GTQ',
            'is_active' => true,
        ];
    }
}
