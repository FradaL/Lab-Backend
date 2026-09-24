<?php

namespace Tests\Feature\Api\V1\LaboratoryAreas;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryAreaShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_detail_returns_the_exact_contract_for_an_active_area(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => 'Pruebas hematológicas.',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);

        $this->areaRequest($user, $laboratory, $area->id)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $area->id,
                    'code' => 'HEM',
                    'name' => 'Hematología',
                    'description' => 'Pruebas hematológicas.',
                    'status' => LaboratoryArea::STATUS_ACTIVE,
                    'created_at' => '2026-09-23T12:00:00.000000Z',
                    'updated_at' => '2026-09-23T12:00:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.exam_count')
            ->assertJsonMissingPath('data.has_exams');
    }

    public function test_inactive_area_and_null_description_are_visible(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'description' => null,
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);

        $this->areaRequest($user, $laboratory, $area->id)
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_INACTIVE);
    }

    public function test_cross_tenant_and_nonexistent_areas_share_the_same_neutral_404(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $areaB = LaboratoryArea::factory()->for($labB)->create();

        $crossTenant = $this->areaRequest($user, $labA, $areaB->id)
            ->assertNotFound();
        $missing = $this->areaRequest($user, $labA, 999999999)
            ->assertNotFound();

        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());

        foreach ([$crossTenant, $missing] as $response) {
            $json = json_encode($response->json(), JSON_THROW_ON_ERROR);

            foreach (['LaboratoryArea', '999999999', 'laboratory_id', 'SQLSTATE', '/var/www', 'trace'] as $leak) {
                $this->assertStringNotContainsString($leak, $json);
            }
        }
    }

    public function test_tenant_isolation_is_symmetric_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $areaA = LaboratoryArea::factory()->for($labA)->create();
        $areaB = LaboratoryArea::factory()->for($labB)->create();

        $this->areaRequest($user, $labA, $areaA->id)->assertOk()->assertJsonPath('data.id', $areaA->id);
        $this->areaRequest($user, $labB, $areaB->id)->assertOk()->assertJsonPath('data.id', $areaB->id);
        $this->areaRequest($user, $labA, $areaA->id)->assertOk()->assertJsonPath('data.id', $areaA->id);
        $this->areaRequest($user, $labA, $areaB->id)->assertNotFound();
        $this->areaRequest($user, $labB, $areaA->id)->assertNotFound();
    }

    #[DataProvider('invalidIdProvider')]
    public function test_invalid_route_identifiers_return_not_found(string $area): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory, $area)->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'letters' => ['abc'],
            'word' => ['test'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    public function test_guest_is_rejected_without_leaking_area_data(): void
    {
        $area = LaboratoryArea::factory()->create([
            'code' => 'SECRET-CODE',
        ]);

        $response = $this->getJson("/api/v1/laboratory-areas/{$area->id}")
            ->assertUnauthorized();

        $this->assertPipelineResponseDoesNotLeak($response, $area);
    }

    public function test_missing_laboratory_context_is_rejected_without_leaking_area_data(): void
    {
        $area = LaboratoryArea::factory()->create([
            'code' => 'SECRET-CODE',
        ]);

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->getJson("/api/v1/laboratory-areas/{$area->id}")
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertPipelineResponseDoesNotLeak($response, $area);
    }

    public function test_inactive_membership_is_rejected_without_leaking_area_data(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'SECRET-CODE',
        ]);

        $response = $this->areaRequest($user, $laboratory, $area->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertPipelineResponseDoesNotLeak($response, $area);
    }

    public function test_missing_subscription_is_rejected_without_leaking_area_data(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'SECRET-CODE',
        ]);

        $response = $this->areaRequest($user, $laboratory, $area->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertPipelineResponseDoesNotLeak($response, $area);
    }

    public function test_create_then_detail_returns_the_persisted_area(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $created = $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-areas', [
                'code' => 'MIC',
                'name' => 'Microbiología',
                'description' => 'Cultivos.',
            ])
            ->assertCreated();

        $this->areaRequest($user, $laboratory, $created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.code', 'MIC')
            ->assertJsonPath('data.name', 'Microbiología')
            ->assertJsonPath('data.description', 'Cultivos.')
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_ACTIVE);
    }

    public function test_detail_is_read_only_and_does_not_change_timestamps(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $beforeUpdatedAt = $area->updated_at?->toISOString();
        $counts = [
            'laboratory_areas' => LaboratoryArea::query()->count(),
            'laboratories' => Laboratory::query()->count(),
            'subscriptions' => Subscription::query()->count(),
            'users' => User::query()->count(),
        ];

        $this->travel(5)->minutes();
        $this->areaRequest($user, $laboratory, $area->id)->assertOk();

        $this->assertSame($beforeUpdatedAt, $area->fresh()->updated_at?->toISOString());
        $this->assertSame($counts['laboratory_areas'], LaboratoryArea::query()->count());
        $this->assertSame($counts['laboratories'], Laboratory::query()->count());
        $this->assertSame($counts['subscriptions'], Subscription::query()->count());
        $this->assertSame($counts['users'], User::query()->count());
    }

    /**
     * @return array{User, Laboratory}
     */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

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

    private function areaRequest(
        User $user,
        Laboratory $laboratory,
        int|string $area,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-areas/{$area}");
    }

    private function assertPipelineResponseDoesNotLeak(
        TestResponse $response,
        LaboratoryArea $area,
    ): void {
        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($area->code, $json);
        $this->assertStringNotContainsString($area->name, $json);
        $this->assertStringNotContainsString('laboratory_id', $json);
    }
}
