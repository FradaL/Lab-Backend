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

class LaboratoryAreaStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    #[DataProvider('statusTransitionProvider')]
    public function test_status_transitions_are_idempotent_and_return_exact_detail(
        string $initialStatus,
        string $requestedStatus,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory, ['status' => $initialStatus]);
        $originalUpdatedAt = $area->updated_at?->toISOString();
        $this->travel(1)->minute();

        $response = $this->statusRequest($user, $laboratory, $area->id, [
            'status' => $requestedStatus,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', $requestedStatus)
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams');

        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name', 'description', 'status', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );

        $area->refresh();
        $this->assertSame($requestedStatus, $area->status);

        if ($initialStatus === $requestedStatus) {
            $this->assertSame($originalUpdatedAt, $area->updated_at?->toISOString());
        } else {
            $this->assertSame('2026-09-23T12:01:00.000000Z', $area->updated_at?->toISOString());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function statusTransitionProvider(): array
    {
        return [
            'active to inactive' => [LaboratoryArea::STATUS_ACTIVE, LaboratoryArea::STATUS_INACTIVE],
            'inactive to active' => [LaboratoryArea::STATUS_INACTIVE, LaboratoryArea::STATUS_ACTIVE],
            'active to active' => [LaboratoryArea::STATUS_ACTIVE, LaboratoryArea::STATUS_ACTIVE],
            'inactive to inactive' => [LaboratoryArea::STATUS_INACTIVE, LaboratoryArea::STATUS_INACTIVE],
        ];
    }

    public function test_only_status_and_natural_updated_at_change(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $original = $area->getRawOriginal();
        $this->travel(1)->minute();

        $this->statusRequest($user, $laboratory, $area->id, [
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ])->assertOk();

        $current = $area->refresh()->getRawOriginal();

        foreach (array_keys($original) as $field) {
            if (in_array($field, ['status', 'updated_at'], true)) {
                continue;
            }

            $this->assertEquals($original[$field], $current[$field], "The {$field} field changed.");
        }

        $this->assertSame(LaboratoryArea::STATUS_INACTIVE, $current['status']);
        $this->assertSame($laboratory->id, $current['laboratory_id']);
        $this->assertNotSame($original['updated_at'], $current['updated_at']);
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_payloads_are_rejected_without_changes(array $payload): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $before = $area->getRawOriginal();

        $this->statusRequest($user, $laboratory, $area->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertRawStateUnchanged($before, $area);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'missing' => [[]],
            'null' => [['status' => null]],
            'empty' => [['status' => '']],
            'uppercase active' => [['status' => 'ACTIVE']],
            'uppercase inactive' => [['status' => 'INACTIVE']],
            'title case active' => [['status' => 'Active']],
            'mixed case' => [['status' => 'aCtIvE']],
            'leading whitespace' => [['status' => ' active']],
            'trailing whitespace' => [['status' => 'active ']],
            'surrounding active whitespace' => [['status' => ' active ']],
            'surrounding inactive whitespace' => [['status' => ' inactive ']],
            'unknown value' => [['status' => 'pending']],
            'numeric' => [['status' => 1]],
            'boolean' => [['status' => true]],
            'array' => [['status' => ['active']]],
        ];
    }

    #[DataProvider('unexpectedFieldProvider')]
    public function test_additional_fields_reject_the_whole_payload_atomically(
        string $field,
        mixed $value,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $before = $area->getRawOriginal();

        if ($field === 'laboratory_id') {
            $value = Laboratory::factory()->create()->id;
        }

        $this->statusRequest($user, $laboratory, $area->id, [
            'status' => LaboratoryArea::STATUS_INACTIVE,
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertRawStateUnchanged($before, $area);
    }

    /** @return array<string, array{string, mixed}> */
    public static function unexpectedFieldProvider(): array
    {
        return [
            'name' => ['name', 'HACKED'],
            'code' => ['code', 'HACKED'],
            'description' => ['description', 'HACKED'],
            'laboratory id' => ['laboratory_id', 999],
            'id' => ['id', 999],
            'created at' => ['created_at', '2020-01-01T00:00:00Z'],
            'updated at' => ['updated_at', '2020-01-01T00:00:00Z'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_cross_tenant_and_nonexistent_areas_share_neutral_404_without_mutation(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $areaB = $this->area($labB);
        $before = $areaB->getRawOriginal();

        $crossTenant = $this->statusRequest($user, $labA, $areaB->id, [
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ])->assertNotFound();
        $missing = $this->statusRequest($user, $labA, 999999999, [
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ])->assertNotFound();

        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());
        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertRawStateUnchanged($before, $areaB);

        foreach ([$crossTenant, $missing] as $response) {
            $this->assertNoLeakage($response);
        }
    }

    public function test_context_switching_changes_only_the_selected_area(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $areaA = $this->area($labA, ['code' => 'A', 'name' => 'Área A']);
        $areaB = $this->area($labB, ['code' => 'B', 'name' => 'Área B']);

        $this->statusRequest($user, $labA, $areaA->id, ['status' => LaboratoryArea::STATUS_INACTIVE])->assertOk();
        $this->statusRequest($user, $labB, $areaB->id, ['status' => LaboratoryArea::STATUS_INACTIVE])->assertOk();
        $this->statusRequest($user, $labA, $areaA->id, ['status' => LaboratoryArea::STATUS_ACTIVE])->assertOk();
        $this->statusRequest($user, $labA, $areaB->id, ['status' => LaboratoryArea::STATUS_ACTIVE])->assertNotFound();

        $this->assertSame(LaboratoryArea::STATUS_ACTIVE, $areaA->refresh()->status);
        $this->assertSame(LaboratoryArea::STATUS_INACTIVE, $areaB->refresh()->status);
    }

    public function test_status_change_is_visible_in_detail_listing_filters_and_search(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);

        $this->statusRequest($user, $laboratory, $area->id, [
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ])->assertOk();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-areas/{$area->id}")
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_INACTIVE)
            ->assertJsonPath('data.code', 'HEM')
            ->assertJsonPath('data.name', 'Hematología');

        $this->indexRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->indexRequest($user, $laboratory, ['status' => 'active'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->indexRequest($user, $laboratory, ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $area->id);
        $this->indexRequest($user, $laboratory, ['search' => 'HEM'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $area->id);
    }

    public function test_general_update_and_create_still_reject_status(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-areas/{$area->id}", ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-areas', [
                'code' => 'QUI',
                'name' => 'Química Clínica',
                'status' => 'inactive',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(LaboratoryArea::STATUS_ACTIVE, $area->refresh()->status);
        $this->assertDatabaseCount('laboratory_areas', 1);
    }

    public function test_guest_is_rejected_without_mutation(): void
    {
        $area = LaboratoryArea::factory()->create();
        $before = $area->getRawOriginal();

        $this->patchJson("/api/v1/laboratory-areas/{$area->id}/status", ['status' => 'inactive'])
            ->assertUnauthorized();

        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_missing_context_is_rejected_without_mutation(): void
    {
        $area = LaboratoryArea::factory()->create();
        $before = $area->getRawOriginal();

        $this->actingAs(User::factory()->create(), 'web')
            ->patchJson("/api/v1/laboratory-areas/{$area->id}/status", ['status' => 'inactive'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_inactive_membership_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $area = $this->area($laboratory);
        $before = $area->getRawOriginal();

        $this->statusRequest($user, $laboratory, $area->id, ['status' => 'inactive'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_missing_subscription_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $area = $this->area($laboratory);
        $before = $area->getRawOriginal();

        $this->statusRequest($user, $laboratory, $area->id, ['status' => 'inactive'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertRawStateUnchanged($before, $area);
    }

    #[DataProvider('invalidRouteProvider')]
    public function test_invalid_route_identifiers_return_not_found(string $area): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->statusRequest($user, $laboratory, $area, ['status' => 'inactive'])
            ->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function invalidRouteProvider(): array
    {
        return [
            'letters' => ['abc'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'laboratory_areas.view', 'laboratory_areas.create', 'laboratory_areas.update',
            'laboratory_areas.change_status',
        ]);

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

    /** @param array<string, mixed> $attributes */
    private function area(Laboratory $laboratory, array $attributes = []): LaboratoryArea
    {
        return LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => 'Pruebas hematológicas.',
            'status' => LaboratoryArea::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function statusRequest(
        User $user,
        Laboratory $laboratory,
        int|string $area,
        array $payload,
    ): TestResponse {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'laboratory_areas.change_status');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-areas/{$area}/status", $payload);
    }

    /** @param array<string, string> $query */
    private function indexRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/laboratory-areas';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    /** @param array<string, mixed> $before */
    private function assertRawStateUnchanged(array $before, LaboratoryArea $area): void
    {
        $after = $area->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }

    private function assertNoLeakage(TestResponse $response): void
    {
        $body = strtolower($response->getContent());

        foreach (['sqlstate', 'laboratoryarea', 'laboratory_id', '/var/www', 'exception', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
