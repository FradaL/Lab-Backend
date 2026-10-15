<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryOrderExam>
 */
class LaboratoryOrderExamFactory extends Factory
{
    /** Fixture for the order transition action after an explicit sample-free line start. */
    public function processingStarted(): static
    {
        return $this->afterCreating(function (LaboratoryOrderExam $line): void {
            $line->update(['clinical_activity_started_at' => now(), 'processing_started_at' => now(), 'processing_started_by' => User::factory()->create()->id]);
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'laboratory_order_id' => fn (array $attributes): int => LaboratoryOrder::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'laboratory_exam_id' => fn (array $attributes): int => LaboratoryExam::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'price_list_id' => fn (array $attributes): int => LaboratoryOrder::query()
                ->findOrFail($attributes['laboratory_order_id'])
                ->price_list_id,
            'unit_price' => fake()->randomElement(['0.00', '12.34', '50.00', '999.99']),
            'clinical_version_id' => fn (array $attributes): int => LaboratoryExamFactory::publishedVersion(
                LaboratoryExam::query()->findOrFail($attributes['laboratory_exam_id']),
            ),
            'clinical_configuration_source' => 'published_version',
            'exam_code' => fn (array $attributes): string => LaboratoryExam::query()
                ->findOrFail($attributes['laboratory_exam_id'])
                ->code,
            'exam_name' => fn (array $attributes): string => LaboratoryExam::query()
                ->findOrFail($attributes['laboratory_exam_id'])
                ->name,
            'price_list_name' => fn (array $attributes): string => PriceList::query()
                ->findOrFail($attributes['price_list_id'])
                ->name,
        ];
    }
}
