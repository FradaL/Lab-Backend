<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceListExam> */
class PriceListExamFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'price_list_id' => fn (array $attributes): int => PriceList::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'laboratory_exam_id' => fn (array $attributes): int => LaboratoryExam::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'price' => fake()->randomElement(['0.00', '12.34', '40.00', '75.00', '999.99']),
            'status' => PriceListExam::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PriceListExam::STATUS_INACTIVE,
        ]);
    }
}
