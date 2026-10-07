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

class PriceListStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00', 'UTC'));
    }

    public function test_minimal_payload_creates_exact_resource_from_database_defaults_without_lookup(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists')) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->priceListRequest($user, $laboratory, [
            'name' => 'Lista General',
            'currency' => 'GTQ',
        ])
            ->assertCreated()
            ->assertJsonStructure(['data'])
            ->assertJsonPath('data.name', 'Lista General')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.currency', 'GTQ')
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.status', PriceList::STATUS_ACTIVE)
            ->assertJsonPath('data.created_at', '2026-09-28T12:00:00.000000Z')
            ->assertJsonPath('data.updated_at', '2026-09-28T12:00:00.000000Z');

        $this->assertSame([
            'id',
            'name',
            'description',
            'currency',
            'is_default',
            'status',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data')));
        $this->assertIsBool($response->json('data.is_default'));
        $response
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.exam_prices')
            ->assertJsonMissingPath('data.commercial_entities');

        $created = PriceList::query()->sole();
        $this->assertSame($laboratory->id, $created->laboratory_id);
        $this->assertSame(PriceList::STATUS_ACTIVE, $created->status);
        $this->assertFalse($created->is_default);
        $this->assertNull($created->description);

        $insert = collect($queries)->first(fn (string $sql): bool => str_starts_with(strtolower($sql), 'insert into'));
        $this->assertNotNull($insert);
        $this->assertStringContainsString('"laboratory_id"', $insert);
        $this->assertStringContainsString('"name"', $insert);
        $this->assertStringContainsString('"description"', $insert);
        $this->assertStringContainsString('"currency"', $insert);
        $this->assertStringNotContainsString('"status"', $insert);
        $this->assertStringNotContainsString('"is_default"', $insert);
        $this->assertCount(1, collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(strtolower($sql), 'insert into'),
        ));
        $this->assertCount(0, collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(strtolower($sql), 'update '),
        ));
        $this->assertCount(1, collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(strtolower($sql), 'select count(*)'),
        ));
    }

    #[DataProvider('validCurrencyProvider')]
    public function test_full_payload_normalizes_text_preserves_case_and_accepts_structural_currencies(string $currency): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->priceListRequest($user, $laboratory, [
            'name' => '  lista Mixta  ',
            'description' => '  Convenio para clientes generales  ',
            'currency' => $currency,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'lista Mixta')
            ->assertJsonPath('data.description', 'Convenio para clientes generales')
            ->assertJsonPath('data.currency', $currency);

        $this->assertDatabaseHas('price_lists', [
            'laboratory_id' => $laboratory->id,
            'name' => 'lista Mixta',
            'description' => 'Convenio para clientes generales',
            'currency' => $currency,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function validCurrencyProvider(): array
    {
        return [
            'GTQ' => ['GTQ'],
            'USD' => ['USD'],
            'EUR' => ['EUR'],
            'structural ABC' => ['ABC'],
        ];
    }

    #[DataProvider('descriptionProvider')]
    public function test_description_normalization_and_boundaries(string $case, mixed $input, ?string $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $payload = $this->validPayload();

        if ($case === 'omitted') {
            unset($payload['description']);
        } else {
            $payload['description'] = $input;
        }

        $this->priceListRequest($user, $laboratory, $payload)
            ->assertCreated()
            ->assertJsonPath('data.description', $expected);

        $this->assertSame($expected, PriceList::query()->sole()->description);
    }

    /** @return array<string, array{string, mixed, string|null}> */
    public static function descriptionProvider(): array
    {
        return [
            'omitted' => ['omitted', null, null],
            'explicit null' => ['provided', null, null],
            'empty' => ['provided', '', null],
            'whitespace' => ['provided', '   ', null],
            'trimmed' => ['provided', '  Tarifa general  ', 'Tarifa general'],
            'maximum 255' => ['provided', str_repeat('á', 255), str_repeat('á', 255)],
        ];
    }

    public function test_name_accepts_75_unicode_characters_after_trim(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $name = str_repeat('á', 75);

        $this->priceListRequest($user, $laboratory, [
            'name' => "  {$name}  ",
            'currency' => 'GTQ',
        ])->assertCreated()->assertJsonPath('data.name', $name);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_server_controlled_and_unknown_payloads_are_atomic(
        array $overrides,
        array $removed,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $payload = array_replace($this->validPayload(), $overrides);

        foreach ($removed as $removedField) {
            unset($payload[$removedField]);
        }

        $response = $this->priceListRequest($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('price_lists', 0);
        $this->assertNoDatabaseDetails($response);
    }

    /** @return array<string, array{array<string, mixed>, list<string>, string}> */
    public static function invalidPayloadProvider(): array
    {
        return [
            'empty payload' => [[], ['name', 'currency', 'description'], 'name'],
            'name missing' => [[], ['name'], 'name'],
            'name null' => [['name' => null], [], 'name'],
            'name empty' => [['name' => ''], [], 'name'],
            'name whitespace' => [['name' => '   '], [], 'name'],
            'name integer' => [['name' => 123], [], 'name'],
            'name boolean' => [['name' => true], [], 'name'],
            'name array' => [['name' => ['General']], [], 'name'],
            'name object' => [['name' => (object) ['value' => 'General']], [], 'name'],
            'name 76' => [['name' => str_repeat('á', 76)], [], 'name'],
            'description integer' => [['description' => 123], [], 'description'],
            'description boolean' => [['description' => true], [], 'description'],
            'description array' => [['description' => ['Tarifa']], [], 'description'],
            'description object' => [['description' => (object) ['value' => 'Tarifa']], [], 'description'],
            'description 256' => [['description' => str_repeat('á', 256)], [], 'description'],
            'currency missing' => [[], ['currency'], 'currency'],
            'currency null' => [['currency' => null], [], 'currency'],
            'currency empty' => [['currency' => ''], [], 'currency'],
            'currency lowercase' => [['currency' => 'gtq'], [], 'currency'],
            'currency mixed Gtq' => [['currency' => 'Gtq'], [], 'currency'],
            'currency mixed gTQ' => [['currency' => 'gTQ'], [], 'currency'],
            'currency mixed GTq' => [['currency' => 'GTq'], [], 'currency'],
            'currency leading whitespace' => [['currency' => ' GTQ'], [], 'currency'],
            'currency trailing whitespace' => [['currency' => 'GTQ '], [], 'currency'],
            'currency surrounding whitespace' => [['currency' => ' GTQ '], [], 'currency'],
            'currency short' => [['currency' => 'US'], [], 'currency'],
            'currency long' => [['currency' => 'USDD'], [], 'currency'],
            'currency numeric string' => [['currency' => '123'], [], 'currency'],
            'currency integer' => [['currency' => 123], [], 'currency'],
            'currency alphanumeric' => [['currency' => 'G1Q'], [], 'currency'],
            'currency underscore' => [['currency' => 'G_Q'], [], 'currency'],
            'status injection' => [['status' => 'inactive'], [], 'status'],
            'default true injection' => [['is_default' => true], [], 'is_default'],
            'default false injection' => [['is_default' => false], [], 'is_default'],
            'laboratory injection' => [['laboratory_id' => 999], [], 'laboratory_id'],
            'id injection' => [['id' => 99], [], 'id'],
            'created at injection' => [['created_at' => '2020-01-01'], [], 'created_at'],
            'updated at injection' => [['updated_at' => '2020-01-01'], [], 'updated_at'],
            'branch injection' => [['branch_id' => 1], [], 'branch_id'],
            'user injection' => [['user_id' => 1], [], 'user_id'],
            'price injection' => [['price' => 100], [], 'price'],
            'prices injection' => [['prices' => []], [], 'prices'],
            'exam injection' => [['exam_id' => 1], [], 'exam_id'],
            'laboratory exam injection' => [['laboratory_exam_id' => 1], [], 'laboratory_exam_id'],
            'exams injection' => [['exams' => []], [], 'exams'],
            'commercial entity injection' => [['commercial_entity_id' => 1], [], 'commercial_entity_id'],
            'entity injection' => [['entity_id' => 1], [], 'entity_id'],
            'currency name injection' => [['currency_name' => 'Quetzal'], [], 'currency_name'],
            'unknown field' => [['foo' => 'bar'], [], 'foo'],
            'valid plus unknown' => [['foo' => 'bar'], [], 'foo'],
        ];
    }

    public function test_uniqueness_is_tenant_scoped_case_sensitive_and_uses_normalized_name(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);

        $this->priceListRequest($user, $laboratoryA, $this->validPayload())->assertCreated();
        $duplicate = $this->priceListRequest($user, $laboratoryA, $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $normalized = $this->priceListRequest($user, $laboratoryA, $this->validPayload([
            'name' => '  Lista General  ',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $this->priceListRequest($user, $laboratoryA, $this->validPayload([
            'name' => 'lista general',
        ]))->assertCreated();
        $this->priceListRequest($user, $laboratoryB, $this->validPayload())->assertCreated();

        $this->assertSame(2, PriceList::forLaboratory($laboratoryA)->count());
        $this->assertSame(1, PriceList::forLaboratory($laboratoryB)->count());
        $this->assertNoDatabaseDetails($duplicate);
        $this->assertNoDatabaseDetails($normalized);
    }

    #[DataProvider('defaultStatusProvider')]
    public function test_existing_default_remains_unchanged_and_new_list_is_non_default(string $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $default = PriceList::factory()->for($laboratory)->asDefault()->create([
            'name' => 'Default actual',
            'status' => $status,
        ]);
        $before = $default->fresh()->getRawOriginal();

        $this->priceListRequest($user, $laboratory, $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.is_default', false);

        $this->assertSame($before, $default->fresh()->getRawOriginal());
        $this->assertTrue($default->fresh()->is_default);
        $this->assertFalse(PriceList::query()->where('name', 'Lista General')->sole()->is_default);
    }

    /** @return array<string, array{string}> */
    public static function defaultStatusProvider(): array
    {
        return [
            'active default' => [PriceList::STATUS_ACTIVE],
            'inactive default' => [PriceList::STATUS_INACTIVE],
        ];
    }

    public function test_tenant_ownership_is_symmetric_during_context_switching(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);

        foreach ([
            [$laboratoryA, 'A1'],
            [$laboratoryB, 'B1'],
            [$laboratoryA, 'A2'],
            [$laboratoryB, 'B2'],
        ] as [$laboratory, $name]) {
            $this->priceListRequest($user, $laboratory, $this->validPayload(['name' => $name]))
                ->assertCreated();
        }

        $this->assertSame(['A1', 'A2'], PriceList::forLaboratory($laboratoryA)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['B1', 'B2'], PriceList::forLaboratory($laboratoryB)->orderBy('name')->pluck('name')->all());
    }

    public function test_unique_name_race_is_mapped_to_safe_name_validation_error(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $inserted = false;

        PriceList::creating(function (PriceList $priceList) use (&$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::table('price_lists')->insert([
                'laboratory_id' => $priceList->laboratory_id,
                'name' => $priceList->name,
                'description' => null,
                'currency' => 'GTQ',
                'is_default' => false,
                'status' => PriceList::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->priceListRequest($user, $laboratory, $this->validPayload())
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);

            $this->assertNoDatabaseDetails($response);
            $this->assertTrue($inserted);

            // The simulated contender runs on this same connection. Store is now
            // transactional with its audit insert, so that synthetic row rolls back
            // with the failed request (a real concurrent transaction remains isolated).
            $this->assertDatabaseCount('price_lists', 0);
        } finally {
            PriceList::flushEventListeners();
        }
    }

    public function test_unrelated_query_exception_is_rethrown(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        PriceList::creating(function (PriceList $priceList): void {
            DB::table('price_lists')->insert([
                'laboratory_id' => $priceList->laboratory_id,
                'name' => 'Unrelated failure',
                'description' => null,
                'currency' => 'bad',
                'is_default' => false,
                'status' => PriceList::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->priceListRequest($user, $laboratory, $this->validPayload());
        } finally {
            PriceList::flushEventListeners();
        }
    }

    public function test_guest_and_missing_context_precede_validation_without_writes(): void
    {
        $this->postJson('/api/v1/price-lists', [])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/v1/price-lists', [])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertDatabaseCount('price_lists', 0);
    }

    public function test_invalid_nonexistent_and_inactive_contexts_precede_validation_without_writes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->postJson('/api/v1/price-lists', [])->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->postJson('/api/v1/price-lists', [])->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->priceListRequest($user, $inactive, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $this->assertDatabaseCount('price_lists', 0);
    }

    public function test_membership_and_subscription_precede_invalid_payload_without_writes(): void
    {
        $user = User::factory()->create();
        $denied = Laboratory::factory()->create();
        $this->createCurrentSubscription($denied);
        $this->priceListRequest($user, $denied, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->priceListRequest($user, $withoutSubscription, [])->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertDatabaseCount('price_lists', 0);
    }

    public function test_debug_false_success_and_representative_errors_do_not_leak_details(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $laboratory] = $this->activeTenant($user);
        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $guest = $this->postJson('/api/v1/price-lists', [])->assertUnauthorized();
        $missingContext = $this->actingAs($user, 'web')
            ->postJson('/api/v1/price-lists', [])->assertBadRequest();

        $responses = [
            $guest,
            $missingContext,
            $this->priceListRequest($user, $laboratory, $this->validPayload())->assertCreated(),
            $this->priceListRequest($user, $laboratory, [])->assertUnprocessable(),
            $this->priceListRequest($user, $laboratory, $this->validPayload(['foo' => 'bar']))->assertUnprocessable(),
            $this->priceListRequest($user, $laboratory, $this->validPayload())->assertUnprocessable(),
            $this->priceListRequest($user, $withoutSubscription, [])->assertForbidden(),
            $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
                ->postJson('/api/v1/price-lists', [])->assertNotFound(),
        ];

        foreach ($responses as $response) {
            $this->assertNoDatabaseDetails($response);
        }
    }

    public function test_runtime_openapi_and_controller_match_store_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/exams') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/available-exams'))
            ->values();
        $methods = $routes->map(fn ($route): array => $route->methods())->all();

        $this->assertCount(7, $routes);
        $this->assertContains(['GET', 'HEAD'], $methods);
        $this->assertContains(['POST'], $methods);
        foreach ($routes as $route) {
            $this->assertContains('saas', $route->middleware());
        }

        $publicMethods = collect((new ReflectionClass(PriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PriceListController::class)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['active', 'index', 'setDefault', 'show', 'store', 'update', 'updateStatus'], $publicMethods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists']['post'];
        $input = $document['components']['schemas']['CreatePriceListInput'];

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['name', 'currency'], $input['required']);
        $this->assertSame(['name', 'description', 'currency'], array_keys($input['properties']));
        $this->assertFalse($input['additionalProperties']);
        $this->assertSame(75, $input['properties']['name']['maxLength']);
        $this->assertSame(['string', 'null'], $input['properties']['description']['type']);
        $this->assertSame(255, $input['properties']['description']['maxLength']);
        $this->assertSame('^[A-Z]{3}$', $input['properties']['currency']['pattern']);
        $this->assertSame(3, $input['properties']['currency']['minLength']);
        $this->assertSame(3, $input['properties']['currency']['maxLength']);
        $this->assertSame('#/components/schemas/CreatePriceListInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/PriceListResponse', $operation['responses']['201']['content']['application/json']['schema']['$ref']);
        $this->assertSame([201, 400, 401, 403, 404, 422], array_keys($operation['responses']));

        $priceListOperationCount = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/price-lists') && ! str_starts_with($name, '/api/v1/price-lists/{priceList}/exams') && ! str_starts_with($name, '/api/v1/price-lists/{priceList}/available-exams'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $laboratoryExamOperationCount = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/laboratory-exams'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $this->assertSame(7, $priceListOperationCount);
        $this->assertSame(6, $laboratoryExamOperationCount);
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

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Lista General',
            'description' => 'Tarifa general del laboratorio',
            'currency' => 'GTQ',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function priceListRequest(User $user, Laboratory $laboratory, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/price-lists', $payload);
    }

    private function assertNoDatabaseDetails(TestResponse $response): void
    {
        $payload = $response->getContent();

        foreach ([
            'SQLSTATE',
            'price_lists_laboratory_name_unique',
            'price_lists_currency_format_check',
            'select ',
            'insert into',
            'bindings',
            '/var/www',
            'App\\Models',
            'stack trace',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }
}
