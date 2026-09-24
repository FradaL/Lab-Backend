<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;

class SaasFoundationSeeder extends Seeder
{
    /**
     * Seed the development SaaS foundation data.
     */
    public function run(): void
    {
        $plan = Plan::query()->updateOrCreate(
            ['code' => 'DEMO'],
            [
                'name' => 'Demo',
                'price' => 0,
                'billing_period' => 'monthly',
                'status' => 'active',
            ],
        );

        $laboratory = Laboratory::query()->updateOrCreate(
            ['nit' => '000000000001'],
            [
                'name' => 'Laboratorio Demo Donqer',
                'legal_name' => 'Laboratorio Demo Donqer, S.A.',
                'phone' => '+50222000000',
                'email' => 'demo@donqerlab.test',
                'address' => 'Ciudad de Guatemala',
                'timezone' => 'America/Guatemala',
                'currency' => 'GTQ',
                'is_active' => true,
            ],
        );

        Branch::query()->updateOrCreate(
            [
                'laboratory_id' => $laboratory->id,
                'code' => 'MAIN',
            ],
            [
                'name' => 'Sucursal Principal',
                'phone' => '+50222000000',
                'email' => 'principal@donqerlab.test',
                'address' => 'Ciudad de Guatemala',
                'is_main' => true,
                'status' => 'active',
            ],
        );

        Subscription::query()->updateOrCreate(
            [
                'plan_id' => $plan->id,
                'laboratory_id' => $laboratory->id,
                'starts_at' => '2026-01-01 00:00:00',
            ],
            [
                'status' => Subscription::STATUS_ACTIVE,
                'ends_at' => null,
                'trial_ends_at' => null,
            ],
        );

        User::query()
            ->whereIn('email', ['admin@donqerlab.test', 'reception@donqerlab.test'])
            ->each(function (User $user) use ($laboratory): void {
                LaboratoryUser::query()->updateOrCreate(
                    [
                        'laboratory_id' => $laboratory->id,
                        'user_id' => $user->id,
                    ],
                    ['is_active' => true],
                );
            });
    }
}
