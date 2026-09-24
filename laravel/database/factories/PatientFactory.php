<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'first_names' => fake()->firstName(),
            'last_names' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-100 years', 'now'),
            'gender' => fake()->randomElement(['female', 'male']),
            'phone' => fake()->numerify('+502 2### ####'),
            'mobile' => fake()->numerify('+502 5### ####'),
            'email' => fake()->safeEmail(),
            'address' => fake()->address(),
            'affiliation_number' => fake()->bothify('AFF-########'),
            'weight' => fake()->randomFloat(2, 1, 300),
            'height' => fake()->randomFloat(2, 30, 250),
            'status' => Patient::STATUS_ACTIVE,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
