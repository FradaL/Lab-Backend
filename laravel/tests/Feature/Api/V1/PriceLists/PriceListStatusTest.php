<?php

namespace Tests\Feature\Api\V1\PriceLists;

use App\Http\Controllers\Api\V1\PriceListController;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PriceListStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    }

    #[DataProvider('statusMatrixProvider')]
    public function test_official_status_matrix(
        string $current,
        bool $isDefault,
        string $requested,
        int $expectedStatus,
        bool $changes,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'description' => 'Metadata',
            'currency' => 'GTQ',
            'status' => $current,
            'is_default' => $isDefault,
        ]);
        $before = $this->priceListRow($priceList);
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_contains($query->sql, 'price_lists') && str_starts_with(strtolower($query->sql), 'update ')) {
                $updates++;
            }
        });
        $this->travel(5)->minutes();

        $response = $this->statusRequest($user, $laboratory, $priceList->id, ['status' => $requested])
            ->assertStatus($expectedStatus);

        if ($expectedStatus === 422) {
            $response
                ->assertJsonValidationErrors(['status'])
                ->assertJsonPath('errors.status.0', 'The default price list cannot be deactivated.');
        } else {
            $response
                ->assertJsonPath('data.status', $requested)
                ->assertJsonPath('data.is_default', $isDefault)
                ->assertJsonPath('data.name', 'Original')
                ->assertJsonPath('data.description', 'Metadata')
                ->assertJsonPath('data.currency', 'GTQ');
        }

        $after = $this->priceListRow($priceList);
        $this->assertSame($changes ? $requested : $current, $after->status);
        $this->assertSame($isDefault, (bool) $after->is_default);
        $this->assertSame('Original', $after->name);
        $this->assertSame('Metadata', $after->description);
        $this->assertSame('GTQ', $after->currency);
        $this->assertSame($laboratory->id, $after->laboratory_id);
        $this->assertSame($before->created_at, $after->created_at);
        $this->assertSame($changes ? '2026-09-30 12:05:00' : $before->updated_at, $after->updated_at);
        $this->assertSame($changes ? 1 : 0, $updates);
    }

    public static function statusMatrixProvider(): array
    {
        return [
            'active regular to inactive' => ['active', false, 'inactive', 200, true],
            'inactive regular to active' => ['inactive', false, 'active', 200, true],
            'active regular idempotent' => ['active', false, 'active', 200, false],
            'inactive regular idempotent' => ['inactive', false, 'inactive', 200, false],
            'active default blocked' => ['active', true, 'inactive', 422, false],
            'active default idempotent' => ['active', true, 'active', 200, false],
            'inactive default repaired' => ['inactive', true, 'active', 200, true],
            'inactive default idempotent' => ['inactive', true, 'inactive', 200, false],
        ];
    }

    public function test_success_returns_exact_resource_without_relationships(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'General',
            'description' => null,
            'currency' => 'USD',
        ]);
        $this->travel(5)->minutes();

        $this->statusRequest($user, $laboratory, $priceList->id, ['status' => 'inactive'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $priceList->id,
                'name' => 'General',
                'description' => null,
                'currency' => 'USD',
                'is_default' => false,
                'status' => 'inactive',
                'created_at' => '2026-09-30T12:00:00.000000Z',
                'updated_at' => '2026-09-30T12:05:00.000000Z',
            ]])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.exam_prices');
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_values_are_rejected_without_writes(mixed $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create(['status' => 'active']);
        $before = $this->priceListRow($priceList);

        $this->statusRequest($user, $laboratory, $priceList->id, ['status' => $status])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertEquals($before, $this->priceListRow($priceList));
    }

    public static function invalidStatusProvider(): array
    {
        return [
            'uppercase active' => ['ACTIVE'],
            'uppercase inactive' => ['INACTIVE'],
            'title active' => ['Active'],
            'title inactive' => ['Inactive'],
            'mixed case' => ['aCtIvE'],
            'leading active whitespace' => [' active'],
            'trailing active whitespace' => ['active '],
            'surrounding active whitespace' => [' active '],
            'leading inactive whitespace' => [' inactive'],
            'trailing inactive whitespace' => ['inactive '],
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'zero' => [0],
            'one' => [1],
            'array' => [[]],
            'object' => [(object) []],
            'empty' => [''],
            'enabled' => ['enabled'],
            'disabled' => ['disabled'],
            'pending' => ['pending'],
        ];
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_unknown_fields_reject_whole_payload_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create(['status' => 'active']);
        $before = $this->priceListRow($priceList);

        $this->statusRequest($user, $laboratory, $priceList->id, [
            'status' => 'inactive',
            $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->priceListRow($priceList));
    }

    public static function unknownFieldProvider(): array
    {
        return [
            'name' => ['name', 'Changed'],
            'description' => ['description', 'Changed'],
            'currency' => ['currency', 'USD'],
            'is default' => ['is_default', true],
            'laboratory' => ['laboratory_id', 999],
            'id' => ['id', 999],
            'created timestamp' => ['created_at', '2020-01-01'],
            'updated timestamp' => ['updated_at', '2020-01-01'],
            'arbitrary' => ['future_field', 'value'],
        ];
    }

    public function test_empty_payload_is_rejected_without_write(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $before = $this->priceListRow($priceList);

        $this->statusRequest($user, $laboratory, $priceList->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertEquals($before, $this->priceListRow($priceList));
    }

    public function test_blocked_default_does_not_touch_any_price_list_or_issue_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $default = PriceList::factory()->for($laboratory)->asDefault()->create([
            'name' => 'Default',
            'description' => 'Protected',
            'currency' => 'GTQ',
        ]);
        $otherA = PriceList::factory()->for($laboratory)->create(['name' => 'Other A']);
        $otherB = PriceList::factory()->for($laboratory)->inactive()->create(['name' => 'Other B']);
        $before = DB::table('price_lists')->orderBy('id')->get()->all();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $this->statusRequest($user, $laboratory, $default->id, ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The default price list cannot be deactivated.');

        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select *')));
        $this->assertEquals($before, DB::table('price_lists')->orderBy('id')->get()->all());
        $this->assertSame('Other A', $otherA->fresh()->name);
        $this->assertSame('Other B', $otherB->fresh()->name);
    }

    #[DataProvider('queryBehaviorProvider')]
    public function test_query_behavior_uses_one_tenant_lookup_and_no_business_queries(
        string $current,
        bool $isDefault,
        string $requested,
        int $expectedStatus,
        int $expectedUpdates,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'status' => $current,
            'is_default' => $isDefault,
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $this->statusRequest($user, $laboratory, $priceList->id, ['status' => $requested])
            ->assertStatus($expectedStatus);

        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select *')));
        $this->assertCount($expectedUpdates, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        $this->assertCount(1 + $expectedUpdates, $queries);
        $this->assertStringContainsString('"laboratory_id" = ?', $queries[0]);
        $this->assertStringContainsString('"id" = ?', $queries[0]);
    }

    public static function queryBehaviorProvider(): array
    {
        return [
            'dirty' => ['active', false, 'inactive', 200, 1],
            'idempotent' => ['active', false, 'active', 200, 0],
            'blocked' => ['active', true, 'inactive', 422, 0],
        ];
    }

    public function test_lookup_precedes_validation_with_neutral_404_parity(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $foreign = PriceList::factory()->for($labB)->create(['status' => 'active']);

        foreach ([['status' => 'active'], ['status' => 'INVALID']] as $payload) {
            $cross = $this->statusRequest($user, $labA, $foreign->id, $payload)
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
            $missing = $this->statusRequest($user, $labA, 999999999, $payload)
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
            $this->assertSame($cross->getContent(), $missing->getContent());
        }

        $this->assertSame('active', $foreign->fresh()->status);
    }

    public function test_tenant_isolation_is_symmetric_during_context_switching(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $listA = PriceList::factory()->for($labA)->create(['status' => 'active']);
        $listB = PriceList::factory()->for($labB)->inactive()->create();

        $this->statusRequest($user, $labA, $listA->id, ['status' => 'inactive'])->assertOk();
        $this->statusRequest($user, $labB, $listB->id, ['status' => 'active'])->assertOk();
        $this->statusRequest($user, $labA, $listB->id, ['status' => 'inactive'])->assertNotFound();
        $this->statusRequest($user, $labB, $listA->id, ['status' => 'active'])->assertNotFound();
        $this->statusRequest($user, $labA, $listA->id, ['status' => 'active'])->assertOk();
        $this->statusRequest($user, $labB, $listB->id, ['status' => 'inactive'])->assertOk();

        $this->assertSame('active', $listA->fresh()->status);
        $this->assertSame('inactive', $listB->fresh()->status);
    }

    public function test_pipeline_errors_precede_lookup_and_invalid_payload(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $invalid = ['status' => 'INVALID'];

        $this->patchJson("/api/v1/price-lists/{$priceList->id}/status", $invalid)->assertUnauthorized();
        $this->actingAs($user, 'web')->patchJson("/api/v1/price-lists/{$priceList->id}/status", $invalid)
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->patchJson("/api/v1/price-lists/{$priceList->id}/status", $invalid)
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->patchJson("/api/v1/price-lists/{$priceList->id}/status", $invalid)
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $this->createCurrentSubscription($laboratory);
        $this->statusRequest($user, $laboratory, $priceList->id, $invalid)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        DB::table('subscriptions')->delete();
        $this->statusRequest($user, $laboratory, $priceList->id, $invalid)
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->statusRequest($user, $inactive, $priceList->id, $invalid)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');
        $this->assertSame('active', $priceList->fresh()->status);
    }

    #[DataProvider('malformedIdProvider')]
    public function test_malformed_ids_do_not_reach_controller(string $id): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->statusRequest($user, $laboratory, $id, ['status' => 'active'])->assertNotFound();
    }

    public static function malformedIdProvider(): array
    {
        return [
            'letters' => ['abc'],
            'mixed' => ['1abc'],
            'negative' => ['-1'],
            'decimal' => ['1.5'],
            'zero' => ['0'],
            'large' => ['999999999999'],
        ];
    }

    public function test_invalid_status_on_active_default_fails_validation_before_business_rule(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $default = PriceList::factory()->for($laboratory)->asDefault()->create();

        $response = $this->statusRequest($user, $laboratory, $default->id, ['status' => 'INVALID'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertNotSame(
            'The default price list cannot be deactivated.',
            $response->json('errors.status.0'),
        );
        $this->assertSame(PriceList::STATUS_ACTIVE, $default->fresh()->status);
        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_debug_false_representative_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        $guest = $this->patchJson('/api/v1/price-lists/1/status', [])->assertUnauthorized();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $regular = PriceList::factory()->for($labA)->create();
        $default = PriceList::factory()->for($labA)->asDefault()->create();
        $foreign = PriceList::factory()->for($labB)->create();
        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $missingContext = $this->actingAs($user, 'web')
            ->patchJson('/api/v1/price-lists/1/status', [])->assertBadRequest();
        $subscriptionFailure = $this->statusRequest($user, $withoutSubscription, 1, ['status' => 'INVALID'])
            ->assertForbidden();

        $responses = [
            $this->statusRequest($user, $labA, $regular->id, ['status' => 'inactive'])->assertOk(),
            $this->statusRequest($user, $labA, $regular->id, ['status' => 'inactive'])->assertOk(),
            $this->statusRequest($user, $labA, $regular->id, ['status' => 'INVALID'])->assertUnprocessable(),
            $this->statusRequest($user, $labA, $default->id, ['status' => 'inactive'])->assertUnprocessable(),
            $this->statusRequest($user, $labA, 999999999, ['status' => 'active'])->assertNotFound(),
            $this->statusRequest($user, $labA, $foreign->id, ['status' => 'active'])->assertNotFound(),
            $guest,
            $missingContext,
            $subscriptionFailure,
        ];

        foreach ($responses as $response) {
            foreach (['SQLSTATE', 'bindings', '/var/www', 'App\\Models', 'Illuminate\\', 'stack trace', 'constraint'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_runtime_controller_and_openapi_contract_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/exams') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/available-exams'))
            ->values();
        $statusRoute = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@updateStatus'));

        $this->assertCount(7, $routes);
        $this->assertSame([
            ['GET', 'HEAD'], ['POST'], ['GET', 'HEAD'], ['GET', 'HEAD'], ['PATCH'], ['PATCH'], ['PATCH'],
        ], $routes->map(fn ($route): array => $route->methods())->all());
        $this->assertSame('api/v1/price-lists/{priceList}/status', $statusRoute->uri());
        $this->assertSame('[0-9]+', $statusRoute->wheres['priceList']);
        $this->assertContains('saas', $statusRoute->middleware());

        $methods = collect((new ReflectionClass(PriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PriceListController::class)
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['active', 'index', 'setDefault', 'show', 'store', 'update', 'updateStatus'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}/status']['patch'];
        $input = $document['components']['schemas']['UpdatePriceListStatusInput'];
        $pathParameter = collect($operation['parameters'])->firstWhere('in', 'path');

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['status'], array_keys($input['properties']));
        $this->assertSame(['status'], $input['required']);
        $this->assertSame(['active', 'inactive'], $input['properties']['status']['enum']);
        $this->assertFalse($input['additionalProperties']);
        $this->assertSame('priceList', $pathParameter['name']);
        $this->assertTrue($pathParameter['required']);
        $this->assertSame('integer', $pathParameter['schema']['type']);
        $this->assertSame('int64', $pathParameter['schema']['format']);
        $this->assertSame(1, $pathParameter['schema']['minimum']);
        $this->assertSame('#/components/schemas/PriceListResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));

        $priceListOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/price-lists'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $examOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/laboratory-exams'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $this->assertSame(10, $priceListOperations);
        $this->assertSame(6, $examOperations);
        $this->assertArrayHasKey('/api/v1/price-lists/{priceList}/default', $document['paths']);
        $this->assertArrayHasKey('/api/v1/price-lists/active', $document['paths']);
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

    private function statusRequest(User $user, Laboratory $laboratory, int|string $priceList, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/price-lists/{$priceList}/status", $payload);
    }

    private function priceListRow(PriceList $priceList): object
    {
        return DB::table('price_lists')->where('id', $priceList->id)->firstOrFail();
    }
}
