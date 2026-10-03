<?php

namespace Database\Factories;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommercialClientPriceList>
 */
class CommercialClientPriceListFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'commercial_client_id' => fn (array $attributes): int => CommercialClient::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'price_list_id' => fn (array $attributes): int => PriceList::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
            'status' => CommercialClientPriceList::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
    }
}
