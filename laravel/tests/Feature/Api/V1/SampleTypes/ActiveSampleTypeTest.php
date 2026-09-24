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

class ActiveSampleTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
    }

    public function test_active_endpoint_returns_only_active_sample_types_in_exact_lightweight_shape(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sangre = $this->sampleType($laboratory, [
            'name' => 'Sangre',
            'status' => SampleType::STATUS_ACTIVE,
        ]);
        $this->sampleType($laboratory, [
            'name' => 'Orina',
            'status' => SampleType::STATUS_INACTIVE,
        ]);
        $plasma = $this->sampleType($laboratory, [
            'name' => 'Plasma',
            'status' => SampleType::STATUS_ACTIVE,
        ]);

        $response = $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => $plasma->id, 'name' => 'Plasma'],
                    ['id' => $sangre->id, 'name' => 'Sangre'],
                ],
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');

        foreach ($response->json('data') as $item) {
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($item));
        }
    }

    #[DataProvider('emptyCollectionProvider')]
    public function test_empty_and_only_inactive_catalogs_return_an_empty_collection(bool $createInactive): void
    {
        [$user, $laboratory] = $this->activeTenant();

        if ($createInactive) {
            $this->sampleType($laboratory, ['status' => SampleType::STATUS_INACTIVE]);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /** @return array<string, array{bool}> */
    public static function emptyCollectionProvider(): array
    {
        return [
            'empty catalog' => [false],
            'only inactive' => [true],
        ];
    }

    public function test_all_active_sample_types_are_returned_without_pagination_or_arbitrary_limit(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (range(1, 105) as $number) {
            $this->sampleType($laboratory, [
                'name' => sprintf('Tipo %03d', $number),
            ]);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonCount(105, 'data')
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');
    }

    public function test_tenant_isolation_is_symmetric_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleA = $this->sampleType($labA, ['name' => 'Sangre']);
        $this->sampleType($labA, ['name' => 'Orina', 'status' => SampleType::STATUS_INACTIVE]);
        $sampleB1 = $this->sampleType($labB, ['name' => 'Plasma']);
        $sampleB2 = $this->sampleType($labB, ['name' => 'Suero']);

        $responseA = $this->activeRequest($user, $labA)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$sampleA->id], $responseA->json('data.*.id'));

        $responseB = $this->activeRequest($user, $labB)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$sampleB1->id, $sampleB2->id], $responseB->json('data.*.id'));

        $responseAAgain = $this->activeRequest($user, $labA)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$sampleA->id], $responseAAgain->json('data.*.id'));
    }

    #[DataProvider('queryParameterProvider')]
    public function test_every_query_parameter_is_rejected(string $parameter, string $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->sampleType($laboratory);

        $this->activeRequest($user, $laboratory, [$parameter => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$parameter]);
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'search' => ['search', 'sangre'],
            'status' => ['status', 'active'],
            'sort' => ['sort', 'name'],
            'direction' => ['direction', 'desc'],
            'page' => ['page', '1'],
            'per page' => ['per_page', '10'],
            'tenant injection' => ['laboratory_id', '999'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_multiple_query_parameters_are_rejected_together(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->activeRequest($user, $laboratory, ['search' => 'sangre', 'page' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search', 'page']);
    }

    public function test_status_changes_are_reflected_immediately(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);

        $this->activeRequest($user, $laboratory)
            ->assertJsonPath('data.0.id', $sampleType->id);

        $this->statusRequest($user, $laboratory, $sampleType->id, SampleType::STATUS_INACTIVE)
            ->assertOk();
        $this->activeRequest($user, $laboratory)
            ->assertExactJson(['data' => []]);

        $this->statusRequest($user, $laboratory, $sampleType->id, SampleType::STATUS_ACTIVE)
            ->assertOk();
        $this->activeRequest($user, $laboratory)
            ->assertJsonPath('data.0.id', $sampleType->id);
    }

    public function test_name_update_is_reflected_immediately_in_server_order(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory, ['name' => 'Sangre']);
        $plasma = $this->sampleType($laboratory, ['name' => 'Plasma']);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/sample-types/{$sampleType->id}", [
                'name' => 'A Sangre total',
            ])
            ->assertOk();

        $response = $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([$sampleType->id, $plasma->id], $response->json('data.*.id'));
        $response->assertJsonPath('data.0.name', 'A Sangre total');
    }

    public function test_query_is_single_tenant_status_scoped_ordered_and_read_only(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $before = $sampleType->getRawOriginal();
        $beforeCount = SampleType::count();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'sample_types')) {
                $queries[] = $query;
            }
        });

        $this->activeRequest($user, $laboratory)->assertOk();

        $selects = array_values(array_filter(
            $queries,
            fn (QueryExecuted $query): bool => str_starts_with(strtolower($query->sql), 'select'),
        ));

        $this->assertCount(1, $selects);
        $sql = strtolower($selects[0]->sql);
        $this->assertStringContainsString('laboratory_id', $sql);
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('order by "name" asc, "id" asc', $sql);
        $this->assertStringNotContainsString('count(', $sql);
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertSame([$laboratory->id, SampleType::STATUS_ACTIVE], $selects[0]->bindings);
        $this->assertSame($beforeCount, SampleType::count());
        $this->assertRawStateUnchanged($before, $sampleType);
    }

    public function test_guest_is_rejected_without_data_or_writes(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'Secret Guest Sample']);

        $response = $this->getJson('/api/v1/sample-types/active')
            ->assertUnauthorized();

        $this->assertPipelineFailureIsSafe($response, $sampleType);
    }

    public function test_missing_context_is_rejected_without_data_or_writes(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'Secret Context Sample']);

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/sample-types/active')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertPipelineFailureIsSafe($response, $sampleType);
    }

    public function test_inactive_membership_is_rejected_without_data_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $sampleType = $this->sampleType($laboratory, ['name' => 'Secret Inactive Membership']);

        $response = $this->activeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertPipelineFailureIsSafe($response, $sampleType);
    }

    public function test_missing_membership_is_rejected_without_data_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        $sampleType = $this->sampleType($laboratory, ['name' => 'Secret Missing Membership']);

        $response = $this->activeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertPipelineFailureIsSafe($response, $sampleType);
    }

    public function test_missing_subscription_is_rejected_without_data_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $sampleType = $this->sampleType($laboratory, ['name' => 'Secret Missing Subscription']);

        $response = $this->activeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertPipelineFailureIsSafe($response, $sampleType);
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

    /** @param array<string, mixed> $attributes */
    private function sampleType(Laboratory $laboratory, array $attributes = []): SampleType
    {
        return SampleType::factory()->for($laboratory)->create($attributes);
    }

    /** @param array<string, string> $query */
    private function activeRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/sample-types/active';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    private function statusRequest(
        User $user,
        Laboratory $laboratory,
        int $sampleType,
        string $status,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/sample-types/{$sampleType}/status", compact('status'));
    }

    /** @param array<string, mixed> $before */
    private function assertRawStateUnchanged(array $before, SampleType $sampleType): void
    {
        $after = $sampleType->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }

    private function assertPipelineFailureIsSafe(
        TestResponse $response,
        SampleType $sampleType,
    ): void {
        $before = $sampleType->getRawOriginal();
        $beforeCount = SampleType::count();
        $body = strtolower($response->getContent());

        $this->assertStringNotContainsString(strtolower($sampleType->name), $body);
        $this->assertStringNotContainsString('laboratory_id', $body);
        $this->assertStringNotContainsString('select ', $body);
        $this->assertStringNotContainsString('app\\', $body);
        $this->assertSame($beforeCount, SampleType::count());
        $this->assertRawStateUnchanged($before, $sampleType);
    }
}
