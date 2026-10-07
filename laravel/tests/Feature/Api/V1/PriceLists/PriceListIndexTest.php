<?php

namespace Tests\Feature\Api\V1\PriceLists;

use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PriceListIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00', 'UTC'));
    }

    public function test_authenticated_empty_listing_returns_standard_pagination(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->priceListRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_resource_exposes_exact_fields_types_nulls_and_timestamps(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $default = PriceList::factory()->for($laboratory)->asDefault()->create([
            'name' => 'A General',
            'description' => null,
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $inactive = PriceList::factory()->for($laboratory)->inactive()->create([
            'name' => 'B Seguro',
            'description' => 'Precios del seguro.',
            'currency' => 'USD',
        ]);

        $response = $this->priceListRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $default->id)
            ->assertJsonPath('data.0.name', 'A General')
            ->assertJsonPath('data.0.description', null)
            ->assertJsonPath('data.0.currency', 'GTQ')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.created_at', '2026-09-27T12:00:00.000000Z')
            ->assertJsonPath('data.0.updated_at', '2026-09-27T12:00:00.000000Z')
            ->assertJsonPath('data.1.id', $inactive->id)
            ->assertJsonPath('data.1.is_default', false)
            ->assertJsonPath('data.1.status', 'inactive');

        $this->assertSame([
            'id',
            'name',
            'description',
            'currency',
            'is_default',
            'status',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertIsBool($response->json('data.0.is_default'));
        $this->assertIsBool($response->json('data.1.is_default'));
        $response
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.laboratory')
            ->assertJsonMissingPath('data.0.branch_id')
            ->assertJsonMissingPath('data.0.exams')
            ->assertJsonMissingPath('data.0.exam_prices')
            ->assertJsonMissingPath('data.0.commercial_entities')
            ->assertJsonMissingPath('data.0.users')
            ->assertJsonMissingPath('data.0.subscriptions');
    }

    public function test_default_listing_includes_all_status_and_default_combinations(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $records = collect([
            ['name' => 'A', 'status' => 'active', 'is_default' => true],
            ['name' => 'B', 'status' => 'active', 'is_default' => false],
            ['name' => 'C', 'status' => 'inactive', 'is_default' => false],
        ])->map(fn (array $attributes): PriceList => PriceList::factory()
            ->for($laboratory)
            ->create($attributes));
        $inactiveDefault = PriceList::factory()->for($laboratory)->create([
            'name' => 'D',
            'status' => 'inactive',
            'is_default' => false,
        ]);
        PriceList::query()->whereKey($records->first()->id)->update(['is_default' => false]);
        $inactiveDefault->update(['is_default' => true]);

        $this->priceListRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.*.id', [
                $records[0]->id,
                $records[1]->id,
                $records[2]->id,
                $inactiveDefault->id,
            ])
            ->assertJsonPath('meta.total', 4);
    }

    public function test_collection_is_symmetrically_isolated_during_context_switching(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $priceListA = PriceList::factory()->for($laboratoryA)->create(['name' => 'General A']);
        $priceListB = PriceList::factory()->for($laboratoryB)->create(['name' => 'General B']);

        foreach ([[$laboratoryA, $priceListA], [$laboratoryB, $priceListB], [$laboratoryA, $priceListA], [$laboratoryB, $priceListB]] as [$laboratory, $expected]) {
            $this->priceListRequest($user, $laboratory)
                ->assertOk()
                ->assertJsonPath('data.*.id', [$expected->id]);
        }
    }

    public function test_search_supports_name_description_partial_case_unicode_trim_and_empty(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $general = PriceList::factory()->for($laboratory)->create([
            'name' => 'Lista General',
            'description' => 'Convenio Médico y Promoción Especial',
        ]);
        $chemical = PriceList::factory()->for($laboratory)->create([
            'name' => 'Jornada Química',
            'description' => null,
        ]);

        foreach (['Lista General', 'general', 'STA GEN', 'Médico', 'Promoción', '  general  '] as $search) {
            $this->priceListRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$general->id]);
        }
        $this->priceListRequest($user, $laboratory, ['search' => 'química'])
            ->assertJsonPath('data.*.id', [$chemical->id]);

        foreach (['', '   '] as $search) {
            $this->priceListRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(2, 'data');
        }
    }

    public function test_grouped_name_and_description_search_never_escape_tenant_scope(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        PriceList::factory()->for($laboratoryA)->create([
            'name' => 'Lista Local',
            'description' => null,
        ]);
        PriceList::factory()->for($laboratoryB)->create([
            'name' => 'Convenio UltraEspecial',
            'description' => 'PROMO-SECRETA-B',
        ]);

        foreach (['UltraEspecial', 'PROMO-SECRETA-B'] as $search) {
            $this->priceListRequest($user, $laboratoryA, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }
    }

    public function test_search_wildcards_and_sql_like_strings_are_safely_bound(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        PriceList::factory()->for($laboratory)->create([
            'name' => "Lista O'Reilly 100%",
            'description' => 'Código_A',
        ]);

        foreach (['%', '_', "'", "%' OR 1=1 --"] as $search) {
            $this->priceListRequest($user, $laboratory, ['search' => $search])->assertOk();
        }

        $this->assertSame(1, $laboratory->priceLists()->count());
    }

    public function test_status_currency_and_default_filters_are_exact_and_independent(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $default = PriceList::factory()->for($laboratory)->asDefault()->create([
            'name' => 'A Default',
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_INACTIVE,
        ]);
        $activeGtq = PriceList::factory()->for($laboratory)->create([
            'name' => 'B Active GTQ',
            'currency' => 'GTQ',
        ]);
        $activeUsd = PriceList::factory()->for($laboratory)->create([
            'name' => 'C Active USD',
            'currency' => 'USD',
        ]);

        $this->priceListRequest($user, $laboratory, ['status' => 'active'])
            ->assertJsonPath('data.*.id', [$activeGtq->id, $activeUsd->id]);
        $this->priceListRequest($user, $laboratory, ['status' => 'inactive'])
            ->assertJsonPath('data.*.id', [$default->id]);
        $this->priceListRequest($user, $laboratory, ['currency' => 'GTQ'])
            ->assertJsonPath('data.*.id', [$default->id, $activeGtq->id]);
        $this->priceListRequest($user, $laboratory, ['currency' => 'USD'])
            ->assertJsonPath('data.*.id', [$activeUsd->id]);
        $this->priceListRequest($user, $laboratory, ['is_default' => 'true'])
            ->assertJsonPath('data.*.id', [$default->id]);
        $this->priceListRequest($user, $laboratory, ['is_default' => 'false'])
            ->assertJsonPath('data.*.id', [$activeGtq->id, $activeUsd->id]);
        $this->priceListRequest($user, $laboratory)
            ->assertJsonCount(3, 'data');
    }

    public function test_all_filters_combine_with_and_inside_the_tenant(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $expected = PriceList::factory()->for($laboratoryA)->create([
            'name' => 'Lista General GTQ',
            'description' => 'Convenio general',
            'currency' => 'GTQ',
            'status' => 'active',
            'is_default' => false,
        ]);
        PriceList::factory()->for($laboratoryA)->create(['name' => 'Lista General USD', 'currency' => 'USD']);
        PriceList::factory()->for($laboratoryA)->inactive()->create(['name' => 'Lista General inactiva']);
        PriceList::factory()->for($laboratoryB)->create(['name' => 'Lista General GTQ']);

        $this->priceListRequest($user, $laboratoryA, [
            'search' => 'general',
            'status' => 'active',
            'currency' => 'GTQ',
            'is_default' => 'false',
        ])
            ->assertOk()
            ->assertJsonPath('data.*.id', [$expected->id])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_sorting_supports_whitelist_directions_default_and_tiebreakers(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = PriceList::factory()->for($laboratory)->create([
            'name' => 'Beta', 'currency' => 'USD', 'created_at' => '2026-09-20 12:00:00',
        ]);
        $second = PriceList::factory()->for($laboratory)->create([
            'name' => 'Gamma', 'currency' => 'GTQ', 'created_at' => '2026-09-20 12:00:00',
        ]);
        $third = PriceList::factory()->for($laboratory)->create([
            'name' => 'Alpha', 'currency' => 'EUR', 'created_at' => '2026-09-22 12:00:00',
        ]);

        $this->priceListRequest($user, $laboratory)
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
        $this->priceListRequest($user, $laboratory, ['direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$second->id, $first->id, $third->id]);

        foreach ([
            ['name', 'asc', [$third->id, $first->id, $second->id]],
            ['name', 'desc', [$second->id, $first->id, $third->id]],
            ['currency', 'asc', [$third->id, $second->id, $first->id]],
            ['currency', 'desc', [$first->id, $second->id, $third->id]],
            ['created_at', 'asc', [$first->id, $second->id, $third->id]],
            ['created_at', 'desc', [$third->id, $second->id, $first->id]],
        ] as [$sort, $direction, $ids]) {
            $this->priceListRequest($user, $laboratory, compact('sort', 'direction'))
                ->assertJsonPath('data.*.id', $ids);
        }
    }

    public function test_pagination_supports_defaults_custom_pages_and_boundaries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        foreach (range(1, 18) as $number) {
            PriceList::factory()->for($laboratory)->create([
                'name' => sprintf('Lista %02d', $number),
            ]);
        }

        $this->priceListRequest($user, $laboratory)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18);
        $this->priceListRequest($user, $laboratory, ['per_page' => 5, 'page' => 2])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5);
        $this->priceListRequest($user, $laboratory, ['per_page' => 1])
            ->assertJsonCount(1, 'data');
        $this->priceListRequest($user, $laboratory, ['per_page' => 100])
            ->assertJsonCount(18, 'data');
        $this->priceListRequest($user, $laboratory, ['page' => 2])
            ->assertJsonCount(3, 'data');
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_and_unknown_query_parameters_return_422_without_writes(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $beforeUpdatedAt = $priceList->getRawOriginal('updated_at');

        $this->priceListRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame($beforeUpdatedAt, $priceList->fresh()->getRawOriginal('updated_at'));
        $this->assertSame(1, $laboratory->priceLists()->count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueryProvider(): array
    {
        return [
            'status uppercase' => [['status' => 'ACTIVE'], 'status'],
            'status mixed case' => [['status' => 'Inactive'], 'status'],
            'status unknown' => [['status' => 'enabled'], 'status'],
            'status numeric' => [['status' => '1'], 'status'],
            'status empty' => [['status' => ''], 'status'],
            'status whitespace' => [['status' => ' active '], 'status'],
            'currency lowercase' => [['currency' => 'gtq'], 'currency'],
            'currency mixed case' => [['currency' => 'Gtq'], 'currency'],
            'currency short' => [['currency' => 'US'], 'currency'],
            'currency long' => [['currency' => 'USDD'], 'currency'],
            'currency numeric' => [['currency' => '123'], 'currency'],
            'currency alphanumeric' => [['currency' => 'G1Q'], 'currency'],
            'currency empty' => [['currency' => ''], 'currency'],
            'currency whitespace' => [['currency' => ' GTQ '], 'currency'],
            'default one' => [['is_default' => '1'], 'is_default'],
            'default zero' => [['is_default' => '0'], 'is_default'],
            'default uppercase true' => [['is_default' => 'TRUE'], 'is_default'],
            'default mixed false' => [['is_default' => 'False'], 'is_default'],
            'default yes' => [['is_default' => 'yes'], 'is_default'],
            'default empty' => [['is_default' => ''], 'is_default'],
            'sort status' => [['sort' => 'status'], 'sort'],
            'sort default' => [['sort' => 'is_default'], 'sort'],
            'sort laboratory' => [['sort' => 'laboratory_id'], 'sort'],
            'sort id' => [['sort' => 'id'], 'sort'],
            'sort empty' => [['sort' => ''], 'sort'],
            'direction uppercase' => [['direction' => 'DESC'], 'direction'],
            'direction word' => [['direction' => 'ascending'], 'direction'],
            'direction empty' => [['direction' => ''], 'direction'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page above max' => [['per_page' => 101], 'per_page'],
            'per page negative' => [['per_page' => -1], 'per_page'],
            'per page decimal' => [['per_page' => '1.5'], 'per_page'],
            'per page text' => [['per_page' => 'abc'], 'per_page'],
            'per page empty' => [['per_page' => ''], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page decimal' => [['page' => '1.5'], 'page'],
            'page text' => [['page' => 'abc'], 'page'],
            'page empty' => [['page' => ''], 'page'],
            'unknown' => [['foo' => 'bar'], 'foo'],
            'laboratory injection' => [['laboratory_id' => 1], 'laboratory_id'],
            'branch injection' => [['branch_id' => 1], 'branch_id'],
            'name alias' => [['name' => 'General'], 'name'],
            'description alias' => [['description' => 'x'], 'description'],
            'include injection' => [['include' => 'x'], 'include'],
            'with injection' => [['with' => 'x'], 'with'],
            'valid and unknown' => [['status' => 'active', 'foo' => 'bar'], 'foo'],
            'multiple unknown' => [['foo' => 'a', 'bar' => 'b'], 'foo'],
        ];
    }

    public function test_duplicate_query_keys_follow_laravel_last_value_semantics(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $inactive = PriceList::factory()->for($laboratory)->inactive()->create();
        PriceList::factory()->for($laboratory)->create();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/price-lists?status=active&status=inactive')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$inactive->id]);
    }

    public function test_query_is_tenant_scoped_grouped_bound_and_filters_with_and(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        PriceList::factory()->for($laboratory)->create([
            'name' => 'General', 'description' => 'Convenio', 'currency' => 'GTQ',
        ]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->priceListRequest($user, $laboratory, [
            'search' => 'gen',
            'status' => 'active',
            'currency' => 'GTQ',
            'is_default' => 'false',
        ])->assertOk();

        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'price_lists'))
            ->values();
        DB::disableQueryLog();

        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('"price_lists"."laboratory_id" = ?', $query['query']);
            $this->assertContains($laboratory->id, $query['bindings']);
        }
        $pageQuery = $queries->last();
        $this->assertMatchesRegularExpression('/\("name"(?:::text)? (?:i?like) \? or "description"(?:::text)? (?:i?like) \?\)/', $pageQuery['query']);
        $this->assertStringContainsString('"status" = ?', $pageQuery['query']);
        $this->assertStringContainsString('"currency" = ?', $pageQuery['query']);
        $this->assertStringContainsString('"is_default" = ?', $pageQuery['query']);
        $this->assertContains('%gen%', $pageQuery['bindings']);
    }

    public function test_get_is_read_only_and_uses_two_price_list_queries_without_n_plus_one(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $beforeUpdatedAt = $priceList->getRawOriginal('updated_at');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->priceListRequest($user, $laboratory)->assertOk();
        $singleQueries = $this->priceListQueries(DB::getQueryLog());

        PriceList::factory()->count(9)->for($laboratory)->create();
        DB::flushQueryLog();
        $this->priceListRequest($user, $laboratory, ['per_page' => 100])
            ->assertOk()
            ->assertJsonCount(10, 'data');
        $tenQueries = $this->priceListQueries(DB::getQueryLog());

        PriceList::factory()->count(40)->for($laboratory)->create();
        DB::flushQueryLog();
        $this->priceListRequest($user, $laboratory, ['per_page' => 100])
            ->assertOk()
            ->assertJsonCount(50, 'data');
        $multipleQueries = $this->priceListQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2, count($singleQueries));
        $this->assertSame(2, count($tenQueries));
        $this->assertSame(2, count($multipleQueries));
        $this->assertSame($beforeUpdatedAt, $priceList->fresh()->getRawOriginal('updated_at'));
        foreach ([...$singleQueries, ...$tenQueries, ...$multipleQueries] as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
    }

    public function test_pipeline_errors_precede_validation_and_do_not_query_or_write_price_lists(): void
    {
        $user = User::factory()->create();
        $before = PriceList::query()->count();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/api/v1/price-lists?status=invalid')->assertUnauthorized();
        $this->actingAs($user, 'web')
            ->getJson('/api/v1/price-lists?status=invalid')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $queries = $this->priceListQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $queries);
        $this->assertSame($before, PriceList::query()->count());
    }

    public function test_context_membership_and_subscription_errors_keep_foundation_contracts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->getJson('/api/v1/price-lists')->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->getJson('/api/v1/price-lists')->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->priceListRequest($user, $inactive)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $denied = Laboratory::factory()->create();
        $this->createCurrentSubscription($denied);
        $this->priceListRequest($user, $denied)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->priceListRequest($user, $withoutSubscription)->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertSame(0, PriceList::query()->count());
    }

    public function test_debug_false_responses_do_not_leak_internal_details(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        PriceList::factory()->for($laboratory)->create();
        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);

        $responses = [
            $this->getJson('/api/v1/price-lists')->assertUnauthorized(),
            $this->actingAs($user, 'web')->getJson('/api/v1/price-lists')->assertBadRequest(),
            $this->priceListRequest($user, $laboratory)->assertOk(),
            $this->priceListRequest($user, $laboratory, ['search' => 'none'])->assertOk(),
            $this->priceListRequest($user, $laboratory, ['foo' => 'bar'])->assertUnprocessable(),
            $this->priceListRequest($user, $laboratory, ['status' => 'ACTIVE'])->assertUnprocessable(),
            $this->priceListRequest($user, $laboratory, ['currency' => 'gtq'])->assertUnprocessable(),
            $this->priceListRequest($user, $laboratory, ['is_default' => '1'])->assertUnprocessable(),
            $this->priceListRequest($user, $withoutSubscription)->assertForbidden(),
        ];

        foreach ($responses as $response) {
            $payload = $response->getContent();
            foreach (['SQLSTATE', 'bindings', '/var/www', 'Illuminate\\', 'price_lists_', 'trace'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $payload);
            }
        }
    }

    public function test_runtime_and_openapi_match_the_index_contract_after_store_is_added(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/exams') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/available-exams'))
            ->values();
        $indexRoute = $routes->first(fn ($route): bool => in_array('GET', $route->methods(), true));

        $this->assertCount(7, $routes);
        $this->assertSame(['GET', 'HEAD'], $indexRoute->methods());
        $this->assertContains('saas', $indexRoute->middleware());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $operation = $document['paths']['/api/v1/price-lists']['get'];
        $queryParameters = collect($operation['parameters'])
            ->filter(fn (array $parameter): bool => ($parameter['in'] ?? null) === 'query');

        $this->assertSame('3.1.0', $document['openapi']);
        $priceListOperationCount = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/price-lists') && ! str_starts_with($name, '/api/v1/price-lists/{priceList}/exams') && ! str_starts_with($name, '/api/v1/price-lists/{priceList}/available-exams'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $this->assertSame(7, $priceListOperationCount);
        $this->assertCount(8, $queryParameters);
        $this->assertEqualsCanonicalizing([
            'search', 'status', 'currency', 'is_default', 'sort', 'direction', 'per_page', 'page',
        ], $queryParameters->pluck('name')->all());
        $this->assertSame('#/components/schemas/PriceListCollection', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertSame([
            'id', 'name', 'description', 'currency', 'is_default', 'status', 'created_at', 'updated_at',
        ], $document['components']['schemas']['PriceList']['required']);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'price_lists.view');

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

    /** @param array<string, mixed> $query */
    private function priceListRequest(User $user, Laboratory $laboratory, array $query = []): TestResponse
    {
        $uri = '/api/v1/price-lists';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>, time: float}>  $queries
     * @return array<int, array{query: string, bindings: array<int, mixed>, time: float}>
     */
    private function priceListQueries(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            fn (array $query): bool => str_contains($query['query'], 'price_lists'),
        ));
    }
}
