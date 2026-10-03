<?php

namespace Database\Factories;

use App\Models\CommercialClient;
use App\Models\Laboratory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommercialClient>
 */
class CommercialClientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'name' => fake()->unique()->company(),
            'type' => fake()->randomElement([
                CommercialClient::TYPE_INSURANCE,
                CommercialClient::TYPE_COMPANY,
                CommercialClient::TYPE_AGREEMENT,
                CommercialClient::TYPE_OTHER,
            ]),
            'tax_id' => fake()->optional()->bothify('TAX-########'),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->companyEmail(),
            'address' => fake()->optional()->address(),
            'notes' => fake()->optional()->sentence(),
            'status' => CommercialClient::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);
    }

    public function insurance(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CommercialClient::TYPE_INSURANCE,
        ]);
    }

    public function company(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CommercialClient::TYPE_COMPANY,
        ]);
    }

    public function agreement(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CommercialClient::TYPE_AGREEMENT,
        ]);
    }

    public function other(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CommercialClient::TYPE_OTHER,
        ]);
    }
}
