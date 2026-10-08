<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialClientIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
    }

    public function test_empty_listing_returns_standard_pagination_metadata(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory)
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

    public function test_resource_exposes_exact_contract_and_preserves_nulls(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Entidad Uno',
            'type' => CommercialClient::TYPE_INSURANCE,
            'tax_id' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'notes' => null,
            'status' => CommercialClient::STATUS_ACTIVE,
        ]);

        $response = $this->commercialClientRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonPath('data.0.name', 'Entidad Uno')
            ->assertJsonPath('data.0.type', CommercialClient::TYPE_INSURANCE)
            ->assertJsonPath('data.0.tax_id', null)
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('data.0.address', null)
            ->assertJsonPath('data.0.notes', null)
            ->assertJsonPath('data.0.status', CommercialClient::STATUS_ACTIVE)
            ->assertJsonPath('data.0.created_at', '2026-10-02T12:00:00.000000Z')
            ->assertJsonPath('data.0.updated_at', '2026-10-02T12:00:00.000000Z');

        $this->assertSame([
            'id', 'name', 'type', 'tax_id', 'phone', 'email', 'address', 'notes',
            'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $response
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.price_list_id')
            ->assertJsonMissingPath('data.0.laboratory')
            ->assertJsonMissingPath('data.0.price_list');
    }

    public function test_context_switching_is_symmetrically_tenant_isolated_and_allows_same_name(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $clientA = CommercialClient::factory()->for($laboratoryA)->create(['name' => 'Entidad Compartida']);
        $clientB = CommercialClient::factory()->for($laboratoryB)->create(['name' => 'Entidad Compartida']);

        foreach ([[$laboratoryA, $clientA], [$laboratoryB, $clientB]] as [$laboratory, $expected]) {
            $this->commercialClientRequest($user, $laboratory)
                ->assertOk()
                ->assertJsonPath('data.*.id', [$expected->id])
                ->assertJsonPath('meta.total', 1);
        }
    }

    public function test_search_supports_all_fields_partial_case_insensitive_nulls_empty_and_no_matches(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Seguros Aurora',
            'tax_id' => 'NIT-ABC-123',
            'phone' => '+502 5555-9876',
            'email' => 'Convenios@Aurora.test',
        ]);
        CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Entidad sin contacto',
            'tax_id' => null,
            'phone' => null,
            'email' => null,
        ]);

        foreach (['seguros', 'AURORA', 'abc-12', '5555-98', 'convenios@aurora'] as $search) {
            $this->commercialClientRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$client->id]);
        }

        $this->commercialClientRequest($user, $laboratory, ['search' => 'sin coincidencia'])
            ->assertJsonCount(0, 'data');
        foreach (['', '   '] as $search) {
            $this->commercialClientRequest($user, $laboratory, ['search' => $search])
                ->assertJsonCount(2, 'data');
        }
    }

    public function test_grouped_search_never_escapes_tenant_scope(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $visible = CommercialClient::factory()->for($laboratoryA)->create(['name' => 'Empresa Visible']);
        CommercialClient::factory()->for($laboratoryB)->create([
            'name' => 'Empresa Secreta',
            'tax_id' => 'SECRET-TAX',
            'phone' => 'SECRET-PHONE',
            'email' => 'secret@example.test',
        ]);

        $this->commercialClientRequest($user, $laboratoryA, ['search' => 'Empresa'])
            ->assertJsonPath('data.*.id', [$visible->id]);

        foreach (['Secreta', 'SECRET-TAX', 'SECRET-PHONE', 'secret@example.test'] as $search) {
            $this->commercialClientRequest($user, $laboratoryA, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_status_and_type_filters_include_all_by_default_and_combine_with_search(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $expected = CommercialClient::factory()->for($laboratory)->insurance()->create([
            'name' => 'Seguros Exactos',
            'status' => CommercialClient::STATUS_ACTIVE,
        ]);
        $inactiveInsurance = CommercialClient::factory()->for($laboratory)->insurance()->inactive()->create([
            'name' => 'Seguros Inactivos',
        ]);
        $company = CommercialClient::factory()->for($laboratory)->company()->create(['name' => 'Empresa Exacta']);
        $agreement = CommercialClient::factory()->for($laboratory)->agreement()->create(['name' => 'Convenio Exacto']);
        $other = CommercialClient::factory()->for($laboratory)->other()->create(['name' => 'Organización Exacta']);

        $this->commercialClientRequest($user, $laboratory)->assertJsonCount(5, 'data');
        $this->commercialClientRequest($user, $laboratory, ['status' => 'active'])
            ->assertJsonCount(4, 'data');
        $this->commercialClientRequest($user, $laboratory, ['status' => 'inactive'])
            ->assertJsonPath('data.*.id', [$inactiveInsurance->id]);

        foreach ([
            CommercialClient::TYPE_INSURANCE => [$expected->id, $inactiveInsurance->id],
            CommercialClient::TYPE_COMPANY => [$company->id],
            CommercialClient::TYPE_AGREEMENT => [$agreement->id],
            CommercialClient::TYPE_OTHER => [$other->id],
        ] as $type => $expectedIds) {
            $response = $this->commercialClientRequest($user, $laboratory, ['type' => $type]);
            $this->assertEqualsCanonicalizing($expectedIds, $response->json('data.*.id'));
        }

        $this->commercialClientRequest($user, $laboratory, [
            'search' => 'seguros',
            'status' => 'active',
            'type' => 'insurance',
        ])->assertJsonPath('data.*.id', [$expected->id]);
    }

    public function test_sorting_supports_whitelist_directions_defaults_and_stable_tiebreaker(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = CommercialClient::factory()->for($laboratory)->company()->create([
            'name' => 'Beta', 'created_at' => '2026-09-20 12:00:00',
        ]);
        $second = CommercialClient::factory()->for($laboratory)->company()->create([
            'name' => 'Gamma', 'created_at' => '2026-09-20 12:00:00',
        ]);
        $third = CommercialClient::factory()->for($laboratory)->agreement()->create([
            'name' => 'Alpha', 'created_at' => '2026-09-22 12:00:00',
        ]);

        $this->commercialClientRequest($user, $laboratory)
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
        $this->commercialClientRequest($user, $laboratory, ['direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$second->id, $first->id, $third->id]);
        $this->commercialClientRequest($user, $laboratory, ['sort' => 'type'])
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
        $this->commercialClientRequest($user, $laboratory, ['sort' => 'type', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$second->id, $first->id, $third->id]);
        $this->commercialClientRequest($user, $laboratory, ['sort' => 'created_at'])
            ->assertJsonPath('data.*.id', [$first->id, $second->id, $third->id]);
        $this->commercialClientRequest($user, $laboratory, ['sort' => 'created_at', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$third->id, $second->id, $first->id]);
    }

    public function test_pagination_supports_defaults_custom_pages_and_boundaries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        foreach (range(1, 18) as $number) {
            CommercialClient::factory()->for($laboratory)->create([
                'name' => sprintf('Entidad %02d', $number),
            ]);
        }

        $this->commercialClientRequest($user, $laboratory)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18);
        $this->commercialClientRequest($user, $laboratory, ['per_page' => 1])
            ->assertJsonCount(1, 'data');
        $this->commercialClientRequest($user, $laboratory, ['per_page' => 5, 'page' => 2])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5);
        $this->commercialClientRequest($user, $laboratory, ['per_page' => 100])
            ->assertJsonCount(18, 'data');
        $this->commercialClientRequest($user, $laboratory, ['page' => 2])
            ->assertJsonCount(3, 'data');
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_prohibited_and_unknown_query_parameters_are_rejected(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueryProvider(): array
    {
        return [
            'invalid status' => [['status' => 'enabled'], 'status'],
            'uppercase status' => [['status' => 'ACTIVE'], 'status'],
            'numeric status' => [['status' => '1'], 'status'],
            'invalid type' => [['type' => 'client'], 'type'],
            'translated insurance' => [['type' => 'seguro'], 'type'],
            'uppercase type' => [['type' => 'COMPANY'], 'type'],
            'invalid sort' => [['sort' => 'status'], 'sort'],
            'laboratory sort' => [['sort' => 'laboratory_id'], 'sort'],
            'invalid direction' => [['direction' => 'ascending'], 'direction'],
            'uppercase direction' => [['direction' => 'DESC'], 'direction'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page above maximum' => [['per_page' => 101], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page text' => [['page' => 'invalid'], 'page'],
            'unknown parameter' => [['foo' => 'bar'], 'foo'],
            'laboratory injection' => [['laboratory_id' => 123], 'laboratory_id'],
            'price list injection' => [['price_list_id' => 1], 'price_list_id'],
        ];
    }

    public function test_saas_pipeline_preserves_auth_context_membership_and_subscription_errors(): void
    {
        $this->getJson('/api/v1/commercial-clients')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/commercial-clients')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $denied = Laboratory::factory()->create();
        $this->createCurrentSubscription($denied);
        $this->commercialClientRequest($user, $denied)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->commercialClientRequest($user, $withoutSubscription)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_query_is_scoped_grouped_read_only_and_constant_without_related_queries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->insurance()->create([
            'name' => 'Seguros Query',
            'tax_id' => 'Q-100',
            'phone' => null,
            'email' => null,
        ]);
        $beforeUpdatedAt = $client->getRawOriginal('updated_at');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->commercialClientRequest($user, $laboratory, [
            'search' => 'query',
            'status' => 'active',
            'type' => 'insurance',
        ])->assertOk();

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $clientQueries = $queries
            ->filter(fn (array $query): bool => str_contains($query['query'], 'commercial_clients'))
            ->values();

        $this->assertCount(2, $clientQueries);
        foreach ($clientQueries as $query) {
            $this->assertStringContainsString('"commercial_clients"."laboratory_id" = ?', $query['query']);
            $this->assertContains($laboratory->id, $query['bindings']);
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        $this->assertMatchesRegularExpression(
            '/\("name"(?:::text)? (?:i?like) \? or "tax_id"(?:::text)? (?:i?like) \? or "phone"(?:::text)? (?:i?like) \? or "email"(?:::text)? (?:i?like) \?\)/',
            $clientQueries->last()['query'],
        );
        foreach (['price_lists', 'price_list_exams', 'patients', 'doctors', 'orders'] as $table) {
            $this->assertFalse($queries->contains(fn (array $query): bool => str_contains($query['query'], $table)));
        }
        $this->assertSame($beforeUpdatedAt, $client->fresh()->getRawOriginal('updated_at'));

        CommercialClient::factory()->count(20)->for($laboratory)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->commercialClientRequest($user, $laboratory, ['per_page' => 100])->assertOk();
        $manyClientQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'commercial_clients'));
        DB::disableQueryLog();
        $this->assertCount(2, $manyClientQueries);
    }

    public function test_get_does_not_synthesize_particular_or_write_rows(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        CommercialClient::factory()->for($laboratory)->create(['name' => 'Entidad Real']);
        $before = CommercialClient::query()->count();

        $this->commercialClientRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonMissing(['name' => 'Particular']);

        $this->assertSame($before, CommercialClient::query()->count());
        $this->assertFalse(CommercialClient::query()->where('name', 'Particular')->exists());
    }

    public function test_runtime_and_openapi_expose_exactly_one_commercial_client_listing_operation(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'api/v1/commercial-clients')
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
            ->values();

        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'HEAD'], $routes->first()->methods());
        $this->assertContains('saas', $routes->first()->middleware());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $path = $document['paths']['/api/v1/commercial-clients'];
        $operation = $path['get'];
        $queryParameters = collect($operation['parameters'])
            ->filter(fn (array $parameter): bool => ($parameter['in'] ?? null) === 'query');

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('get', $path);
        $this->assertCount(7, $queryParameters);
        $this->assertEqualsCanonicalizing([
            'search', 'status', 'type', 'sort', 'direction', 'per_page', 'page',
        ], $queryParameters->pluck('name')->all());
        $this->assertSame([200, 400, 401, 403, 422], array_keys($operation['responses']));
        $this->assertSame(
            '#/components/schemas/CommercialClientCollection',
            $operation['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame([
            'id', 'name', 'type', 'tax_id', 'phone', 'email', 'address', 'notes',
            'status', 'created_at', 'updated_at',
        ], $document['components']['schemas']['CommercialClient']['required']);
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

    /** @param array<string, mixed> $query */
    private function commercialClientRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/commercial-clients';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'commercial_clients.view');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}
