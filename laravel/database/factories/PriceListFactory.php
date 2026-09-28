<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'currency' => 'GTQ',
            'is_default' => false,
            'status' => PriceList::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PriceList::STATUS_INACTIVE,
        ]);
    }

    public function asDefault(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
