<?php

namespace Database\Factories;

use App\Models\Laboratory;
use App\Models\ReportDeliveryPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** @extends Factory<ReportDeliveryPolicy> */
class ReportDeliveryPolicyFactory extends Factory
{
    public function create($attributes = [], ?Model $parent = null)
    {
        return DB::transaction(fn () => parent::create($attributes, $parent));
    }

    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'revision' => 1,
            'mode' => 'full_payment_required',
            'updated_by' => function (array $attributes): int {
                $lab = Laboratory::findOrFail($attributes['laboratory_id']);
                $actor = $lab->users()->wherePivot('is_active', true)->first() ?? User::factory()->create();
                $lab->users()->syncWithoutDetaching([$actor->id => ['is_active' => true]]);

                return $actor->id;
            },
            'updated_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ReportDeliveryPolicy $policy): void {
            DB::table('report_delivery_policy_changes')->insert([
                'laboratory_id' => $policy->laboratory_id,
                'revision' => $policy->revision,
                'previous_revision' => $policy->revision - 1,
                'previous_mode' => 'full_payment_required',
                'new_mode' => $policy->mode,
                'reason' => 'FICTICIO — Selección explícita del dataset de prueba.',
                'changed_by' => $policy->updated_by,
                'changed_at' => $policy->updated_at->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }
}
