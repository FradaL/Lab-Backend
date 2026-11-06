<?php

namespace Database\Factories;

use App\Cash\OrderFinancialState;
use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\ReportDeliveryPolicy;
use App\Models\User;
use App\ReportDelivery\OrderDeliveryPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @extends Factory<LaboratoryOrder>
 */
class LaboratoryOrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'branch_id' => fn (array $attributes): int => Branch::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'patient_id' => fn (array $attributes): int => Patient::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'doctor_id' => fn (array $attributes): int => Doctor::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'commercial_client_id' => fn (array $attributes): int => CommercialClient::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'commercial_client_name' => fn (array $attributes): ?string => $attributes['commercial_client_id'] === null
                ? null
                : CommercialClient::query()->findOrFail($attributes['commercial_client_id'])->name,
            'commercial_client_type' => fn (array $attributes): ?string => $attributes['commercial_client_id'] === null
                ? null
                : CommercialClient::query()->findOrFail($attributes['commercial_client_id'])->type,
            'price_list_id' => fn (array $attributes): int => PriceList::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'price_list_name' => fn (array $attributes): string => PriceList::query()
                ->findOrFail($attributes['price_list_id'])
                ->name,
            'code' => strtoupper(fake()->unique()->bothify('ORD-########')),
            'ordered_at' => fake()->dateTimeBetween('-1 year'),
            'status' => LaboratoryOrder::STATUS_PENDING,
            'notes' => fake()->optional()->sentence(),
            'subtotal' => '0.00',
            'discount' => '0.00',
            'discount_type' => null,
            'discount_value' => null,
            'taxes' => '0.00',
            'total' => '0.00',
            'currency' => fn (array $attributes): string => PriceList::query()
                ->findOrFail($attributes['price_list_id'])
                ->currency,
            'created_by' => User::factory(),
            ...(Schema::hasColumn('laboratory_orders', 'delivery_policy_snapshot') ? [
                'delivery_policy_snapshot' => function (array $attributes): array {
                    $lab = Laboratory::findOrFail($attributes['laboratory_id']);
                    if (! ReportDeliveryPolicy::forLaboratory($lab)->exists()) {
                        ReportDeliveryPolicy::factory()->for($lab)->create();
                    }

                    return DB::transaction(fn () => app(OrderDeliveryPolicy::class)->capture($lab));
                },
            ] : []),
        ];
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        return DB::transaction(fn () => parent::create($attributes, $parent));
    }

    public function configure(): static
    {
        return $this->afterCreating(function (LaboratoryOrder $order): void {
            if (Schema::hasTable('laboratory_order_financial_states') && $order->delivery_policy_snapshot !== null) {
                if (! DB::table('laboratory_user')->where('laboratory_id', $order->laboratory_id)->where('user_id', $order->created_by)->exists()) {
                    User::findOrFail($order->created_by)->laboratories()->attach($order->laboratory_id, ['is_active' => true]);
                }
                DB::transaction(fn () => app(OrderFinancialState::class)->initialize($order));
            }
        });
    }

    public function withoutDoctor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'doctor_id' => null,
        ]);
    }

    public function particular(): static
    {
        return $this->state(fn (array $attributes): array => [
            'commercial_client_id' => null,
            'commercial_client_name' => null,
            'commercial_client_type' => null,
        ]);
    }
}
