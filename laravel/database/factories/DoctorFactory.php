<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Laboratory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
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
            'specialty' => fake()->optional()->randomElement([
                'Medicina General',
                'Pediatría',
                'Cardiología',
                'Ginecología',
            ]),
            'phone' => fake()->optional()->numerify('+502 5### ####'),
            'email' => fake()->optional()->safeEmail(),
            'license_number' => fake()->optional()->bothify('MED-######'),
            'status' => Doctor::STATUS_ACTIVE,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
