<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Http\Controllers\Api\V1\CommercialClientController;
use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class CommercialClientActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_endpoint_returns_only_active_clients_in_exact_lightweight_shape(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $zeta = $this->commercialClient($laboratory, [
            'name' => 'Zeta Seguros',
            'type' => CommercialClient::TYPE_INSURANCE,
            'tax_id' => '1234567-8',
            'phone' => '+502 2222-3333',
            'email' => 'zeta@example.test',
            'address' => 'Ciudad',
            'notes' => 'Dato administrativo',
        ]);
        $alpha = $this->commercialClient($laboratory, [
            'name' => 'Alpha Empresa',
            'type' => CommercialClient::TYPE_COMPANY,
        ]);
        $this->commercialClient($laboratory, [
            'name' => 'Beta Inactiva',
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);

        $response = $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    $this->expectedItem($alpha),
                    $this->expectedItem($zeta),
                ],
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');

        foreach ($response->json('data') as $item) {
            $this->assertSame(['id', 'name', 'type'], array_keys($item));
            foreach (['tax_id', 'phone', 'email', 'address', 'notes', 'status', 'created_at', 'updated_at', 'laboratory_id', 'price_list_id'] as $field) {
                $this->assertArrayNotHasKey($field, $item);
            }
        }
    }

    #[DataProvider('emptyCatalogProvider')]
    public function test_empty_or_inactive_only_catalog_returns_empty_data(bool $createInactive): void
    {
        [$user, $laboratory] = $this->activeTenant();
        if ($createInactive) {
            $this->commercialClient($laboratory, ['status' => CommercialClient::STATUS_INACTIVE]);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /** @return array<string, array{bool}> */
    public static function emptyCatalogProvider(): array
    {
        return [
            'empty' => [false],
            'inactive only' => [true],
        ];
    }

    public function test_tenant_isolation_duplicate_names_and_header_switching_are_symmetric(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $a = $this->commercialClient($labA, ['name' => 'Entidad Compartida']);
        $this->commercialClient($labA, ['name' => 'Oculta A', 'status' => CommercialClient::STATUS_INACTIVE]);
        $b = $this->commercialClient($labB, ['name' => 'Entidad Compartida']);
        $b2 = $this->commercialClient($labB, ['name' => 'Otra Entidad']);

        foreach ([[$labA, [$a]], [$labB, [$b, $b2]], [$labA, [$a]]] as [$laboratory, $expected]) {
            $response = $this->activeRequest($user, $laboratory)->assertOk();
            $this->assertSame(
                collect($expected)->sortBy([['name', 'asc'], ['id', 'asc']])->pluck('id')->values()->all(),
                $response->json('data.*.id'),
            );
        }
    }

    public function test_all_canonical_types_are_returned_without_filtering_or_translation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $types = [
            CommercialClient::TYPE_INSURANCE,
            CommercialClient::TYPE_COMPANY,
            CommercialClient::TYPE_AGREEMENT,
            CommercialClient::TYPE_OTHER,
        ];
        foreach ($types as $index => $type) {
            $this->commercialClient($laboratory, [
                'name' => sprintf('Entidad %d', $index),
                'type' => $type,
            ]);
        }

        $response = $this->activeRequest($user, $laboratory)->assertOk();
        $this->assertSame($types, $response->json('data.*.type'));
    }

    #[DataProvider('queryParameterProvider')]
    public function test_every_query_parameter_is_rejected_without_mutation(string $parameter, string $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = $this->commercialClient($laboratory);
        $before = $client->attributesToArray();

        $this->activeRequest($user, $laboratory, [$parameter => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$parameter]);

        $this->assertSame($this->sorted($before), $this->sorted($client->fresh()->attributesToArray()));
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'unknown' => ['foo', 'bar'],
            'status' => ['status', 'inactive'],
            'type' => ['type', 'insurance'],
            'search' => ['search', 'empresa'],
            'page' => ['page', '1'],
            'per page' => ['per_page', '10'],
            'sort' => ['sort', 'name'],
            'direction' => ['direction', 'desc'],
            'tenant injection' => ['laboratory_id', '999'],
        ];
    }

    public function test_get_never_synthesizes_particular_or_writes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = $this->commercialClient($laboratory, ['name' => 'Entidad Real']);
        $before = $client->attributesToArray();
        $count = CommercialClient::query()->count();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', ltrim($query->sql)) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonMissing(['name' => 'Particular']);

        $this->assertSame([], $writes);
        $this->assertSame($count, CommercialClient::query()->count());
        $this->assertSame($this->sorted($before), $this->sorted($client->fresh()->attributesToArray()));
        $this->assertFalse(CommercialClient::query()->where('name', 'Particular')->exists());
    }

    #[DataProvider('catalogSizeProvider')]
    public function test_catalog_uses_one_lightweight_constant_query_without_relations(int $count): void
    {
        [$user, $laboratory] = $this->activeTenant();
        foreach (range(1, $count) as $number) {
            $this->commercialClient($laboratory, ['name' => sprintf('Entidad %03d', $number)]);
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'commercial_clients')) {
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
        $this->assertStringContainsString('select "id", "name", "type"', $sql);
        $this->assertStringContainsString('"laboratory_id" = ?', $sql);
        $this->assertStringContainsString('"status" = ?', $sql);
        $this->assertStringContainsString('order by "name" asc, "id" asc', $sql);
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertStringNotContainsString('count(', $sql);
        $this->assertSame([$laboratory->id, CommercialClient::STATUS_ACTIVE], $selects[0]->bindings);
    }

    /** @return array<string, array{int}> */
    public static function catalogSizeProvider(): array
    {
        return [
            'one row' => [1],
            'one hundred rows' => [100],
        ];
    }

    public function test_catalog_performs_no_queries_to_unrelated_domains(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->commercialClient($laboratory);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->activeRequest($user, $laboratory)->assertOk();

        foreach (['price_lists', 'price_list_exams', 'patients', 'doctors', 'branches', 'orders'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }

    public function test_saas_pipeline_precedes_catalog_and_query_validation(): void
    {
        $this->getJson('/api/v1/commercial-clients/active?foo=bar')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/commercial-clients/active?foo=bar')
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->getJson('/api/v1/commercial-clients/active?foo=bar')
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->getJson('/api/v1/commercial-clients/active?foo=bar')
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $inactiveLaboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLaboratory, ['is_active' => true]);
        $this->createCurrentSubscription($inactiveLaboratory);
        $this->activeRequest($user, $inactiveLaboratory, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        $this->createCurrentSubscription($inactiveMembership);
        $this->activeRequest($user, $inactiveMembership, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->activeRequest($user, $withoutSubscription, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_active_route_runtime_controller_and_openapi_contracts_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getActionName(), CommercialClientController::class.'@'))
            ->values();
        $active = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@active'));
        $show = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@show'));

        $this->assertCount(6, $routes);
        $this->assertSame([
            'api/v1/commercial-clients',
            'api/v1/commercial-clients',
            'api/v1/commercial-clients/active',
            'api/v1/commercial-clients/{commercialClient}',
            'api/v1/commercial-clients/{commercialClient}',
            'api/v1/commercial-clients/{commercialClient}/status',
        ], $routes->map(fn ($route): string => $route->uri())->all());
        $this->assertNotNull($active);
        $this->assertNotNull($show);
        $this->assertLessThan($routes->search($show), $routes->search($active));
        $this->assertContains('saas', $active->middleware());
        $this->assertSame('[0-9]+', $show->wheres['commercialClient']);

        $methods = collect((new ReflectionClass(CommercialClientController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === CommercialClientController::class)
            ->pluck('name')->all();
        $this->assertSame(['index', 'store', 'active', 'show', 'update', 'updateStatus'], $methods);

        [$user, $laboratory] = $this->activeTenant();
        $client = $this->commercialClient($laboratory);
        $this->activeRequest($user, $laboratory)
            ->assertOk()->assertJsonPath('data.0.id', $client->id);
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/commercial-clients/{$client->id}")
            ->assertOk()->assertJsonPath('data.id', $client->id);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $document['paths']['/api/v1/commercial-clients/active'];
        $operation = $path['get'];
        $schema = $document['components']['schemas']['ActiveCommercialClient'];
        $collection = $document['components']['schemas']['ActiveCommercialClientCollection'];
        $verbs = array_flip(['get', 'post', 'put', 'patch', 'delete']);
        $operations = collect($document['paths'])->flatMap(
            fn (array $item): array => array_values(array_intersect_key($item, $verbs)),
        );

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['get'], array_keys($path));
        $this->assertCount(0, collect($operation['parameters'])->where('in', 'query'));
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertSame([200, 400, 401, 403, 422], array_keys($operation['responses']));
        $this->assertSame('#/components/schemas/ActiveCommercialClientCollection', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame(['id', 'name', 'type'], $schema['required']);
        $this->assertSame(['id', 'name', 'type'], array_keys($schema['properties']));
        $this->assertSame(['insurance', 'company', 'agreement', 'other'], $schema['properties']['type']['enum']);
        $this->assertSame(['data'], $collection['required']);
        $this->assertSame('#/components/schemas/ActiveCommercialClient', $collection['properties']['data']['items']['$ref']);
        $this->assertCount(67, $operations);
        $this->assertCount(6, $operations->filter(
            fn (array $item): bool => in_array('Commercial Clients', $item['tags'] ?? [], true),
        ));
        $this->assertCount(5, $operations->filter(
            fn (array $item): bool => in_array('Exam Prices', $item['tags'] ?? [], true),
        ));
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
    private function commercialClient(Laboratory $laboratory, array $attributes = []): CommercialClient
    {
        return CommercialClient::factory()->for($laboratory)->create($attributes);
    }

    /** @param array<string, string> $query */
    private function activeRequest(User $user, Laboratory $laboratory, array $query = []): TestResponse
    {
        $uri = '/api/v1/commercial-clients/active';
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    /** @return array{id: int, name: string, type: string} */
    private function expectedItem(CommercialClient $commercialClient): array
    {
        return [
            'id' => $commercialClient->id,
            'name' => $commercialClient->name,
            'type' => $commercialClient->type,
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
}
