<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LaboratorySampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'laboratory_order_id' => fn (array $attributes) => LaboratoryOrder::factory()->create(['laboratory_id' => $attributes['laboratory_id']])->id,
            'sample_type_id' => fn (array $attributes) => SampleType::factory()->create(['laboratory_id' => $attributes['laboratory_id']])->id,
            'identifier' => (string) Str::ulid(), 'status' => 'collected', 'provenance' => 'internal',
            'collected_at' => now()->subMinutes(5), 'collected_by' => User::factory(),
            'collection_branch_id' => fn (array $attributes) => Branch::factory()->create(['laboratory_id' => $attributes['laboratory_id']])->id,
            'facts' => (object) [],
        ];
    }
}
