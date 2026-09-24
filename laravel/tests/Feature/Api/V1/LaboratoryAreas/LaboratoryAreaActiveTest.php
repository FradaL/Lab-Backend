<?php

namespace Tests\Feature\Api\V1\LaboratoryAreas;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryAreaActiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_active_endpoint_returns_only_active_areas_in_exact_lightweight_shape(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $beta = $this->area($laboratory, [
            'code' => 'BET',
            'name' => 'Beta',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);
        $this->area($laboratory, [
            'code' => 'QUI',
            'name' => 'Química Clínica',
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        $alpha = $this->area($laboratory, [
            'code' => 'ALP',
            'name' => 'Alpha',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);

        $response = $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => $alpha->id, 'code' => 'ALP', 'name' => 'Alpha'],
                    ['id' => $beta->id, 'code' => 'BET', 'name' => 'Beta'],
                ],
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');

        foreach ($response->json('data') as $item) {
            $this->assertEqualsCanonicalizing(['id', 'code', 'name'], array_keys($item));
        }
    }

    #[DataProvider('emptyCollectionProvider')]
    public function test_empty_and_only_inactive_catalogs_return_an_empty_collection(bool $createInactive): void
    {
        [$user, $laboratory] = $this->activeTenant();

        if ($createInactive) {
            $this->area($laboratory, ['status' => LaboratoryArea::STATUS_INACTIVE]);
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

    public function test_all_active_areas_are_returned_without_pagination_or_arbitrary_limit(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (range(1, 105) as $number) {
            $this->area($laboratory, [
                'code' => sprintf('A%03d', $number),
                'name' => sprintf('Area %03d', $number),
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
        $areaA = $this->area($labA, ['code' => 'HEM', 'name' => 'Hematología']);
        $this->area($labA, ['code' => 'QUI', 'name' => 'Química', 'status' => LaboratoryArea::STATUS_INACTIVE]);
        $areaB1 = $this->area($labB, ['code' => 'MIC', 'name' => 'Microbiología']);
        $areaB2 = $this->area($labB, ['code' => 'SER', 'name' => 'Serología']);

        $responseA = $this->activeRequest($user, $labA)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$areaA->id], $responseA->json('data.*.id'));

        $responseB = $this->activeRequest($user, $labB)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$areaB1->id, $areaB2->id], $responseB->json('data.*.id'));

        $responseAAgain = $this->activeRequest($user, $labA)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$areaA->id], $responseAAgain->json('data.*.id'));
    }

    #[DataProvider('queryParameterProvider')]
    public function test_every_query_parameter_is_rejected(string $parameter, string $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->area($laboratory);

        $this->activeRequest($user, $laboratory, [$parameter => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$parameter]);
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'search' => ['search', 'hem'],
            'status' => ['status', 'inactive'],
            'sort' => ['sort', 'name'],
            'direction' => ['direction', 'desc'],
            'page' => ['page', '1'],
            'per page' => ['per_page', '10'],
            'tenant injection' => ['laboratory_id', '999'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_status_changes_are_reflected_immediately(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);

        $this->activeRequest($user, $laboratory)
            ->assertJsonPath('data.0.id', $area->id);

        $this->statusRequest($user, $laboratory, $area->id, LaboratoryArea::STATUS_INACTIVE)
            ->assertOk();
        $this->activeRequest($user, $laboratory)
            ->assertExactJson(['data' => []]);

        $this->statusRequest($user, $laboratory, $area->id, LaboratoryArea::STATUS_ACTIVE)
            ->assertOk();
        $this->activeRequest($user, $laboratory)
            ->assertJsonPath('data.0.id', $area->id);
    }

    public function test_code_and_name_updates_are_reflected_immediately_in_server_order(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory, ['code' => 'HEM', 'name' => 'Hematología']);
        $alpha = $this->area($laboratory, ['code' => 'ALP', 'name' => 'Alpha']);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-areas/{$area->id}", [
                'code' => 'HEM-CL',
                'name' => 'Aardvark Hematología Clínica',
            ])
            ->assertOk();

        $response = $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([$area->id, $alpha->id], $response->json('data.*.id'));
        $response
            ->assertJsonPath('data.0.code', 'HEM-CL')
            ->assertJsonPath('data.0.name', 'Aardvark Hematología Clínica');
    }

    public function test_query_is_single_tenant_status_scoped_and_read_only(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $before = $area->getRawOriginal();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'laboratory_areas')) {
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
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertSame([$laboratory->id, LaboratoryArea::STATUS_ACTIVE], $selects[0]->bindings);
        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_guest_is_rejected_without_area_data(): void
    {
        $area = LaboratoryArea::factory()->create(['code' => 'SECRET-CODE']);

        $response = $this->getJson('/api/v1/laboratory-areas/active')
            ->assertUnauthorized();

        $this->assertPipelineResponseDoesNotLeak($response, $area);
    }

    public function test_missing_context_is_rejected_without_area_data(): void
    {
        $area = LaboratoryArea::factory()->create(['code' => 'SECRET-CODE']);

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/laboratory-areas/active')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertPipelineResponseDoesNotLeak($response, $area);
    }

    public function test_inactive_membership_is_rejected_without_area_data(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $area = $this->area($laboratory, ['code' => 'SECRET-CODE']);

        $response = $this->activeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertPipelineResponseDoesNotLeak($response, $area);
    }

    public function test_missing_subscription_is_rejected_without_area_data(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $area = $this->area($laboratory, ['code' => 'SECRET-CODE']);

        $response = $this->activeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertPipelineResponseDoesNotLeak($response, $area);
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
    private function area(Laboratory $laboratory, array $attributes = []): LaboratoryArea
    {
        return LaboratoryArea::factory()->for($laboratory)->create($attributes);
    }

    /** @param array<string, string> $query */
    private function activeRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/laboratory-areas/active';

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
        int $area,
        string $status,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-areas/{$area}/status", compact('status'));
    }

    /** @param array<string, mixed> $before */
    private function assertRawStateUnchanged(array $before, LaboratoryArea $area): void
    {
        $after = $area->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }

    private function assertPipelineResponseDoesNotLeak(
        TestResponse $response,
        LaboratoryArea $area,
    ): void {
        $body = strtolower($response->getContent());

        $this->assertStringNotContainsString(strtolower($area->code), $body);
        $this->assertStringNotContainsString(strtolower($area->name), $body);
        $this->assertStringNotContainsString('laboratory_id', $body);
    }
}
