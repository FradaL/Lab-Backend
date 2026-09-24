<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EnsureActiveSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'));

        Route::middleware(['auth:sanctum', 'laboratory.context', 'subscription.active'])
            ->get('/api/v1/testing/subscription-access', function (CurrentLaboratory $currentLaboratory) {
                return response()->json([
                    'laboratory_id' => $currentLaboratory->id(),
                ]);
            });
    }

    public function test_laboratory_without_subscriptions_is_denied(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_laboratory_with_only_inactive_subscriptions_is_denied(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'status' => Subscription::STATUS_INACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_current_normal_subscription_allows_access(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('laboratory_id', $laboratory->id);
    }

    public function test_subscription_allows_access_exactly_at_its_start(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        $this->tenantRequest($user, $laboratory)->assertOk();
    }

    public function test_normal_subscription_allows_access_exactly_at_its_end(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now(),
            'trial_ends_at' => null,
        ]);

        $this->tenantRequest($user, $laboratory)->assertOk();
    }

    public function test_trial_subscription_allows_access_exactly_at_its_trial_end(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'trial_ends_at' => now(),
        ]);

        $this->tenantRequest($user, $laboratory)->assertOk();
    }

    public function test_future_normal_subscription_is_denied(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_NOT_STARTED');
    }

    public function test_expired_normal_subscription_is_denied_while_still_marked_active(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        $subscription = Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subSecond(),
            'trial_ends_at' => null,
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    public function test_current_trial_subscription_allows_access(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subHour(),
            'trial_ends_at' => now()->addDay(),
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertOk();
    }

    public function test_expired_trial_is_denied_while_still_marked_active(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        $subscription = Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => now()->subSecond(),
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'TRIAL_EXPIRED');

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    public function test_inactive_subscription_never_allows_access_with_valid_dates(): void
    {
        [$user, $laboratory] = $this->userWithLaboratory();
        Subscription::factory()->for($laboratory)->create([
            'status' => Subscription::STATUS_INACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_middleware_uses_subscription_from_the_current_laboratory(): void
    {
        $user = User::factory()->create();
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $user->laboratories()->attach([$laboratoryA->id, $laboratoryB->id], ['is_active' => true]);

        Subscription::factory()->for($laboratoryA)->create(['starts_at' => now()->subDay()]);
        Subscription::factory()->for($laboratoryB)->create(['starts_at' => now()->subDay()]);

        $this->tenantRequest($user, $laboratoryB)
            ->assertOk()
            ->assertJsonPath('laboratory_id', $laboratoryB->id);
    }

    public function test_active_subscription_from_laboratory_a_does_not_grant_access_to_laboratory_b(): void
    {
        $user = User::factory()->create();
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $user->laboratories()->attach([$laboratoryA->id, $laboratoryB->id], ['is_active' => true]);

        Subscription::factory()->for($laboratoryA)->create(['starts_at' => now()->subDay()]);

        $this->tenantRequest($user, $laboratoryB)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_non_subscription_routes_remain_available_without_a_subscription(): void
    {
        [$user] = $this->userWithLaboratory();

        $this->getJson('/api/v1/health')->assertOk();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @return array{User, Laboratory}
     */
    private function userWithLaboratory(): array
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        return [$user, $laboratory];
    }

    private function tenantRequest(User $user, Laboratory $laboratory): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/testing/subscription-access');
    }
}
