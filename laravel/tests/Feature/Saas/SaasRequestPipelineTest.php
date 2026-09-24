<?php

namespace Tests\Feature\Saas;

use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SaasRequestPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'));

        Route::middleware('saas')
            ->get('/api/v1/testing/saas-pipeline', function (CurrentLaboratory $currentLaboratory) {
                return response()->json([
                    'laboratory_id' => $currentLaboratory->id(),
                ]);
            });
    }

    public function test_complete_pipeline_allows_a_valid_tenant_request(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->createCurrentSubscription($laboratory);

        $this->tenantRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson(['laboratory_id' => $laboratory->id]);
    }

    public function test_authentication_runs_before_tenant_and_subscription_resolution(): void
    {
        $this->getJson('/api/v1/testing/saas-pipeline')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_missing_header_stops_at_tenant_context(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/testing/saas-pipeline')
            ->assertBadRequest()
            ->assertExactJson([
                'message' => 'Debe especificar el laboratorio.',
                'code' => 'LABORATORY_CONTEXT_REQUIRED',
            ]);
    }

    public function test_missing_laboratory_is_rejected_before_subscription_resolution(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->withHeader('X-Laboratory-ID', '999999')
            ->getJson('/api/v1/testing/saas-pipeline')
            ->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');
    }

    public function test_inactive_laboratory_is_rejected_before_subscription_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');
    }

    public function test_missing_membership_is_rejected_before_subscription_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_inactive_membership_is_rejected_before_subscription_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_valid_tenant_without_subscription_reaches_subscription_layer(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_valid_tenant_with_future_subscription_reaches_subscription_layer(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->addSecond(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_NOT_STARTED');
    }

    public function test_valid_tenant_with_expired_subscription_reaches_subscription_layer(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subSecond(),
            'trial_ends_at' => null,
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_valid_tenant_with_expired_trial_reaches_subscription_layer(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => now()->subSecond(),
        ]);

        $this->tenantRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'TRIAL_EXPIRED');
    }

    public function test_subscription_in_another_tenant_never_bypasses_membership(): void
    {
        $userA = User::factory()->create();
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $userA->laboratories()->attach($laboratoryA, ['is_active' => true]);
        $this->createCurrentSubscription($laboratoryA);
        $this->createCurrentSubscription($laboratoryB);

        $this->tenantRequest($userA, $laboratoryB)
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'No tiene acceso al laboratorio solicitado.',
                'code' => 'LABORATORY_ACCESS_DENIED',
            ]);
    }

    /**
     * @return array{User, Laboratory}
     */
    private function activeTenant(): array
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        return [$user, $laboratory];
    }

    private function createCurrentSubscription(Laboratory $laboratory): Subscription
    {
        return Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    private function tenantRequest(User $user, Laboratory $laboratory): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/testing/saas-pipeline');
    }
}
