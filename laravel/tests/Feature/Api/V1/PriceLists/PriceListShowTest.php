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

class PriceListShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC'));
    }

    public function test_detail_returns_the_exact_contract_for_an_active_price_list(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Lista General',
            'description' => 'Tarifa administrativa.',
            'currency' => 'USD',
            'is_default' => false,
            'status' => PriceList::STATUS_ACTIVE,
        ]);

        $this->priceListRequest($user, $laboratory, $priceList->id)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $priceList->id,
                    'name' => 'Lista General',
                    'description' => 'Tarifa administrativa.',
                    'currency' => 'USD',
                    'is_default' => false,
                    'status' => PriceList::STATUS_ACTIVE,
                    'created_at' => '2026-09-29T12:00:00.000000Z',
                    'updated_at' => '2026-09-29T12:00:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.exam_prices')
            ->assertJsonMissingPath('data.commercial_entities')
            ->assertJsonMissingPath('data.users')
            ->assertJsonMissingPath('data.subscriptions')
            ->assertJsonMissingPath('data.branches')
            ->assertJsonMissingPath('data.links')
            ->assertJsonMissingPath('data.meta');
    }

    public function test_inactive_price_list_is_visible(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->inactive()->for($laboratory)->create();

        $this->priceListRequest($user, $laboratory, $priceList->id)
            ->assertOk()
            ->assertJsonPath('data.id', $priceList->id)
            ->assertJsonPath('data.status', PriceList::STATUS_INACTIVE);
    }

    public function test_cross_tenant_and_nonexistent_records_share_the_same_neutral_404(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $priceListB = PriceList::factory()->for($labB)->create(['name' => 'SECRET-SERUM']);

        $crossTenant = $this->priceListRequest($user, $labA, $priceListB->id)
            ->assertNotFound();
        $missing = $this->priceListRequest($user, $labA, 999999999)
            ->assertNotFound();

        $this->assertSame($crossTenant->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());

        foreach ([$crossTenant, $missing] as $response) {
            $this->assertResponseDoesNotLeak($response, ['SECRET-SERUM', 'PriceList', '999999999']);
        }
    }

    public function test_same_name_across_tenants_is_resolved_by_tenant_and_id(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $priceListA = PriceList::factory()->for($labA)->create(['name' => 'Sangre']);
        $priceListB = PriceList::factory()->for($labB)->create(['name' => 'Sangre']);

        $this->priceListRequest($user, $labA, $priceListA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $priceListA->id);
        $this->priceListRequest($user, $labA, $priceListB->id)->assertNotFound();
    }

    public function test_tenant_context_switching_has_no_residual_state(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $priceListA = PriceList::factory()->for($labA)->create(['name' => 'Sangre']);
        $priceListB = PriceList::factory()->for($labB)->create(['name' => 'Suero']);

        $this->priceListRequest($user, $labA, $priceListA->id)->assertOk();
        $this->priceListRequest($user, $labB, $priceListB->id)->assertOk();
        $this->priceListRequest($user, $labA, $priceListB->id)->assertNotFound();
        $this->priceListRequest($user, $labB, $priceListA->id)->assertNotFound();
        $this->priceListRequest($user, $labA, $priceListA->id)->assertOk();
        $this->priceListRequest($user, $labB, $priceListB->id)->assertOk();
    }

    public function test_lookup_queries_price_lists_once_with_tenant_and_id(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = $query;
            }
        });

        $this->priceListRequest($user, $laboratory, $priceList->id)->assertOk();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('laboratory_id', $queries[0]->sql);
        $this->assertStringContainsString('id', $queries[0]->sql);
        $this->assertContains($laboratory->id, $queries[0]->bindings);
        $this->assertContains($priceList->id, $queries[0]->bindings);
    }

    #[DataProvider('unexpectedQueryProvider')]
    public function test_query_parameters_are_rejected(string $query, string $field): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = $query;
            }
        });

        $response = $this->priceListRequest($user, $laboratory, $priceList->id, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        $this->assertCount(0, $queries);
    }

    /** @return array<string, array{string, string}> */
    public static function unexpectedQueryProvider(): array
    {
        return [
            'unknown parameter' => ['foo=bar', 'foo'],
            'include' => ['include=exams', 'include'],
            'with' => ['with=prices', 'with'],
            'tenant injection' => ['laboratory_id=999999', 'laboratory_id'],
        ];
    }

    #[DataProvider('invalidIdProvider')]
    public function test_non_numeric_route_identifiers_return_not_found(string $priceList): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = $query;
            }
        });

        $this->priceListRequest($user, $laboratory, $priceList)->assertNotFound();
        $this->assertCount(0, $queries);
    }

    /** @return array<string, array{string}> */
    public static function invalidIdProvider(): array
    {
        return [
            'letters' => ['abc'],
            'alphanumeric' => ['1abc'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    public function test_detail_is_read_only(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $before = $priceList->only(['name', 'status', 'updated_at']);
        $count = PriceList::query()->count();
        $writes = [];

        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)\\b/i', ltrim($query->sql)) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->travel(5)->minutes();
        $this->priceListRequest($user, $laboratory, $priceList->id)->assertOk();

        $priceList->refresh();
        $this->assertSame($before['name'], $priceList->name);
        $this->assertSame($before['status'], $priceList->status);
        $this->assertTrue($before['updated_at']->equalTo($priceList->updated_at));
        $this->assertSame([], $writes);
        $this->assertSame($count, PriceList::query()->count());
    }

    public function test_guest_is_rejected_without_leaking_price_list_data(): void
    {
        $priceList = PriceList::factory()->create(['name' => 'SECRET-GUEST']);
        $count = PriceList::query()->count();

        $response = $this->getJson("/api/v1/price-lists/{$priceList->id}")
            ->assertUnauthorized();

        $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        $this->assertSame($count, PriceList::query()->count());
    }

    public function test_missing_laboratory_context_is_rejected_without_leakage_or_writes(): void
    {
        $priceList = PriceList::factory()->create(['name' => 'SECRET-CONTEXT']);
        $count = PriceList::query()->count();

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->getJson("/api/v1/price-lists/{$priceList->id}")
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        $this->assertSame($count, PriceList::query()->count());
    }

    public function test_inactive_membership_is_rejected_without_leakage_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $priceList = PriceList::factory()->for($laboratory)->create(['name' => 'SECRET-INACTIVE']);
        $count = PriceList::query()->count();

        $response = $this->priceListRequest($user, $laboratory, $priceList->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        $this->assertSame($count, PriceList::query()->count());
    }

    public function test_nonexistent_membership_is_rejected_without_leakage_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        $priceList = PriceList::factory()->for($laboratory)->create(['name' => 'SECRET-NO-MEMBER']);
        $count = PriceList::query()->count();

        $response = $this->priceListRequest($user, $laboratory, $priceList->id, 'foo=bar')
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        $this->assertSame($count, PriceList::query()->count());
    }

    public function test_missing_subscription_is_rejected_without_leakage_or_writes(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $priceList = PriceList::factory()->for($laboratory)->create(['name' => 'SECRET-SUBSCRIPTION']);
        $count = PriceList::query()->count();

        $response = $this->priceListRequest($user, $laboratory, $priceList->id, 'foo=bar')
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        $this->assertSame($count, PriceList::query()->count());
    }

    public function test_create_then_detail_returns_the_persisted_price_list(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $created = $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/price-lists', ['name' => 'Plasma', 'currency' => 'GTQ'])
            ->assertCreated();

        $this->priceListRequest($user, $laboratory, $created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Plasma')
            ->assertJsonPath('data.status', PriceList::STATUS_ACTIVE);
    }

    #[DataProvider('administrativeStateProvider')]
    public function test_default_and_description_variants_are_visible(
        string $status,
        bool $isDefault,
        ?string $description,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'description' => $description,
            'is_default' => $isDefault,
            'status' => $status,
        ]);

        $response = $this->priceListRequest($user, $laboratory, $priceList->id)
            ->assertOk()
            ->assertJsonPath('data.description', $description)
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.is_default', $isDefault);

        $this->assertIsBool($response->json('data.is_default'));
    }

    /** @return array<string, array{string, bool, string|null}> */
    public static function administrativeStateProvider(): array
    {
        return [
            'active default' => [PriceList::STATUS_ACTIVE, true, 'Principal'],
            'active non-default' => [PriceList::STATUS_ACTIVE, false, 'General'],
            'inactive default' => [PriceList::STATUS_INACTIVE, true, 'Historical'],
            'null description' => [PriceList::STATUS_ACTIVE, false, null],
        ];
    }

    #[DataProvider('numericMissingIdProvider')]
    public function test_numeric_missing_ids_return_neutral_404(int $identifier): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();

        $this->priceListRequest($user, $laboratory, $identifier)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    /** @return array<string, array{int}> */
    public static function numericMissingIdProvider(): array
    {
        return [
            'zero' => [0],
            'large' => [999999999],
        ];
    }

    public function test_invalid_query_precedes_nonexistent_and_cross_tenant_lookup(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $foreign = PriceList::factory()->for($labB)->create();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = $query;
            }
        });

        $nonexistent = $this->priceListRequest($user, $labA, 999999999, 'foo=bar')
            ->assertUnprocessable();
        $crossTenant = $this->priceListRequest($user, $labA, $foreign->id, 'foo=bar')
            ->assertUnprocessable();

        $this->assertSame($nonexistent->json(), $crossTenant->json());
        $this->assertCount(0, $queries);
    }

    public function test_invalid_nonexistent_and_inactive_laboratory_contexts_precede_lookup(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        $priceList = PriceList::factory()->create(['name' => 'SECRET-CONTEXT-STATES']);
        $inactiveLab = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLab, ['is_active' => true]);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = $query;
            }
        });

        $invalid = $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->getJson("/api/v1/price-lists/{$priceList->id}?foo=bar")
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $nonexistent = $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->getJson("/api/v1/price-lists/{$priceList->id}?foo=bar")
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');
        $inactive = $this->priceListRequest($user, $inactiveLab, $priceList->id, 'foo=bar')
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $this->assertCount(0, $queries);
        foreach ([$invalid, $nonexistent, $inactive] as $response) {
            $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        }
    }

    public function test_pipeline_precedes_query_validation_and_price_list_lookup(): void
    {
        config(['app.debug' => false]);
        $priceList = PriceList::factory()->create(['name' => 'SECRET-PRECEDENCE']);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = $query;
            }
        });

        $guest = $this->getJson("/api/v1/price-lists/{$priceList->id}?foo=bar")
            ->assertUnauthorized();
        $missing = $this->actingAs(User::factory()->create(), 'web')
            ->getJson("/api/v1/price-lists/{$priceList->id}?foo=bar")
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $user = User::factory()->create();
        $deniedLab = Laboratory::factory()->create();
        $this->createCurrentSubscription($deniedLab);
        $denied = $this->priceListRequest($user, $deniedLab, $priceList->id, 'foo=bar')
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $this->assertCount(0, $queries);
        foreach ([$guest, $missing, $denied] as $response) {
            $this->assertResponseDoesNotLeak($response, [$priceList->name]);
        }
    }

    public function test_membership_denial_and_cross_tenant_resource_are_distinct(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $allowedLab] = $this->activeTenant($user);
        $deniedLab = Laboratory::factory()->create();
        $this->createCurrentSubscription($deniedLab);
        $foreign = PriceList::factory()->for($deniedLab)->create();

        $this->priceListRequest($user, $deniedLab, $foreign->id)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $this->priceListRequest($user, $allowedLab, $foreign->id)
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_route_controller_and_openapi_contracts_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists'))
            ->values();
        $show = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@show'));

        $this->assertCount(7, $routes);
        $this->assertSame([['GET', 'HEAD'], ['POST'], ['GET', 'HEAD'], ['GET', 'HEAD'], ['PATCH'], ['PATCH'], ['PATCH']], $routes->map(fn ($route): array => $route->methods())->all());
        $this->assertSame('api/v1/price-lists/{priceList}', $show->uri());
        $this->assertSame('[0-9]+', $show->wheres['priceList']);
        $this->assertContains('saas', $show->middleware());

        $methods = collect((new ReflectionClass(PriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PriceListController::class)
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['active', 'index', 'setDefault', 'show', 'store', 'update', 'updateStatus'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}']['get'];
        $priceListOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/price-lists'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $examOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/laboratory-exams'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $pathParameter = collect($operation['parameters'])->firstWhere('in', 'path');

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(7, $priceListOperations);
        $this->assertSame(6, $examOperations);
        $this->assertSame('priceList', $pathParameter['name']);
        $this->assertTrue($pathParameter['required']);
        $this->assertSame('integer', $pathParameter['schema']['type']);
        $this->assertSame('int64', $pathParameter['schema']['format']);
        $this->assertSame(1, $pathParameter['schema']['minimum']);
        $this->assertCount(0, collect($operation['parameters'])->where('in', 'query'));
        $this->assertSame('#/components/schemas/PriceListResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertStringContainsString('no pertenece al laboratorio actual', $operation['responses']['404']['description']);
        $this->assertArrayHasKey('/api/v1/price-lists/{priceList}/status', $document['paths']);
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

    private function priceListRequest(
        User $user,
        Laboratory $laboratory,
        int|string $priceList,
        string $query = '',
    ): TestResponse {
        $suffix = $query === '' ? '' : "?{$query}";

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/price-lists/{$priceList}{$suffix}");
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
