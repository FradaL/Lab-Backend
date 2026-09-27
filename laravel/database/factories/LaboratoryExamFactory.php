<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\SampleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryExam>
 */
class LaboratoryExamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'laboratory_area_id' => fn (array $attributes): int => LaboratoryArea::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'sample_type_id' => fn (array $attributes): int => SampleType::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'code' => fake()->unique()->bothify('EXAM-########-????'),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'turnaround_time_minutes' => fake()->optional()->numberBetween(0, 10080),
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
    }
}
