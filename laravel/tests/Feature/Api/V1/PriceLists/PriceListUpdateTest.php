<?php

namespace Tests\Feature\Api\V1\PriceLists;

use App\Http\Controllers\Api\V1\PriceListController;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PriceListUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC'));
    }

    public function test_partial_name_update_returns_exact_resource_and_preserves_omitted_fields(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->asDefault()->inactive()->create([
            'name' => 'Original',
            'description' => 'Descripción original',
            'currency' => 'GTQ',
        ]);
        $this->travel(5)->minutes();

        $this->updateRequest($user, $laboratory, $priceList->id, ['name' => '  lista Mixta  '])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $priceList->id,
                'name' => 'lista Mixta',
                'description' => 'Descripción original',
                'currency' => 'GTQ',
                'is_default' => true,
                'status' => PriceList::STATUS_INACTIVE,
                'created_at' => '2026-09-29T12:00:00.000000Z',
                'updated_at' => '2026-09-29T12:05:00.000000Z',
            ]])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.exam_prices');

        $this->assertDatabaseHas('price_lists', [
            'id' => $priceList->id,
            'laboratory_id' => $laboratory->id,
            'name' => 'lista Mixta',
            'description' => 'Descripción original',
            'currency' => 'GTQ',
            'is_default' => true,
            'status' => PriceList::STATUS_INACTIVE,
        ]);
    }

    public function test_all_editable_fields_update_atomically_and_whitespace_description_becomes_null(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'description' => 'Original',
            'currency' => 'GTQ',
        ]);

        $this->updateRequest($user, $laboratory, $priceList->id, [
            'name' => '  Preferencial  ',
            'description' => '   ',
            'currency' => 'USD',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Preferencial')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.currency', 'USD');

        $this->assertDatabaseHas('price_lists', [
            'id' => $priceList->id,
            'name' => 'Preferencial',
            'description' => null,
            'currency' => 'USD',
        ]);
    }

    #[DataProvider('partialPayloadProvider')]
    public function test_each_partial_payload_preserves_every_omitted_field(array $payload, array $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'description' => 'Original description',
            'currency' => 'GTQ',
        ]);

        $this->updateRequest($user, $laboratory, $priceList->id, $payload)->assertOk();

        $this->assertDatabaseHas('price_lists', ['id' => $priceList->id, ...$expected]);
    }

    public static function partialPayloadProvider(): array
    {
        return [
            'name' => [['name' => 'New Name'], ['name' => 'New Name', 'description' => 'Original description', 'currency' => 'GTQ']],
            'description' => [['description' => ' New description '], ['name' => 'Original', 'description' => 'New description', 'currency' => 'GTQ']],
            'nullable description' => [['description' => null], ['name' => 'Original', 'description' => null, 'currency' => 'GTQ']],
            'currency' => [['currency' => 'EUR'], ['name' => 'Original', 'description' => 'Original description', 'currency' => 'EUR']],
            'name and description' => [['name' => 'New Name', 'description' => ' New description '], ['name' => 'New Name', 'description' => 'New description', 'currency' => 'GTQ']],
            'name and currency' => [['name' => 'New Name', 'currency' => 'USD'], ['name' => 'New Name', 'description' => 'Original description', 'currency' => 'USD']],
            'description and currency' => [['description' => null, 'currency' => 'ABC'], ['name' => 'Original', 'description' => null, 'currency' => 'ABC']],
        ];
    }

    #[DataProvider('stateProvider')]
    public function test_update_never_changes_status_or_default(bool $isDefault, string $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'is_default' => $isDefault,
            'status' => $status,
        ]);

        $this->updateRequest($user, $laboratory, $priceList->id, ['currency' => 'USD'])
            ->assertOk()
            ->assertJsonPath('data.is_default', $isDefault)
            ->assertJsonPath('data.status', $status);
    }

    public static function stateProvider(): array
    {
        return [
            'active regular' => [false, PriceList::STATUS_ACTIVE],
            'active default' => [true, PriceList::STATUS_ACTIVE],
            'inactive regular' => [false, PriceList::STATUS_INACTIVE],
            'inactive default' => [true, PriceList::STATUS_INACTIVE],
        ];
    }

    #[DataProvider('invalidNameProvider')]
    public function test_name_validation_is_strict_and_atomic(mixed $name): void
    {
        $this->assertInvalidField(['name' => $name], 'name');
    }

    public static function invalidNameProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'integer' => [123],
            'boolean' => [false],
            'object' => [(object) ['name' => 'value']],
            'array' => [['name']],
            'too long' => [str_repeat('a', 76)],
        ];
    }

    #[DataProvider('invalidDescriptionProvider')]
    public function test_description_validation_is_strict_and_atomic(mixed $description): void
    {
        $this->assertInvalidField(['description' => $description], 'description');
    }

    public static function invalidDescriptionProvider(): array
    {
        return [
            'integer' => [123],
            'boolean' => [false],
            'object' => [(object) ['name' => 'value']],
            'array' => [['description']],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    #[DataProvider('invalidCurrencyProvider')]
    public function test_currency_requires_exact_raw_three_uppercase_letters(mixed $currency): void
    {
        $this->assertInvalidField(['currency' => $currency], 'currency');
    }

    public static function invalidCurrencyProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'lowercase' => ['gtq'],
            'mixed case' => ['Gtq'],
            'short' => ['GT'],
            'long' => ['GTQQ'],
            'digits' => ['G1Q'],
            'leading whitespace' => [' GTQ'],
            'trailing whitespace' => ['GTQ '],
            'integer' => [123],
            'boolean' => [false],
            'object' => [(object) ['name' => 'value']],
            'array' => [['GTQ']],
        ];
    }

    #[DataProvider('prohibitedFieldProvider')]
    public function test_prohibited_and_unknown_fields_fail_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create(['name' => 'Original']);

        $this->updateRequest($user, $laboratory, $priceList->id, [
            'name' => 'Would change',
            $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame('Original', $priceList->fresh()->name);
    }

    public static function prohibitedFieldProvider(): array
    {
        return [
            'status' => ['status', PriceList::STATUS_INACTIVE],
            'is_default' => ['is_default', true],
            'laboratory_id' => ['laboratory_id', 999],
            'id' => ['id', 999],
            'created_at' => ['created_at', '2020-01-01'],
            'updated_at' => ['updated_at', '2020-01-01'],
            'exam_prices' => ['exam_prices', []],
            'branch_id' => ['branch_id', 1],
            'user_id' => ['user_id', 1],
            'prices' => ['prices', []],
            'price' => ['price', 10],
            'exams' => ['exams', []],
            'exam_id' => ['exam_id', 1],
            'laboratory_exam_id' => ['laboratory_exam_id', 1],
            'commercial_entity_id' => ['commercial_entity_id', 1],
            'unknown' => ['future_field', 'value'],
        ];
    }

    public function test_empty_and_unknown_only_payloads_are_rejected_without_writes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $before = $this->priceListRow($priceList);

        $this->updateRequest($user, $laboratory, $priceList->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['payload']);
        $this->updateRequest($user, $laboratory, $priceList->id, ['foo' => 'bar'])
            ->assertUnprocessable()->assertJsonValidationErrors(['foo', 'payload']);

        $this->assertEquals($before, $this->priceListRow($priceList));
    }

    public function test_name_uniqueness_is_exact_tenant_scoped_and_ignores_current_row(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $priceList = PriceList::factory()->for($labA)->create(['name' => 'Original']);
        PriceList::factory()->for($labA)->create(['name' => 'Taken']);
        $this->updateRequest($user, $labA, $priceList->id, ['name' => ' Taken '])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        PriceList::factory()->for($labB)->create(['name' => 'Cross tenant']);

        $this->updateRequest($user, $labA, $priceList->id, ['name' => 'Taken'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->updateRequest($user, $labA, $priceList->id, ['name' => 'taken'])
            ->assertOk()->assertJsonPath('data.name', 'taken');
        $this->updateRequest($user, $labA, $priceList->id, ['name' => 'Cross tenant'])
            ->assertOk()->assertJsonPath('data.name', 'Cross tenant');
        $this->updateRequest($user, $labA, $priceList->id, ['name' => ' Cross tenant '])
            ->assertOk()->assertJsonPath('data.name', 'Cross tenant');
    }

    public function test_cross_tenant_and_nonexistent_ids_return_same_neutral_404_before_validation(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $foreign = PriceList::factory()->for($labB)->create(['name' => 'Secret']);
        $invalid = ['name' => '', 'status' => 'inactive'];

        $cross = $this->updateRequest($user, $labA, $foreign->id, $invalid)
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $missing = $this->updateRequest($user, $labA, 999999999, $invalid)
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);

        $this->assertSame($cross->getContent(), $missing->getContent());
        $this->assertSame('Secret', $foreign->fresh()->name);
    }

    public function test_noop_updates_do_not_issue_update_or_uniqueness_queries_and_keep_timestamp(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'description' => 'Description',
            'currency' => 'GTQ',
        ]);
        $timestamp = $priceList->updated_at->toISOString();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $this->travel(10)->minutes();
        $this->updateRequest($user, $laboratory, $priceList->id, [
            'name' => ' Original ',
            'description' => ' Description ',
            'currency' => 'GTQ',
        ])->assertOk()->assertJsonPath('data.updated_at', $timestamp);

        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select count(*)')));
    }

    #[DataProvider('dirtyUpdateProvider')]
    public function test_dirty_updates_issue_at_most_one_update_without_refresh(array $payload, bool $expectsUniqueQuery): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'description' => 'Original',
            'currency' => 'GTQ',
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $this->updateRequest($user, $laboratory, $priceList->id, $payload)->assertOk();

        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        $uniqueQueries = array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select count(*)'));
        $this->assertCount($expectsUniqueQuery ? 1 : 0, $uniqueQueries);
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select *')));
    }

    public static function dirtyUpdateProvider(): array
    {
        return [
            'name' => [['name' => 'Changed'], true],
            'description' => [['description' => 'Changed'], false],
            'currency' => [['currency' => 'USD'], false],
        ];
    }

    public function test_unique_race_maps_to_name_validation_without_partial_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'description' => 'Original',
        ]);

        PriceList::updating(function (PriceList $updating): void {
            DB::table('price_lists')->insert([
                'laboratory_id' => $updating->laboratory_id,
                'name' => 'RACE',
                'description' => null,
                'currency' => 'GTQ',
                'is_default' => false,
                'status' => PriceList::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->updateRequest($user, $laboratory, $priceList->id, [
                'name' => 'RACE',
                'description' => 'Must not persist',
            ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

            if (DB::getDriverName() === 'pgsql') {
                return;
            }

            $this->assertDatabaseHas('price_lists', [
                'id' => $priceList->id,
                'name' => 'Original',
                'description' => 'Original',
            ]);
        } finally {
            PriceList::flushEventListeners();
        }
    }

    public function test_unrelated_query_exception_is_rethrown(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();

        PriceList::updating(function (PriceList $updating): void {
            DB::table('price_lists')->where('id', $updating->id)->update(['status' => null]);
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->updateRequest($user, $laboratory, $priceList->id, ['name' => 'Changed']);
        } finally {
            PriceList::flushEventListeners();
        }
    }

    public function test_saas_pipeline_precedes_lookup_and_validation_without_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $priceList = PriceList::factory()->for($laboratory)->create(['name' => 'Original']);

        $this->patchJson("/api/v1/price-lists/{$priceList->id}", [])->assertUnauthorized();
        $this->actingAs($user, 'web')->patchJson("/api/v1/price-lists/{$priceList->id}", [])
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->createCurrentSubscription($laboratory);
        $this->updateRequest($user, $laboratory, $priceList->id, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        DB::table('subscriptions')->delete();
        $this->updateRequest($user, $laboratory, $priceList->id, [])->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertSame('Original', $priceList->fresh()->name);
    }

    public function test_text_boundaries_and_structural_currency_are_accepted(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $name = str_repeat('á', 75);
        $description = str_repeat('x', 255);

        $this->updateRequest($user, $laboratory, $priceList->id, [
            'name' => $name,
            'description' => $description,
            'currency' => 'ABC',
        ])->assertOk()
            ->assertJsonPath('data.name', $name)
            ->assertJsonPath('data.description', $description)
            ->assertJsonPath('data.currency', 'ABC');
    }

    public function test_whitespace_description_is_a_noop_when_description_is_already_null(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create(['description' => null]);
        $updatedAt = $priceList->updated_at->toISOString();
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_contains($query->sql, 'price_lists') && str_starts_with(strtolower($query->sql), 'update ')) {
                $updates++;
            }
        });

        $this->travel(5)->minutes();
        $this->updateRequest($user, $laboratory, $priceList->id, ['description' => '   '])
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.updated_at', $updatedAt);
        $this->assertSame(0, $updates);
    }

    public function test_tenant_isolation_is_symmetric_during_context_switching(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $listA = PriceList::factory()->for($labA)->create(['name' => 'A']);
        $listB = PriceList::factory()->for($labB)->create(['name' => 'B']);

        $this->updateRequest($user, $labA, $listA->id, ['name' => 'A updated'])->assertOk();
        $this->updateRequest($user, $labB, $listB->id, ['name' => 'B updated'])->assertOk();
        $this->updateRequest($user, $labA, $listB->id, ['name' => 'Blocked'])->assertNotFound();
        $this->updateRequest($user, $labB, $listA->id, ['name' => 'Blocked'])->assertNotFound();

        $this->assertSame('A updated', $listA->fresh()->name);
        $this->assertSame('B updated', $listB->fresh()->name);
    }

    public function test_invalid_nonexistent_and_inactive_contexts_precede_payload_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->patchJson('/api/v1/price-lists/1', [])->assertBadRequest();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->patchJson('/api/v1/price-lists/1', [])->assertNotFound();

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->updateRequest($user, $inactive, 1, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');
    }

    public function test_debug_false_representative_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $responses = [
            $this->updateRequest($user, $laboratory, $priceList->id, ['name' => 'Changed'])->assertOk(),
            $this->updateRequest($user, $laboratory, $priceList->id, [])->assertUnprocessable(),
            $this->updateRequest($user, $laboratory, 999999999, ['name' => ''])->assertNotFound(),
        ];

        foreach ($responses as $response) {
            foreach (['SQLSTATE', 'bindings', '/var/www', 'App\\Models', 'stack trace', 'constraint'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_runtime_controller_and_openapi_contract_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/exams') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/available-exams'))
            ->values();
        $patch = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@update'));

        $this->assertCount(7, $routes);
        $this->assertSame([['GET', 'HEAD'], ['POST'], ['GET', 'HEAD'], ['GET', 'HEAD'], ['PATCH'], ['PATCH'], ['PATCH']], $routes->map(fn ($route): array => $route->methods())->all());
        $this->assertSame('[0-9]+', $patch->wheres['priceList']);
        $this->assertContains('saas', $patch->middleware());

        $methods = collect((new ReflectionClass(PriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PriceListController::class)
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['active', 'index', 'setDefault', 'show', 'store', 'update', 'updateStatus'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}']['patch'];
        $input = $document['components']['schemas']['UpdatePriceListInput'];

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['name', 'description', 'currency'], array_keys($input['properties']));
        $this->assertArrayNotHasKey('required', $input);
        $this->assertSame(1, $input['minProperties']);
        $this->assertFalse($input['additionalProperties']);
        $this->assertSame('^[A-Z]{3}$', $input['properties']['currency']['pattern']);
        $this->assertSame('#/components/schemas/UpdatePriceListInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
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
    }

    private function assertInvalidField(array $payload, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Original',
            'description' => 'Original',
            'currency' => 'GTQ',
        ]);
        $before = $this->priceListRow($priceList);

        $this->updateRequest($user, $laboratory, $priceList->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->priceListRow($priceList));
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

    private function updateRequest(User $user, Laboratory $laboratory, int|string $priceList, array $payload): TestResponse
    {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'price_lists.update');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/price-lists/{$priceList}", $payload);
    }

    private function priceListRow(PriceList $priceList): object
    {
        return DB::table('price_lists')->where('id', $priceList->id)->firstOrFail();
    }
}
