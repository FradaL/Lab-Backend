<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryArea>
 */
class LaboratoryAreaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'code' => fake()->unique()->bothify('AREA-########-????'),
            'name' => fake()->unique()->bothify('Laboratory Area ########-????'),
            'description' => fake()->optional()->sentence(),
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ];
    }
}
