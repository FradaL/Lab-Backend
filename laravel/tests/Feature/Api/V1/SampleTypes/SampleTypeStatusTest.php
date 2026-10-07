<?php

namespace Tests\Feature\Api\V1\SampleTypes;

use App\Models\Laboratory;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SampleTypeStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
    }

    #[DataProvider('statusTransitionProvider')]
    public function test_status_transitions_are_idempotent_and_return_exact_detail(
        string $initialStatus,
        string $requestedStatus,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory, ['status' => $initialStatus]);
        $originalUpdatedAt = $sampleType->updated_at?->toISOString();
        $this->travel(1)->minute();

        $response = $this->statusRequest($user, $laboratory, $sampleType->id, [
            'status' => $requestedStatus,
        ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $sampleType->id,
                    'name' => 'Sangre',
                    'status' => $requestedStatus,
                    'created_at' => '2026-09-24T12:00:00.000000Z',
                    'updated_at' => $initialStatus === $requestedStatus
                        ? $originalUpdatedAt
                        : '2026-09-24T12:01:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.links');

        $sampleType->refresh();
        $this->assertSame($requestedStatus, $sampleType->status);
        $this->assertSame('Sangre', $sampleType->name);
        $this->assertSame($laboratory->id, $sampleType->laboratory_id);
        $this->assertSame(200, $response->getStatusCode());
    }

    /** @return array<string, array{string, string}> */
    public static function statusTransitionProvider(): array
    {
        return [
            'active to inactive' => [SampleType::STATUS_ACTIVE, SampleType::STATUS_INACTIVE],
            'inactive to active' => [SampleType::STATUS_INACTIVE, SampleType::STATUS_ACTIVE],
            'active to active' => [SampleType::STATUS_ACTIVE, SampleType::STATUS_ACTIVE],
            'inactive to inactive' => [SampleType::STATUS_INACTIVE, SampleType::STATUS_INACTIVE],
        ];
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_payloads_are_rejected_without_changes(array $payload): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $before = $sampleType->getRawOriginal();

        $this->statusRequest($user, $laboratory, $sampleType->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertRawStateUnchanged($before, $sampleType);
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
            'title case' => [['status' => 'Active']],
            'mixed case' => [['status' => 'aCtIvE']],
            'leading whitespace' => [['status' => ' active']],
            'trailing whitespace' => [['status' => 'inactive ']],
            'surrounding whitespace' => [['status' => ' active ']],
            'enabled' => [['status' => 'enabled']],
            'disabled' => [['status' => 'disabled']],
            'integer one' => [['status' => 1]],
            'integer zero' => [['status' => 0]],
            'boolean true' => [['status' => true]],
            'boolean false' => [['status' => false]],
            'array' => [['status' => ['active']]],
            'object' => [['status' => (object) ['value' => 'active']]],
        ];
    }

    #[DataProvider('unexpectedFieldProvider')]
    public function test_additional_fields_reject_the_whole_payload_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $before = $sampleType->getRawOriginal();

        $this->statusRequest($user, $laboratory, $sampleType->id, [
            'status' => SampleType::STATUS_INACTIVE,
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertRawStateUnchanged($before, $sampleType);
    }

    /** @return array<string, array{string, mixed}> */
    public static function unexpectedFieldProvider(): array
    {
        return [
            'name' => ['name', 'HACKED'],
            'laboratory id' => ['laboratory_id', 999],
            'id' => ['id', 999],
            'created at' => ['created_at', '2020-01-01T00:00:00Z'],
            'updated at' => ['updated_at', '2020-01-01T00:00:00Z'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    #[DataProvider('queryBehaviorProvider')]
    public function test_query_shape_and_update_count_match_real_or_noop_transition(
        string $initialStatus,
        string $requestedStatus,
        int $expectedUpdates,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory, ['status' => $initialStatus]);
        $lookups = [];
        $updates = [];

        DB::listen(function (QueryExecuted $query) use (&$lookups, &$updates): void {
            if (str_contains($query->sql, 'select * from "sample_types"')) {
                $lookups[] = $query;
            }

            if (str_starts_with($query->sql, 'update "sample_types"')) {
                $updates[] = $query;
            }
        });

        $this->statusRequest($user, $laboratory, $sampleType->id, ['status' => $requestedStatus])
            ->assertOk();

        $this->assertCount(1, $lookups);
        $this->assertStringContainsString('laboratory_id', $lookups[0]->sql);
        $this->assertStringContainsString('id', $lookups[0]->sql);
        $this->assertContains($laboratory->id, $lookups[0]->bindings);
        $this->assertContains($sampleType->id, $lookups[0]->bindings);
        $this->assertCount($expectedUpdates, $updates);
    }

    /** @return array<string, array{string, string, int}> */
    public static function queryBehaviorProvider(): array
    {
        return [
            'real transition has one update' => [SampleType::STATUS_ACTIVE, SampleType::STATUS_INACTIVE, 1],
            'same state has no update' => [SampleType::STATUS_ACTIVE, SampleType::STATUS_ACTIVE, 0],
        ];
    }

    public function test_cross_tenant_and_nonexistent_records_share_neutral_404_without_mutation(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleTypeB = $this->sampleType($labB, ['name' => 'SECRET-SERUM']);
        $before = $sampleTypeB->getRawOriginal();

        $crossTenant = $this->statusRequest($user, $labA, $sampleTypeB->id, ['status' => SampleType::STATUS_INACTIVE])
            ->assertNotFound();
        $missing = $this->statusRequest($user, $labA, 999999999, ['status' => SampleType::STATUS_INACTIVE])
            ->assertNotFound();

        $this->assertSame($crossTenant->getStatusCode(), $missing->getStatusCode());
        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());
        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertRawStateUnchanged($before, $sampleTypeB);
        $this->assertNoLeakage($crossTenant, ['SECRET-SERUM']);
        $this->assertNoLeakage($missing, ['SECRET-SERUM']);
    }

    public function test_context_switching_changes_only_the_selected_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleTypeA = $this->sampleType($labA, ['name' => 'Sangre']);
        $sampleTypeB = $this->sampleType($labB, ['name' => 'Suero']);

        $this->statusRequest($user, $labA, $sampleTypeA->id, ['status' => SampleType::STATUS_INACTIVE])->assertOk();
        $this->statusRequest($user, $labB, $sampleTypeB->id, ['status' => SampleType::STATUS_INACTIVE])->assertOk();
        $this->statusRequest($user, $labA, $sampleTypeA->id, ['status' => SampleType::STATUS_ACTIVE])->assertOk();
        $this->statusRequest($user, $labB, $sampleTypeB->id, ['status' => SampleType::STATUS_ACTIVE])->assertOk();
        $this->statusRequest($user, $labA, $sampleTypeB->id, ['status' => SampleType::STATUS_INACTIVE])->assertNotFound();

        $this->assertSame(SampleType::STATUS_ACTIVE, $sampleTypeA->refresh()->status);
        $this->assertSame(SampleType::STATUS_ACTIVE, $sampleTypeB->refresh()->status);
    }

    #[DataProvider('queryParameterProvider')]
    public function test_query_parameters_are_rejected_without_changes(string $query, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $before = $sampleType->getRawOriginal();

        $this->statusRequest(
            $user,
            $laboratory,
            $sampleType->id,
            ['status' => SampleType::STATUS_INACTIVE],
            $query,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertRawStateUnchanged($before, $sampleType);
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'unknown query' => ['foo=bar', 'foo'],
            'tenant injection query' => ['laboratory_id=999', 'laboratory_id'],
        ];
    }

    public function test_general_update_and_create_still_reject_status(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/sample-types/{$sampleType->id}", ['status' => SampleType::STATUS_INACTIVE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/sample-types', [
                'name' => 'Plasma',
                'status' => SampleType::STATUS_INACTIVE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(SampleType::STATUS_ACTIVE, $sampleType->refresh()->status);
        $this->assertDatabaseCount('sample_types', 1);
    }

    public function test_status_change_is_visible_in_detail_and_listing_filters(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);

        $this->statusRequest($user, $laboratory, $sampleType->id, ['status' => SampleType::STATUS_INACTIVE])
            ->assertOk();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/sample-types/{$sampleType->id}")
            ->assertOk()
            ->assertJsonPath('data.status', SampleType::STATUS_INACTIVE)
            ->assertJsonPath('data.name', 'Sangre');

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/sample-types?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sampleType->id);
    }

    public function test_guest_is_rejected_without_mutation(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'SECRET-GUEST']);
        $before = $sampleType->getRawOriginal();

        $response = $this->patchJson("/api/v1/sample-types/{$sampleType->id}/status", ['status' => 'inactive'])
            ->assertUnauthorized();

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoLeakage($response, ['SECRET-GUEST']);
    }

    public function test_missing_context_is_rejected_without_mutation(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'SECRET-CONTEXT']);
        $before = $sampleType->getRawOriginal();

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->patchJson("/api/v1/sample-types/{$sampleType->id}/status", ['status' => 'inactive'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoLeakage($response, ['SECRET-CONTEXT']);
    }

    public function test_inactive_membership_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $sampleType = $this->sampleType($laboratory, ['name' => 'SECRET-INACTIVE']);
        $before = $sampleType->getRawOriginal();

        $response = $this->statusRequest($user, $laboratory, $sampleType->id, ['status' => 'inactive'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoLeakage($response, ['SECRET-INACTIVE']);
    }

    public function test_nonexistent_membership_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        $sampleType = $this->sampleType($laboratory, ['name' => 'SECRET-NO-MEMBER']);
        $before = $sampleType->getRawOriginal();

        $response = $this->statusRequest($user, $laboratory, $sampleType->id, ['status' => 'inactive'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoLeakage($response, ['SECRET-NO-MEMBER']);
    }

    public function test_missing_subscription_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $sampleType = $this->sampleType($laboratory, ['name' => 'SECRET-SUBSCRIPTION']);
        $before = $sampleType->getRawOriginal();

        $response = $this->statusRequest($user, $laboratory, $sampleType->id, ['status' => 'inactive'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoLeakage($response, ['SECRET-SUBSCRIPTION']);
    }

    #[DataProvider('invalidRouteProvider')]
    public function test_non_numeric_routes_return_not_found_without_writes(string $sampleType): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $count = SampleType::query()->count();

        $this->statusRequest($user, $laboratory, $sampleType, ['status' => 'inactive'])
            ->assertNotFound();

        $this->assertSame($count, SampleType::query()->count());
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
            'sample_types.view', 'sample_types.create', 'sample_types.update', 'sample_types.change_status',
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
    private function sampleType(Laboratory $laboratory, array $attributes = []): SampleType
    {
        return SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'status' => SampleType::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function statusRequest(
        User $user,
        Laboratory $laboratory,
        int|string $sampleType,
        array $payload,
        string $query = '',
    ): TestResponse {
        $suffix = $query === '' ? '' : "?{$query}";

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'sample_types.change_status');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/sample-types/{$sampleType}/status{$suffix}", $payload);
    }

    /** @param array<string, mixed> $before */
    private function assertRawStateUnchanged(array $before, SampleType $sampleType): void
    {
        $after = $sampleType->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }

    /** @param list<string> $secrets */
    private function assertNoLeakage(TestResponse $response, array $secrets = []): void
    {
        $body = strtolower($response->getContent());

        foreach ([...$secrets, 'sqlstate', 'laboratory_id', 'select ', 'update ', 'bindings', '/var/www', 'exception', 'trace'] as $secret) {
            $this->assertStringNotContainsString(strtolower($secret), $body);
        }
    }
}
