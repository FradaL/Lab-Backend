<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\SampleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SampleType>
 */
class SampleTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'name' => fake()->unique()->bothify('Sample Type ########-????'),
            'status' => SampleType::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SampleType::STATUS_INACTIVE,
        ]);
    }
}
