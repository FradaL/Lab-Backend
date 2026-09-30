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

class PriceListIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
    }

    public function test_complete_single_tenant_http_lifecycle_preserves_invariants(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $generalResponse = $this->request($user, $laboratory, 'POST', '/api/v1/price-lists', [
            'name' => '  General  ',
            'description' => '  Tarifa general  ',
            'currency' => 'GTQ',
        ])->assertCreated();
        $generalId = $generalResponse->json('data.id');
        $generalResponse
            ->assertJsonPath('data.name', 'General')
            ->assertJsonPath('data.description', 'Tarifa general')
            ->assertJsonPath('data.currency', 'GTQ')
            ->assertJsonPath('data.status', PriceList::STATUS_ACTIVE)
            ->assertJsonPath('data.is_default', false);

        $agreementResponse = $this->request($user, $laboratory, 'POST', '/api/v1/price-lists', [
            'name' => 'Convenio',
            'description' => null,
            'currency' => 'USD',
        ])->assertCreated();
        $agreementId = $agreementResponse->json('data.id');

        $this->assertSame(0, PriceList::forLaboratory($laboratory)->where('is_default', true)->count());
        $this->assertSame(
            [$agreementId, $generalId],
            $this->request($user, $laboratory, 'GET', '/api/v1/price-lists')
                ->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$agreementId, $generalId],
            $this->request($user, $laboratory, 'GET', '/api/v1/price-lists/active')
                ->assertOk()->assertJsonMissingPath('links')->assertJsonMissingPath('meta')
                ->json('data.*.id'),
        );
        $this->request($user, $laboratory, 'GET', "/api/v1/price-lists/{$generalId}")
            ->assertOk()->assertJsonPath('data.status', PriceList::STATUS_ACTIVE);

        $this->travel(5)->minutes();
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$generalId}", [
            'currency' => 'USD',
        ])->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', PriceList::STATUS_ACTIVE)
            ->assertJsonPath('data.is_default', false);

        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$agreementId}/default")
            ->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame([$agreementId], $this->defaultIds($laboratory));
        $this->assertSame(
            [true, false],
            $this->request($user, $laboratory, 'GET', '/api/v1/price-lists/active')
                ->assertOk()->json('data.*.is_default'),
        );

        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$generalId}/status", [
            'status' => PriceList::STATUS_INACTIVE,
        ])->assertOk()->assertJsonPath('data.status', PriceList::STATUS_INACTIVE);
        $this->request($user, $laboratory, 'GET', "/api/v1/price-lists/{$generalId}")
            ->assertOk()->assertJsonPath('data.status', PriceList::STATUS_INACTIVE);
        $this->assertSame(
            [$agreementId, $generalId],
            $this->request($user, $laboratory, 'GET', '/api/v1/price-lists')
                ->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$agreementId],
            $this->request($user, $laboratory, 'GET', '/api/v1/price-lists/active')
                ->assertOk()->json('data.*.id'),
        );

        $this->travel(5)->minutes();
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$generalId}", [
            'description' => '  Lista reactivable  ',
            'currency' => 'EUR',
        ])->assertOk()
            ->assertJsonPath('data.description', 'Lista reactivable')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.status', PriceList::STATUS_INACTIVE)
            ->assertJsonPath('data.is_default', false);
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$generalId}/status", [
            'status' => PriceList::STATUS_ACTIVE,
        ])->assertOk();
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$generalId}/default")
            ->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertSame([$generalId], $this->defaultIds($laboratory));
        $this->assertFalse(PriceList::findOrFail($agreementId)->is_default);
        $active = $this->request($user, $laboratory, 'GET', '/api/v1/price-lists/active')->assertOk();
        $this->assertSame([$agreementId, $generalId], $active->json('data.*.id'));
        $this->assertSame([false, true], $active->json('data.*.is_default'));

        $general = PriceList::findOrFail($generalId);
        $this->assertTrue($general->laboratory->is($laboratory));
        $this->assertSame('EUR', $general->currency);
        $this->assertSame('2026-10-02 12:00:00', $general->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 12:10:00', $general->updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_default_replacement_protection_idempotency_and_write_counts_are_integrated(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $a = PriceList::factory()->for($laboratory)->asDefault()->create(['name' => 'A']);
        $b = PriceList::factory()->for($laboratory)->create(['name' => 'B']);
        $c = PriceList::factory()->for($laboratory)->create(['name' => 'C']);

        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$a->id}/status", [
            'status' => PriceList::STATUS_INACTIVE,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('price_lists', [
            'id' => $a->id,
            'status' => PriceList::STATUS_ACTIVE,
            'is_default' => true,
        ]);

        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$b->id}/default")->assertOk();
        $this->assertSame([$b->id], $this->defaultIds($laboratory));
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$a->id}/status", [
            'status' => PriceList::STATUS_INACTIVE,
        ])->assertOk();

        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$c->id}/default")->assertOk();
        $this->assertSame([$c->id], $this->defaultIds($laboratory));
        $before = $c->fresh()->attributesToArray();
        $updates = [];
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_contains($query->sql, 'price_lists') && str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $updates[] = $query->sql;
            }
        });
        $this->travel(5)->minutes();

        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$c->id}/default")
            ->assertOk()->assertJsonPath('data.is_default', true);
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$a->id}/status", [
            'status' => PriceList::STATUS_INACTIVE,
        ])->assertOk();

        $this->assertSame([], $updates);
        $this->assertSame($before, $c->fresh()->attributesToArray());
        $this->assertSame([$c->id], $this->defaultIds($laboratory));
    }

    public function test_legacy_inactive_default_remains_administrative_and_is_never_repaired_implicitly(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $legacy = PriceList::factory()->for($laboratory)->inactive()->asDefault()->create([
            'name' => 'Legacy',
            'description' => null,
            'currency' => 'GTQ',
        ]);

        $this->request($user, $laboratory, 'GET', '/api/v1/price-lists')
            ->assertOk()->assertJsonFragment(['id' => $legacy->id, 'status' => 'inactive', 'is_default' => true]);
        $this->request($user, $laboratory, 'GET', "/api/v1/price-lists/{$legacy->id}")
            ->assertOk()->assertJsonPath('data.is_default', true);
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$legacy->id}", [
            'name' => '  Legacy Edited  ',
            'description' => '  Actualizada  ',
            'currency' => 'USD',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Legacy Edited')
            ->assertJsonPath('data.status', PriceList::STATUS_INACTIVE)
            ->assertJsonPath('data.is_default', true);
        $this->request($user, $laboratory, 'GET', '/api/v1/price-lists/active')
            ->assertOk()->assertExactJson(['data' => []]);
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$legacy->id}/default")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $before = $legacy->fresh()->attributesToArray();
        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$legacy->id}/status", [
            'status' => PriceList::STATUS_INACTIVE,
        ])->assertOk();
        $this->assertSame($before, $legacy->fresh()->attributesToArray());

        $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$legacy->id}/status", [
            'status' => PriceList::STATUS_ACTIVE,
        ])->assertOk()
            ->assertJsonPath('data.status', PriceList::STATUS_ACTIVE)
            ->assertJsonPath('data.is_default', true);
        $this->request($user, $laboratory, 'GET', '/api/v1/price-lists/active')
            ->assertOk()->assertJsonPath('data.0.id', $legacy->id);
    }

    public function test_alternating_tenants_and_symmetric_target_matrix_never_cross_boundaries(): void
    {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $a1 = $this->createViaHttp($user, $labA, 'General', 'GTQ');
        $a2 = $this->createViaHttp($user, $labA, 'Convenio A', 'USD');
        $b1 = $this->createViaHttp($user, $labB, 'General', 'EUR');
        $b2 = $this->createViaHttp($user, $labB, 'Convenio B', 'ABC');

        $this->request($user, $labA, 'PATCH', "/api/v1/price-lists/{$a1}/default")->assertOk();
        $this->request($user, $labB, 'PATCH', "/api/v1/price-lists/{$b1}/default")->assertOk();
        $this->request($user, $labA, 'PATCH', "/api/v1/price-lists/{$a2}", ['description' => 'Sólo A'])->assertOk();
        $this->request($user, $labB, 'PATCH', "/api/v1/price-lists/{$b2}", ['description' => 'Sólo B'])->assertOk();
        $this->request($user, $labA, 'PATCH', "/api/v1/price-lists/{$a2}/status", ['status' => 'inactive'])->assertOk();
        $this->request($user, $labB, 'PATCH', "/api/v1/price-lists/{$b2}/status", ['status' => 'inactive'])->assertOk();

        foreach ([[$labA, [$a1, $a2]], [$labB, [$b1, $b2]], [$labA, [$a1, $a2]], [$labB, [$b1, $b2]]] as [$laboratory, $expected]) {
            $this->assertEqualsCanonicalizing(
                $expected,
                $this->request($user, $laboratory, 'GET', '/api/v1/price-lists')->assertOk()->json('data.*.id'),
            );
        }

        foreach ([[$labA, $b1], [$labB, $a1]] as [$context, $foreignId]) {
            $missingId = 999999999;
            $crossShow = $this->request($user, $context, 'GET', "/api/v1/price-lists/{$foreignId}")
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
            $missingShow = $this->request($user, $context, 'GET', "/api/v1/price-lists/{$missingId}")
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
            $this->assertSame($missingShow->getContent(), $crossShow->getContent());

            foreach ([
                ['', ['foo' => 'invalid']],
                ['/status', ['status' => 'invalid']],
                ['/default', ['is_default' => true]],
            ] as [$suffix, $payload]) {
                $cross = $this->request($user, $context, 'PATCH', "/api/v1/price-lists/{$foreignId}{$suffix}", $payload)
                    ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
                $missing = $this->request($user, $context, 'PATCH', "/api/v1/price-lists/{$missingId}{$suffix}", $payload)
                    ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
                $this->assertSame($missing->getContent(), $cross->getContent());
            }
        }

        $this->assertSame([$a1], $this->defaultIds($labA));
        $this->assertSame([$b1], $this->defaultIds($labB));
        $this->assertSame('Sólo A', PriceList::findOrFail($a2)->description);
        $this->assertSame('Sólo B', PriceList::findOrFail($b2)->description);
    }

    public function test_index_search_filters_sorting_and_pagination_remain_tenant_safe(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $general = PriceList::factory()->for($labA)->asDefault()->create([
            'name' => 'General',
            'description' => 'Tarifa principal',
            'currency' => 'GTQ',
        ]);
        $unicode = PriceList::factory()->for($labA)->create([
            'name' => 'Árbol Especial',
            'description' => 'Convenio único',
            'currency' => 'USD',
        ]);
        $special = PriceList::factory()->for($labA)->inactive()->create([
            'name' => "O'Reilly 100%",
            'description' => 'Literal seguro',
            'currency' => 'EUR',
        ]);
        PriceList::factory()->for($labB)->create([
            'name' => 'General Foreign',
            'description' => 'Tarifa principal secreta',
            'currency' => 'GTQ',
        ]);

        $this->assertSame(
            [$general->id],
            $this->indexRequest($user, $labA, ['search' => '  general  '])->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$general->id],
            $this->indexRequest($user, $labA, ['search' => 'PRINCIPAL'])->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$unicode->id],
            $this->indexRequest($user, $labA, ['search' => 'Árbol'])->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$special->id],
            $this->indexRequest($user, $labA, ['search' => "O'Reilly"])->assertOk()->json('data.*.id'),
        );
        $this->assertCount(3, $this->indexRequest($user, $labA, ['search' => '   '])->assertOk()->json('data'));
        $this->assertSame(
            [$general->id],
            $this->indexRequest($user, $labA, [
                'search' => 'Tarifa',
                'status' => 'active',
                'currency' => 'GTQ',
                'is_default' => 'true',
            ])->assertOk()->json('data.*.id'),
        );
        $this->assertEqualsCanonicalizing(
            [$unicode->id, $special->id],
            $this->indexRequest($user, $labA, ['is_default' => 'false'])->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$special->id, $general->id, $unicode->id],
            $this->indexRequest($user, $labA, ['sort' => 'currency', 'direction' => 'asc'])
                ->assertOk()->json('data.*.id'),
        );

        foreach (range(1, 16) as $number) {
            PriceList::factory()->for($labA)->create(['name' => sprintf('Lista %02d', $number)]);
        }
        $pageOne = $this->indexRequest($user, $labA)->assertOk();
        $this->assertCount(15, $pageOne->json('data'));
        $this->assertSame(19, $pageOne->json('meta.total'));
        $this->assertCount(4, $this->indexRequest($user, $labA, ['page' => '2'])->assertOk()->json('data'));
        $this->indexRequest($user, $labA, ['per_page' => '0'])->assertUnprocessable();
        $this->indexRequest($user, $labA, ['per_page' => '101'])->assertUnprocessable();
    }

    #[DataProvider('endpointProvider')]
    public function test_saas_pipeline_precedes_each_endpoint_contract(
        string $method,
        string $path,
        array $payload,
    ): void {
        $guest = $this->json($method, $path, $payload)->assertUnauthorized();
        $user = User::factory()->create();
        $missing = $this->actingAs($user, 'web')->json($method, $path, $payload)
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertNoLeakage($guest);
        $this->assertNoLeakage($missing);
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function endpointProvider(): array
    {
        return [
            'index' => ['GET', '/api/v1/price-lists?foo=bar', []],
            'store' => ['POST', '/api/v1/price-lists', ['foo' => 'bar']],
            'active' => ['GET', '/api/v1/price-lists/active?foo=bar', []],
            'show' => ['GET', '/api/v1/price-lists/1?foo=bar', []],
            'update' => ['PATCH', '/api/v1/price-lists/1', ['foo' => 'bar']],
            'status' => ['PATCH', '/api/v1/price-lists/1/status', ['status' => ' invalid ']],
            'set default' => ['PATCH', '/api/v1/price-lists/1/default?foo=bar', ['foo' => 'bar']],
        ];
    }

    public function test_routes_malformed_ids_controller_and_openapi_are_closed_and_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/exams') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/available-exams'))
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
        foreach ($routes as $route) {
            $this->assertContains('saas', $route->middleware());
            if (str_contains($route->uri(), '{priceList}')) {
                $this->assertSame('[0-9]+', $route->wheres['priceList']);
            }
        }

        $methods = collect((new ReflectionClass(PriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PriceListController::class)
            ->pluck('name')->all();
        $this->assertSame(['index', 'store', 'active', 'show', 'update', 'updateStatus', 'setDefault'], $methods);

        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        foreach (['abc', '1abc', '-1', '1.5'] as $malformed) {
            $this->request($user, $laboratory, 'GET', "/api/v1/price-lists/{$malformed}")->assertNotFound();
            $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$malformed}", ['name' => 'X'])->assertNotFound();
            $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$malformed}/status", ['status' => 'active'])->assertNotFound();
            $this->request($user, $laboratory, 'PATCH', "/api/v1/price-lists/{$malformed}/default")->assertNotFound();
        }
        $this->request($user, $laboratory, 'GET', '/api/v1/price-lists/active')->assertOk();
        $this->request($user, $laboratory, 'GET', "/api/v1/price-lists/{$priceList->id}")->assertOk();
        $this->request($user, $laboratory, 'PUT', "/api/v1/price-lists/{$priceList->id}", [])->assertMethodNotAllowed();
        $this->request($user, $laboratory, 'DELETE', "/api/v1/price-lists/{$priceList->id}")->assertMethodNotAllowed();

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/v1/price-lists' => ['get', 'post'],
            '/api/v1/price-lists/active' => ['get'],
            '/api/v1/price-lists/{priceList}' => ['get', 'patch'],
            '/api/v1/price-lists/{priceList}/status' => ['patch'],
            '/api/v1/price-lists/{priceList}/default' => ['patch'],
        ];
        foreach ($expectedOperations as $path => $operations) {
            $this->assertSame($operations, array_values(array_intersect(array_keys($document['paths'][$path]), $operations)));
        }
        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(10, $this->operationCount($document, '/api/v1/price-lists'));
        $this->assertSame(6, $this->operationCount($document, '/api/v1/laboratory-exams'));
        $this->assertSame(
            ['id', 'name', 'description', 'currency', 'is_default', 'status', 'created_at', 'updated_at'],
            array_keys($document['components']['schemas']['PriceList']['properties']),
        );
        $this->assertSame(
            ['id', 'name', 'currency', 'is_default'],
            array_keys($document['components']['schemas']['ActivePriceList']['properties']),
        );
        foreach (['CreatePriceListInput', 'UpdatePriceListInput', 'UpdatePriceListStatusInput'] as $schema) {
            $this->assertFalse($document['components']['schemas'][$schema]['additionalProperties']);
        }
        $activeOperation = $document['paths']['/api/v1/price-lists/active']['get'];
        $defaultOperation = $document['paths']['/api/v1/price-lists/{priceList}/default']['patch'];
        $this->assertCount(0, collect($activeOperation['parameters'])->where('in', 'query'));
        $this->assertArrayNotHasKey('requestBody', $activeOperation);
        $this->assertCount(0, collect($defaultOperation['parameters'])->where('in', 'query'));
        $this->assertArrayNotHasKey('requestBody', $defaultOperation);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [$user, $laboratory];
    }

    /** @param array<string, mixed> $payload */
    private function request(
        User $user,
        Laboratory $laboratory,
        string $method,
        string $path,
        array $payload = [],
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->json($method, $path, $payload);
    }

    private function createViaHttp(User $user, Laboratory $laboratory, string $name, string $currency): int
    {
        return (int) $this->request($user, $laboratory, 'POST', '/api/v1/price-lists', [
            'name' => $name,
            'currency' => $currency,
        ])->assertCreated()->json('data.id');
    }

    /** @param array<string, string> $query */
    private function indexRequest(User $user, Laboratory $laboratory, array $query = []): TestResponse
    {
        $path = '/api/v1/price-lists';
        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }

        return $this->request($user, $laboratory, 'GET', $path);
    }

    /** @return list<int> */
    private function defaultIds(Laboratory $laboratory): array
    {
        return PriceList::forLaboratory($laboratory)
            ->where('is_default', true)
            ->pluck('id')
            ->all();
    }

    /** @param array<string, mixed> $document */
    private function operationCount(array $document, string $prefix): int
    {
        return collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, $prefix))
            ->sum(fn (array $path): int => count(array_intersect_key(
                $path,
                array_flip(['get', 'post', 'patch', 'delete']),
            )));
    }

    private function assertNoLeakage(TestResponse $response): void
    {
        $body = strtolower($response->getContent());
        foreach (['sqlstate', 'bindings', '/var/www', 'app\\models', 'illuminate\\', 'laboratory_id', 'stack trace', 'constraint'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
