<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_only_expires_started_active_subscriptions_past_their_access_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'));

        $expiredTrial = Subscription::factory()->create([
            'starts_at' => now()->subMonth(),
            'trial_ends_at' => now()->subSecond(),
            'ends_at' => now()->addMonth(),
        ]);
        $expiredNormal = Subscription::factory()->create([
            'starts_at' => now()->subMonth(),
            'trial_ends_at' => null,
            'ends_at' => now()->subSecond(),
        ]);
        $currentTrial = Subscription::factory()->create([
            'starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDay(),
        ]);
        $currentNormal = Subscription::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $normalEndingNow = Subscription::factory()->create([
            'starts_at' => now()->subMonth(),
            'trial_ends_at' => null,
            'ends_at' => now(),
        ]);
        $trialEndingNow = Subscription::factory()->create([
            'starts_at' => now()->subMonth(),
            'trial_ends_at' => now(),
            'ends_at' => now()->subDay(),
        ]);
        $futureSubscription = Subscription::factory()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->subSecond(),
        ]);
        $inactiveSubscription = Subscription::factory()->create([
            'status' => Subscription::STATUS_INACTIVE,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subSecond(),
        ]);

        $this->artisan('subscriptions:expire')
            ->expectsOutput('Expired subscriptions: 2')
            ->assertSuccessful();

        $this->assertSame(Subscription::STATUS_INACTIVE, $expiredTrial->fresh()->status);
        $this->assertSame(Subscription::STATUS_INACTIVE, $expiredNormal->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $currentTrial->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $currentNormal->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $normalEndingNow->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $trialEndingNow->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $futureSubscription->fresh()->status);
        $this->assertSame(Subscription::STATUS_INACTIVE, $inactiveSubscription->fresh()->status);

        $this->artisan('subscriptions:expire')
            ->expectsOutput('Expired subscriptions: 0')
            ->assertSuccessful();
    }
}
