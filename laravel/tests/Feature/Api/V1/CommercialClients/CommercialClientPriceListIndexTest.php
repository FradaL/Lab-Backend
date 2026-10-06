<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
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

class CommercialClientPriceListIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    }

    public function test_empty_listing_uses_standard_pagination(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();

        $this->request($user, $laboratory, $client)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_resource_contract_contains_derived_effectiveness_without_internal_fields(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $priceList = PriceList::factory()->inactive()->for($laboratory)->create([
            'name' => 'Convenio histórico',
            'currency' => 'USD',
        ]);
        $assignment = $this->assignment($laboratory, $client, $priceList, '2026-01-01', null);

        $response = $this->request($user, $laboratory, $client)->assertOk();

        $response
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.commercial_client.id', $client->id)
            ->assertJsonPath('data.0.price_list.id', $priceList->id)
            ->assertJsonPath('data.0.price_list.name', 'Convenio histórico')
            ->assertJsonPath('data.0.price_list.currency', 'USD')
            ->assertJsonPath('data.0.starts_at', '2026-01-01')
            ->assertJsonPath('data.0.ends_at', null)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.is_effective', true)
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.price_list.status');
        $this->assertSame([
            'id', 'commercial_client', 'price_list', 'starts_at', 'ends_at',
            'status', 'is_effective', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertIsBool($response->json('data.0.is_effective'));
    }

    public function test_default_listing_includes_active_inactive_historical_current_and_future_assignments(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $lists = PriceList::factory()->count(4)->for($laboratory)->create();
        $historical = $this->assignment($laboratory, $client, $lists[0], '2025-01-01', '2025-12-31');
        $current = $this->assignment($laboratory, $client, $lists[1], '2026-01-01', '2026-12-31');
        $inactive = $this->assignment($laboratory, $client, $lists[2], '2026-02-01', null, 'inactive');
        $future = $this->assignment($laboratory, $client, $lists[3], '2027-01-01', null);

        $this->request($user, $laboratory, $client)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$future->id, $inactive->id, $current->id, $historical->id])
            ->assertJsonPath('data.*.is_effective', [false, false, true, false]);
    }

    public function test_inactive_commercial_client_and_inactive_price_list_remain_administratively_visible(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $client->update(['status' => CommercialClient::STATUS_INACTIVE]);
        $priceList = PriceList::factory()->inactive()->for($laboratory)->create();
        $assignment = $this->assignment($laboratory, $client, $priceList, '2026-01-01', null, 'inactive');

        $this->request($user, $laboratory, $client)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$assignment->id])
            ->assertJsonPath('data.0.is_effective', false);
    }

    public function test_explicit_and_default_effective_dates_use_inclusive_boundaries(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $lists = PriceList::factory()->count(2)->for($laboratory)->create();
        $bounded = $this->assignment($laboratory, $client, $lists[0], '2026-10-05', '2026-10-05');
        $tomorrow = $this->assignment($laboratory, $client, $lists[1], '2026-10-06', null, 'inactive');

        $this->request($user, $laboratory, $client)
            ->assertJsonPath('data.*.is_effective', [false, true]);
        $this->request($user, $laboratory, $client, ['effective_date' => '2026-10-06'])
            ->assertJsonPath('data.*.id', [$tomorrow->id, $bounded->id])
            ->assertJsonPath('data.*.is_effective', [false, false]);

        $this->assertTrue($bounded->isEffectiveOn(CarbonImmutable::parse('2026-10-05')));
        $this->assertFalse($bounded->isEffectiveOn(CarbonImmutable::parse('2026-10-04')));
        $this->assertFalse($bounded->isEffectiveOn(CarbonImmutable::parse('2026-10-06')));
    }

    public function test_status_and_effective_filters_combine_without_default_fallback(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        PriceList::factory()->asDefault()->for($laboratory)->create();
        $lists = PriceList::factory()->count(3)->for($laboratory)->create();
        $current = $this->assignment($laboratory, $client, $lists[0], '2026-01-01', null);
        $historical = $this->assignment($laboratory, $client, $lists[1], '2025-01-01', '2025-12-31');
        $inactive = $this->assignment($laboratory, $client, $lists[2], '2026-01-01', null, 'inactive');

        $this->request($user, $laboratory, $client, ['effective' => 'true'])
            ->assertJsonPath('data.*.id', [$current->id]);
        $this->request($user, $laboratory, $client, ['effective' => 'false'])
            ->assertJsonPath('data.*.id', [$inactive->id, $historical->id]);
        $this->request($user, $laboratory, $client, ['status' => 'inactive', 'effective' => 'false'])
            ->assertJsonPath('data.*.id', [$inactive->id]);
        $this->request($user, $laboratory, $client, [
            'effective_date' => '2025-06-01',
            'status' => 'active',
            'effective' => 'true',
            'search' => $lists[1]->name,
        ])->assertJsonPath('data.*.id', [$historical->id]);

        $emptyClient = CommercialClient::factory()->for($laboratory)->create();
        $this->request($user, $laboratory, $emptyClient, ['effective' => 'true'])
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_only_uses_price_list_name_and_is_case_insensitive_and_trimmed(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $expectedList = PriceList::factory()->for($laboratory)->create(['name' => 'Convenio Premium']);
        $otherList = PriceList::factory()->for($laboratory)->create(['name' => 'Tarifa General']);
        $expected = $this->assignment($laboratory, $client, $expectedList, '2026-01-01', null);
        $this->assignment($laboratory, $client, $otherList, '2025-01-01', '2025-12-31');
        $client->update(['name' => 'Premium solamente en cliente']);

        $this->request($user, $laboratory, $client, ['search' => '  PREMIUM  '])
            ->assertOk()->assertJsonPath('data.*.id', [$expected->id]);
        $this->request($user, $laboratory, $client, ['search' => $client->name])
            ->assertOk()->assertJsonCount(0, 'data');
        $this->request($user, $laboratory, $client, ['search' => '   '])
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_all_supported_sort_fields_and_directions_are_stable(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $alpha = PriceList::factory()->for($laboratory)->create(['name' => 'Alpha']);
        $zulu = PriceList::factory()->for($laboratory)->create(['name' => 'Zulu']);
        $first = $this->assignment($laboratory, $client, $zulu, '2026-01-01', '2026-02-01');
        $second = $this->assignment($laboratory, $client, $alpha, '2026-03-01', null, 'inactive');

        $this->request($user, $laboratory, $client, ['sort' => 'price_list_name', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$second->id, $first->id]);
        $this->request($user, $laboratory, $client, ['sort' => 'starts_at', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$first->id, $second->id]);

        foreach (['ends_at', 'status', 'created_at', 'updated_at'] as $sort) {
            $this->request($user, $laboratory, $client, ['sort' => $sort, 'direction' => 'desc'])
                ->assertOk()->assertJsonCount(2, 'data');
        }
    }

    public function test_pagination_uses_project_defaults_limits_and_query_links(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $priceLists = PriceList::factory()->count(18)->for($laboratory)->create();
        foreach ($priceLists as $index => $priceList) {
            $this->assignment($laboratory, $client, $priceList, sprintf('2025-%02d-01', ($index % 12) + 1), null, 'inactive');
        }

        $this->request($user, $laboratory, $client)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 18);
        $this->request($user, $laboratory, $client, ['per_page' => 5, 'page' => 2])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 18);
    }

    public function test_tenant_and_parent_scopes_are_explicit_and_neutral(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA, $clientA] = $this->activeContext($user);
        [, $laboratoryB, $clientB] = $this->activeContext($user);
        $otherClient = CommercialClient::factory()->for($laboratoryA)->create();
        $own = $this->assignment($laboratoryA, $clientA, PriceList::factory()->for($laboratoryA)->create(), '2026-01-01', null);
        $this->assignment($laboratoryA, $otherClient, PriceList::factory()->for($laboratoryA)->create(), '2026-01-01', null);
        $this->assignment($laboratoryB, $clientB, PriceList::factory()->for($laboratoryB)->create(), '2026-01-01', null);

        $this->request($user, $laboratoryA, $clientA)->assertJsonPath('data.*.id', [$own->id]);
        $this->request($user, $laboratoryA, $clientB, ['status' => 'invalid'])
            ->assertNotFound()->assertJsonMissingValidationErrors(['status']);
        $this->request($user, $laboratoryA, 999999, ['status' => 'invalid'])
            ->assertNotFound()->assertJsonMissingValidationErrors(['status']);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_and_unknown_query_parameters_return_422(array $query, string $field): void
    {
        [$user, $laboratory, $client] = $this->activeContext();

        $this->request($user, $laboratory, $client, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueryProvider(): array
    {
        return [
            'invalid date' => [['effective_date' => '2026-02-30'], 'effective_date'],
            'date with time' => [['effective_date' => '2026-10-05T00:00:00Z'], 'effective_date'],
            'status uppercase' => [['status' => 'ACTIVE'], 'status'],
            'status whitespace' => [['status' => ' active '], 'status'],
            'effective one' => [['effective' => '1'], 'effective'],
            'effective uppercase' => [['effective' => 'TRUE'], 'effective'],
            'sort id' => [['sort' => 'id'], 'sort'],
            'direction uppercase' => [['direction' => 'DESC'], 'direction'],
            'page zero' => [['page' => 0], 'page'],
            'page decimal' => [['page' => '1.5'], 'page'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page above max' => [['per_page' => 101], 'per_page'],
            'unknown' => [['foo' => 'bar'], 'foo'],
            'tenant injection' => [['laboratory_id' => 1], 'laboratory_id'],
        ];
    }

    public function test_get_is_read_only_and_query_count_is_constant_without_n_plus_one(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $priceLists = PriceList::factory()->count(10)->for($laboratory)->create();
        $priceLists->each(fn (PriceList $priceList): CommercialClientPriceList => $this->assignment(
            $laboratory,
            $client,
            $priceList,
            '2025-01-01',
            '2025-12-31',
            'inactive',
        ));
        $before = $this->pricingDomainSnapshot();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->request($user, $laboratory, $client, ['per_page' => 100])->assertOk()->assertJsonCount(10, 'data');
        $domainQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'commercial_clients')
            || str_contains($query['query'], 'commercial_client_price_lists')
            || str_contains($query['query'], 'price_lists')
        );
        DB::disableQueryLog();

        $this->assertCount(4, $domainQueries);
        $domainQueries->each(fn (array $query) => $this->assertStringStartsWith(
            'select',
            strtolower(ltrim($query['query'])),
        ));
        $this->assertSame($before, $this->pricingDomainSnapshot());
    }

    public function test_route_and_openapi_contract_add_exactly_one_administrative_operation(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $matches = $routes->filter(fn ($route): bool => $route->uri() === 'api/v1/commercial-clients/{commercialClient}/price-list-assignments'
            && $route->methods() === ['GET', 'HEAD']
        );

        $this->assertCount(1, $matches);
        $this->assertContains('saas', $matches->first()->middleware());
        $this->assertSame('[0-9]+', $matches->first()->wheres['commercialClient'] ?? null);
        $this->assertNull($routes->first(fn ($route): bool => str_contains($route->uri(), 'price-list-assignments/current')));

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/commercial-clients/{commercialClient}/price-list-assignments']['get'];
        $parameters = collect($operation['parameters'])->pluck('name')->filter()->values()->all();

        $this->assertSame([
            'commercialClient', 'effective_date', 'status', 'effective',
            'search', 'sort', 'direction', 'per_page', 'page',
        ], $parameters);
        $this->assertContains(
            '#/components/parameters/LaboratoryContextHeader',
            collect($operation['parameters'])->pluck('$ref')->filter()->all(),
        );
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertSame('boolean', $document['components']['schemas']['CommercialClientPriceListIndexItem']['allOf'][1]['properties']['is_effective']['type']);
    }

    /** @return array{User, Laboratory, CommercialClient} */
    private function activeContext(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [$user, $laboratory, CommercialClient::factory()->for($laboratory)->create()];
    }

    private function assignment(
        Laboratory $laboratory,
        CommercialClient $client,
        PriceList $priceList,
        string $startsAt,
        ?string $endsAt,
        string $status = CommercialClientPriceList::STATUS_ACTIVE,
    ): CommercialClientPriceList {
        return CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $query */
    private function request(
        User $user,
        Laboratory $laboratory,
        CommercialClient|int $commercialClient,
        array $query = [],
    ): TestResponse {
        $id = $commercialClient instanceof CommercialClient ? $commercialClient->id : $commercialClient;
        $suffix = $query === [] ? '' : '?'.http_build_query($query);

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/commercial-clients/{$id}/price-list-assignments{$suffix}");
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function pricingDomainSnapshot(): array
    {
        return collect(['commercial_clients', 'commercial_client_price_lists', 'price_lists'])
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->orderBy('id')->get()->map(
                    fn (object $row): array => (array) $row,
                )->all(),
            ])
            ->all();
    }
}
