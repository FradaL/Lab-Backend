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

class PriceListActiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-27 14:00:00', 'UTC'));
    }

    public function test_active_endpoint_returns_exact_lightweight_mixed_catalog(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $general = $this->priceList($laboratory, [
            'name' => 'General',
            'currency' => 'GTQ',
            'is_default' => true,
            'description' => 'Administrativa',
        ]);
        $agreement = $this->priceList($laboratory, [
            'name' => 'Convenio',
            'currency' => 'USD',
        ]);
        $this->priceList($laboratory, [
            'name' => 'Especial',
            'status' => PriceList::STATUS_INACTIVE,
        ]);

        $response = $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    $this->expectedItem($agreement),
                    $this->expectedItem($general),
                ],
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');

        foreach ($response->json('data') as $item) {
            $this->assertSame(['id', 'name', 'currency', 'is_default'], array_keys($item));
            $this->assertIsInt($item['id']);
            $this->assertIsString($item['name']);
            $this->assertIsString($item['currency']);
            $this->assertIsBool($item['is_default']);
            foreach (['description', 'status', 'laboratory_id', 'created_at', 'updated_at'] as $field) {
                $this->assertArrayNotHasKey($field, $item);
            }
        }
    }

    #[DataProvider('emptyCatalogProvider')]
    public function test_empty_or_only_inactive_catalog_returns_data_array(bool $createInactive): void
    {
        [$user, $laboratory] = $this->activeTenant();
        if ($createInactive) {
            $this->priceList($laboratory, ['status' => PriceList::STATUS_INACTIVE]);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /** @return array<string, array{bool}> */
    public static function emptyCatalogProvider(): array
    {
        return ['empty' => [false], 'only inactive' => [true]];
    }

    public function test_zero_default_and_active_default_states_are_reported_without_mutation(): void
    {
        [$user, $zeroDefaultLab] = $this->activeTenant();
        $first = $this->priceList($zeroDefaultLab, ['name' => 'A', 'is_default' => false]);
        $second = $this->priceList($zeroDefaultLab, ['name' => 'B', 'is_default' => false]);
        $before = [$first->attributesToArray(), $second->attributesToArray()];

        $response = $this->activeRequest($user, $zeroDefaultLab)->assertOk();
        $this->assertSame([false, false], $response->json('data.*.is_default'));
        $this->assertSame($this->sorted($before[0]), $this->sorted($first->fresh()->attributesToArray()));
        $this->assertSame($this->sorted($before[1]), $this->sorted($second->fresh()->attributesToArray()));

        [, $defaultLab] = $this->activeTenant($user);
        $default = $this->priceList($defaultLab, ['name' => 'Default', 'is_default' => true]);
        $regular = $this->priceList($defaultLab, ['name' => 'Regular']);
        $defaultResponse = $this->activeRequest($user, $defaultLab)->assertOk();
        $this->assertSame([$default->id, $regular->id], $defaultResponse->json('data.*.id'));
        $this->assertSame([true, false], $defaultResponse->json('data.*.is_default'));
    }

    public function test_inactive_default_is_excluded_and_not_repaired(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $visible = $this->priceList($laboratory, ['name' => 'Visible']);
        $inactiveDefault = $this->priceList($laboratory, [
            'name' => 'Legacy',
            'status' => PriceList::STATUS_INACTIVE,
            'is_default' => true,
        ]);
        $before = $inactiveDefault->attributesToArray();

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson(['data' => [$this->expectedItem($visible)]]);

        $this->assertSame($this->sorted($before), $this->sorted($inactiveDefault->fresh()->attributesToArray()));
    }

    public function test_ordering_is_name_then_id_and_never_default_first(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $zulu = $this->priceList($laboratory, ['name' => 'Zulu', 'currency' => 'ABC']);
        $alfa = $this->priceList($laboratory, ['name' => 'Alfa', 'currency' => 'EUR']);
        $middleDefault = $this->priceList($laboratory, [
            'name' => 'Medio',
            'currency' => 'GTQ',
            'is_default' => true,
        ]);

        $response = $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([$alfa->id, $middleDefault->id, $zulu->id], $response->json('data.*.id'));
        $this->assertSame(['Alfa', 'Medio', 'Zulu'], $response->json('data.*.name'));
    }

    public function test_case_sensitive_names_follow_database_order_without_transformation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $upper = $this->priceList($laboratory, ['name' => 'General']);
        $lower = $this->priceList($laboratory, ['name' => 'general']);

        $response = $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([$upper->id, $lower->id], $response->json('data.*.id'));
        $this->assertSame(['General', 'general'], $response->json('data.*.name'));
    }

    public function test_structurally_valid_currencies_are_returned_without_transformation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        foreach (['GTQ', 'USD', 'EUR', 'ABC'] as $index => $currency) {
            $this->priceList($laboratory, [
                'name' => sprintf('Lista %d', $index),
                'currency' => $currency,
            ]);
        }

        $response = $this->activeRequest($user, $laboratory)->assertOk();
        $this->assertSame(['GTQ', 'USD', 'EUR', 'ABC'], $response->json('data.*.currency'));
    }

    public function test_tenant_isolation_same_names_defaults_and_context_switching_are_symmetric(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $a = $this->priceList($labA, ['name' => 'General', 'is_default' => true]);
        $this->priceList($labA, ['name' => 'Oculta A', 'status' => PriceList::STATUS_INACTIVE]);
        $b = $this->priceList($labB, ['name' => 'General', 'is_default' => true]);
        $b2 = $this->priceList($labB, ['name' => 'Otra']);

        foreach ([[$labA, [$a]], [$labB, [$b, $b2]], [$labA, [$a]], [$labB, [$b, $b2]]] as [$laboratory, $expected]) {
            $response = $this->activeRequest($user, $laboratory)->assertOk();
            $this->assertSame(
                collect($expected)->sortBy([['name', 'asc'], ['id', 'asc']])->pluck('id')->values()->all(),
                $response->json('data.*.id'),
            );
            $this->assertTrue($response->json('data.0.is_default'));
        }
    }

    #[DataProvider('queryParameterProvider')]
    public function test_every_query_parameter_is_rejected_without_writes(string $parameter, string $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = $this->priceList($laboratory);
        $before = $priceList->attributesToArray();

        $this->activeRequest($user, $laboratory, [$parameter => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$parameter]);

        $this->assertSame($this->sorted($before), $this->sorted($priceList->fresh()->attributesToArray()));
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'search' => ['search', 'general'],
            'status' => ['status', 'active'],
            'currency' => ['currency', 'GTQ'],
            'is default' => ['is_default', 'true'],
            'sort' => ['sort', 'name'],
            'direction' => ['direction', 'asc'],
            'per page' => ['per_page', '100'],
            'page' => ['page', '1'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_multiple_query_parameters_are_rejected_together(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->activeRequest($user, $laboratory, ['search' => 'x', 'status' => 'active', 'page' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search', 'status', 'page']);
    }

    #[DataProvider('catalogSizeProvider')]
    public function test_catalog_uses_one_stable_select_without_count_relations_or_n_plus_one(int $count): void
    {
        [$user, $laboratory] = $this->activeTenant();
        foreach (range(1, $count) as $number) {
            $this->priceList($laboratory, ['name' => sprintf('Lista %03d', $number)]);
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'price_lists')) {
                $queries[] = $query;
            }
        });

        $this->activeRequest($user, $laboratory)->assertOk()->assertJsonCount($count, 'data');

        $selects = array_values(array_filter(
            $queries,
            fn (QueryExecuted $query): bool => str_starts_with(strtolower(ltrim($query->sql)), 'select'),
        ));
        $this->assertCount(1, $selects);
        $sql = strtolower($selects[0]->sql);
        $this->assertStringContainsString('"laboratory_id" = ?', $sql);
        $this->assertStringContainsString('"status" = ?', $sql);
        $this->assertStringContainsString('order by "name" asc, "id" asc', $sql);
        $this->assertStringNotContainsString('count(', $sql);
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertStringNotContainsString('lower(', $sql);
        $this->assertStringNotContainsString('"is_default" desc', $sql);
        $this->assertSame([$laboratory->id, PriceList::STATUS_ACTIVE], $selects[0]->bindings);
    }

    /** @return array<string, array{int}> */
    public static function catalogSizeProvider(): array
    {
        return ['one list' => [1], 'ten lists' => [10]];
    }

    public function test_get_performs_no_insert_update_or_delete(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->priceList($laboratory, ['is_default' => true]);
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', ltrim($query->sql)) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([], $writes);
    }

    public function test_guest_and_missing_context_are_rejected_before_catalog_query(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'price_lists')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson('/api/v1/price-lists/active')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/price-lists/active')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->assertSame([], $queries);
    }

    public function test_invalid_nonexistent_and_inactive_laboratory_contexts_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->getJson('/api/v1/price-lists/active')
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->getJson('/api/v1/price-lists/active')
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $laboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);
        $this->activeRequest($user, $laboratory)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');
    }

    public function test_membership_and_subscription_failures_precede_unknown_query_validation(): void
    {
        $user = User::factory()->create();
        $deniedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($deniedLab, ['is_active' => false]);
        $this->createCurrentSubscription($deniedLab);
        $this->activeRequest($user, $deniedLab, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $unsubscribedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($unsubscribedLab, ['is_active' => true]);
        $this->activeRequest($user, $unsubscribedLab, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_debug_false_representative_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $this->priceList($laboratory, ['name' => 'Visible']);
        $responses = [
            $this->getJson('/api/v1/price-lists/active')->assertUnauthorized(),
            $this->actingAs($user, 'web')->getJson('/api/v1/price-lists/active')->assertBadRequest(),
            $this->activeRequest($user, $laboratory)->assertOk(),
            $this->activeRequest($user, $laboratory, ['foo' => 'bar'])->assertUnprocessable(),
        ];
        [, $emptyLab] = $this->activeTenant($user);
        $responses[] = $this->activeRequest($user, $emptyLab)->assertOk()->assertExactJson(['data' => []]);
        $deniedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($deniedLab, ['is_active' => false]);
        $this->createCurrentSubscription($deniedLab);
        $responses[] = $this->activeRequest($user, $deniedLab)->assertForbidden();

        foreach ($responses as $response) {
            $this->assertNoLeakage($response);
        }
    }

    public function test_active_route_numeric_detail_controller_and_openapi_contracts_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists'))
            ->values();
        $this->assertCount(7, $routes);
        $this->assertSame([
            'api/v1/price-lists',
            'api/v1/price-lists',
            'api/v1/price-lists/active',
            'api/v1/price-lists/{priceList}',
            'api/v1/price-lists/{priceList}',
            'api/v1/price-lists/{priceList}/status',
            'api/v1/price-lists/{priceList}/default',
        ], $routes->map(fn ($route): string => $route->uri())->all());

        $active = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@active'));
        $show = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@show'));
        $this->assertNotNull($active);
        $this->assertNotNull($show);
        $this->assertLessThan($routes->search($show), $routes->search($active));
        $this->assertContains('saas', $active->middleware());
        $this->assertSame('[0-9]+', $show->wheres['priceList']);

        $methods = collect((new ReflectionClass(PriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PriceListController::class)
            ->pluck('name')->all();
        $this->assertSame(['index', 'store', 'active', 'show', 'update', 'updateStatus', 'setDefault'], $methods);

        [$user, $laboratory] = $this->activeTenant();
        $priceList = $this->priceList($laboratory);
        $this->activeRequest($user, $laboratory)->assertOk()->assertJsonPath('data.0.id', $priceList->id);
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/price-lists/{$priceList->id}")
            ->assertOk()->assertJsonPath('data.id', $priceList->id);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/active']['get'];
        $schema = $document['components']['schemas']['ActivePriceList'];
        $collection = $document['components']['schemas']['ActivePriceListCollection'];
        $priceListOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/price-lists'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $examOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/laboratory-exams'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(7, $priceListOperations);
        $this->assertSame(6, $examOperations);
        $this->assertCount(0, collect($operation['parameters'])->where('in', 'query'));
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertSame('#/components/schemas/ActivePriceListCollection', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame(['id', 'name', 'currency', 'is_default'], $schema['required']);
        $this->assertSame(['id', 'name', 'currency', 'is_default'], array_keys($schema['properties']));
        $this->assertSame('boolean', $schema['properties']['is_default']['type']);
        $this->assertSame('int64', $schema['properties']['id']['format']);
        $this->assertSame(75, $schema['properties']['name']['maxLength']);
        $this->assertSame('^[A-Z]{3}$', $schema['properties']['currency']['pattern']);
        $this->assertSame(['data'], $collection['required']);
        $this->assertSame('#/components/schemas/ActivePriceList', $collection['properties']['data']['items']['$ref']);
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
    private function priceList(Laboratory $laboratory, array $attributes = []): PriceList
    {
        return PriceList::factory()->for($laboratory)->create($attributes);
    }

    /** @param array<string, string> $query */
    private function activeRequest(User $user, Laboratory $laboratory, array $query = []): TestResponse
    {
        $uri = '/api/v1/price-lists/active';
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    /** @return array{id: int, name: string, currency: string, is_default: bool} */
    private function expectedItem(PriceList $priceList): array
    {
        return [
            'id' => $priceList->id,
            'name' => $priceList->name,
            'currency' => $priceList->currency,
            'is_default' => $priceList->is_default,
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function sorted(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }

    private function assertNoLeakage(TestResponse $response): void
    {
        $body = strtolower($response->getContent());
        foreach (['sqlstate', 'bindings', '/var/www', 'app\\models', 'illuminate\\', 'laboratory_id', 'stack trace', 'constraint'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
