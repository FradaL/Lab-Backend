<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryOrderExam>
 */
class LaboratoryOrderExamFactory extends Factory
{
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
