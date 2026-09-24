<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Laboratory;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_laboratory_cannot_have_two_active_subscriptions(): void
    {
        $laboratory = Laboratory::factory()->create();
        Subscription::factory()->for($laboratory)->create();

        $this->expectException(QueryException::class);

        Subscription::factory()->for($laboratory)->create();
    }

    public function test_laboratory_can_have_multiple_inactive_subscriptions_and_one_active_subscription(): void
    {
        $laboratory = Laboratory::factory()->create();

        Subscription::factory()->count(2)->for($laboratory)->create([
            'status' => Subscription::STATUS_INACTIVE,
        ]);
        Subscription::factory()->for($laboratory)->create([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertCount(3, $laboratory->subscriptions);
        $this->assertSame(1, $laboratory->subscriptions()->active()->count());
    }

    public function test_different_laboratories_can_each_have_an_active_subscription(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();

        Subscription::factory()->for($laboratoryA)->create();
        Subscription::factory()->for($laboratoryB)->create();

        $this->assertSame(2, Subscription::query()->active()->count());
    }

    public function test_inactive_demo_history_can_coexist_with_a_new_active_paid_subscription(): void
    {
        $laboratory = Laboratory::factory()->create();
        $demoPlan = Plan::factory()->create(['name' => 'Demo']);
        $paidPlan = Plan::factory()->create(['name' => 'Paid']);

        $demo = Subscription::factory()->for($laboratory)->for($demoPlan)->create([
            'status' => Subscription::STATUS_INACTIVE,
            'starts_at' => now()->subMonths(2),
            'trial_ends_at' => now()->subMonth(),
        ]);
        $paid = Subscription::factory()->for($laboratory)->for($paidPlan)->create([
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'trial_ends_at' => null,
        ]);

        $this->assertDatabaseHas('subscriptions', ['id' => $demo->id]);
        $this->assertDatabaseHas('subscriptions', ['id' => $paid->id]);
        $this->assertCount(2, $laboratory->subscriptions);
    }
}
