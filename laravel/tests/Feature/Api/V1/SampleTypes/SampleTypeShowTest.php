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

class SampleTypeShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
    }

    public function test_detail_returns_the_exact_contract_for_an_active_sample_type(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'status' => SampleType::STATUS_ACTIVE,
        ]);

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $sampleType->id,
                    'name' => 'Sangre',
                    'status' => SampleType::STATUS_ACTIVE,
                    'created_at' => '2026-09-24T12:00:00.000000Z',
                    'updated_at' => '2026-09-24T12:00:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.links')
            ->assertJsonMissingPath('data.meta');
    }

    public function test_inactive_sample_type_is_visible(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->inactive()->for($laboratory)->create();

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id)
            ->assertOk()
            ->assertJsonPath('data.id', $sampleType->id)
            ->assertJsonPath('data.status', SampleType::STATUS_INACTIVE);
    }

    public function test_cross_tenant_and_nonexistent_records_share_the_same_neutral_404(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleTypeB = SampleType::factory()->for($labB)->create(['name' => 'SECRET-SERUM']);

        $crossTenant = $this->sampleTypeRequest($user, $labA, $sampleTypeB->id)
            ->assertNotFound();
        $missing = $this->sampleTypeRequest($user, $labA, 999999999)
            ->assertNotFound();

        $this->assertSame($crossTenant->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());

        foreach ([$crossTenant, $missing] as $response) {
            $this->assertResponseDoesNotLeak($response, ['SECRET-SERUM', 'SampleType', '999999999']);
        }
    }

    public function test_same_name_across_tenants_is_resolved_by_tenant_and_id(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleTypeA = SampleType::factory()->for($labA)->create(['name' => 'Sangre']);
        $sampleTypeB = SampleType::factory()->for($labB)->create(['name' => 'Sangre']);

        $this->sampleTypeRequest($user, $labA, $sampleTypeA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $sampleTypeA->id);
        $this->sampleTypeRequest($user, $labA, $sampleTypeB->id)->assertNotFound();
    }

    public function test_tenant_context_switching_has_no_residual_state(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleTypeA = SampleType::factory()->for($labA)->create(['name' => 'Sangre']);
        $sampleTypeB = SampleType::factory()->for($labB)->create(['name' => 'Suero']);

        $this->sampleTypeRequest($user, $labA, $sampleTypeA->id)->assertOk();
        $this->sampleTypeRequest($user, $labB, $sampleTypeB->id)->assertOk();
        $this->sampleTypeRequest($user, $labA, $sampleTypeB->id)->assertNotFound();
        $this->sampleTypeRequest($user, $labB, $sampleTypeA->id)->assertNotFound();
        $this->sampleTypeRequest($user, $labA, $sampleTypeA->id)->assertOk();
    }

    public function test_lookup_queries_sample_types_once_with_tenant_and_id(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->for($laboratory)->create();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'sample_types')) {
                $queries[] = $query;
            }
        });

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id)->assertOk();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('laboratory_id', $queries[0]->sql);
        $this->assertStringContainsString('id', $queries[0]->sql);
        $this->assertContains($laboratory->id, $queries[0]->bindings);
        $this->assertContains($sampleType->id, $queries[0]->bindings);
    }

    #[DataProvider('unexpectedQueryProvider')]
    public function test_query_parameters_are_rejected(string $query, string $field): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->for($laboratory)->create();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertResponseDoesNotLeak($response, [$sampleType->name]);
    }

    /** @return array<string, array{string, string}> */
    public static function unexpectedQueryProvider(): array
    {
        return [
            'unknown parameter' => ['foo=bar', 'foo'],
            'tenant injection' => ['laboratory_id=999999', 'laboratory_id'],
        ];
    }

    #[DataProvider('invalidIdProvider')]
    public function test_non_numeric_route_identifiers_return_not_found(string $sampleType): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->sampleTypeRequest($user, $laboratory, $sampleType)->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function invalidIdProvider(): array
    {
        return [
            'letters' => ['abc'],
            'word' => ['invalid'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    public function test_detail_is_read_only(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'status' => SampleType::STATUS_ACTIVE,
        ]);
        $before = $sampleType->only(['name', 'status', 'updated_at']);
        $count = SampleType::query()->count();

        $this->travel(5)->minutes();
        $this->sampleTypeRequest($user, $laboratory, $sampleType->id)->assertOk();

        $sampleType->refresh();
        $this->assertSame($before['name'], $sampleType->name);
        $this->assertSame($before['status'], $sampleType->status);
        $this->assertTrue($before['updated_at']->equalTo($sampleType->updated_at));
        $this->assertSame($count, SampleType::query()->count());
    }

    public function test_guest_is_rejected_without_leaking_sample_type_data(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'SECRET-GUEST']);
        $count = SampleType::query()->count();

        $response = $this->getJson("/api/v1/sample-types/{$sampleType->id}")
            ->assertUnauthorized();

        $this->assertResponseDoesNotLeak($response, [$sampleType->name]);
        $this->assertSame($count, SampleType::query()->count());
    }

    public function test_missing_laboratory_context_is_rejected_without_leakage_or_writes(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'SECRET-CONTEXT']);
        $count = SampleType::query()->count();

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->getJson("/api/v1/sample-types/{$sampleType->id}")
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertResponseDoesNotLeak($response, [$sampleType->name]);
        $this->assertSame($count, SampleType::query()->count());
    }

    public function test_inactive_membership_is_rejected_without_leakage_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $sampleType = SampleType::factory()->for($laboratory)->create(['name' => 'SECRET-INACTIVE']);
        $count = SampleType::query()->count();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertResponseDoesNotLeak($response, [$sampleType->name]);
        $this->assertSame($count, SampleType::query()->count());
    }

    public function test_nonexistent_membership_is_rejected_without_leakage_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        $sampleType = SampleType::factory()->for($laboratory)->create(['name' => 'SECRET-NO-MEMBER']);
        $count = SampleType::query()->count();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertResponseDoesNotLeak($response, [$sampleType->name]);
        $this->assertSame($count, SampleType::query()->count());
    }

    public function test_missing_subscription_is_rejected_without_leakage_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $sampleType = SampleType::factory()->for($laboratory)->create(['name' => 'SECRET-SUBSCRIPTION']);
        $count = SampleType::query()->count();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertResponseDoesNotLeak($response, [$sampleType->name]);
        $this->assertSame($count, SampleType::query()->count());
    }

    public function test_create_then_detail_returns_the_persisted_sample_type(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $created = $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/sample-types', ['name' => 'Plasma'])
            ->assertCreated();

        $this->sampleTypeRequest($user, $laboratory, $created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Plasma')
            ->assertJsonPath('data.status', SampleType::STATUS_ACTIVE);
    }

    /** @return array{User, Laboratory} */
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

    private function sampleTypeRequest(
        User $user,
        Laboratory $laboratory,
        int|string $sampleType,
        string $query = '',
    ): TestResponse {
        $suffix = $query === '' ? '' : "?{$query}";

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/sample-types/{$sampleType}{$suffix}");
    }

    /** @param array<int, string> $secrets */
    private function assertResponseDoesNotLeak(TestResponse $response, array $secrets): void
    {
        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach ([...$secrets, 'SQLSTATE', 'bindings', '/var/www', 'stack trace'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }
}
