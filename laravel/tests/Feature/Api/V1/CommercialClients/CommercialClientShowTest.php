<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Http\Controllers\Api\V1\CommercialClientController;
use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialClientShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));
    }

    public function test_detail_returns_exact_full_contract_for_current_tenant(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Seguros Completos',
            'type' => CommercialClient::TYPE_INSURANCE,
            'tax_id' => 'NIT-100',
            'phone' => '+502 2222-3333',
            'email' => 'contacto@example.test',
            'address' => 'Ciudad de Guatemala',
            'notes' => 'Convenio administrativo.',
            'status' => CommercialClient::STATUS_ACTIVE,
        ]);

        $this->commercialClientRequest($user, $laboratory, $client->id)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $client->id,
                    'name' => 'Seguros Completos',
                    'type' => CommercialClient::TYPE_INSURANCE,
                    'tax_id' => 'NIT-100',
                    'phone' => '+502 2222-3333',
                    'email' => 'contacto@example.test',
                    'address' => 'Ciudad de Guatemala',
                    'notes' => 'Convenio administrativo.',
                    'status' => CommercialClient::STATUS_ACTIVE,
                    'created_at' => '2026-10-03T12:00:00.000000Z',
                    'updated_at' => '2026-10-03T12:00:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.price_list_id')
            ->assertJsonMissingPath('data.branch_id')
            ->assertJsonMissingPath('data.patient_id')
            ->assertJsonMissingPath('data.doctor_id')
            ->assertJsonMissingPath('data.price_list')
            ->assertJsonMissingPath('data.prices')
            ->assertJsonMissingPath('data.patients')
            ->assertJsonMissingPath('data.doctors')
            ->assertJsonMissingPath('data.branches');
    }

    public function test_nullable_fields_remain_null(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'tax_id' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'notes' => null,
        ]);

        $response = $this->commercialClientRequest($user, $laboratory, $client->id)
            ->assertOk();

        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $response->assertJsonPath("data.{$field}", null);
        }
    }

    #[DataProvider('typeProvider')]
    public function test_every_type_is_visible_in_detail(string $type): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create(['type' => $type]);

        $this->commercialClientRequest($user, $laboratory, $client->id)
            ->assertOk()
            ->assertJsonPath('data.type', $type);
    }

    /** @return array<string, array{string}> */
    public static function typeProvider(): array
    {
        return [
            'insurance' => [CommercialClient::TYPE_INSURANCE],
            'company' => [CommercialClient::TYPE_COMPANY],
            'agreement' => [CommercialClient::TYPE_AGREEMENT],
            'other' => [CommercialClient::TYPE_OTHER],
        ];
    }

    public function test_inactive_commercial_client_is_visible(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->inactive()->for($laboratory)->create();

        $this->commercialClientRequest($user, $laboratory, $client->id)
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.status', CommercialClient::STATUS_INACTIVE);
    }

    public function test_cross_tenant_and_nonexistent_records_share_neutral_404(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $secret = CommercialClient::factory()->for($laboratoryB)->create([
            'name' => 'Entidad Secreta',
            'notes' => 'Dato confidencial',
        ]);

        $crossTenant = $this->commercialClientRequest($user, $laboratoryA, $secret->id)
            ->assertNotFound();
        $missing = $this->commercialClientRequest($user, $laboratoryA, 999999999)
            ->assertNotFound();

        $this->assertSame($crossTenant->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());
        foreach ([$crossTenant, $missing] as $response) {
            $this->assertResponseDoesNotLeak($response, [
                'Entidad Secreta',
                'Dato confidencial',
                'CommercialClient',
                'laboratory',
            ]);
        }
    }

    public function test_validated_tenant_header_is_authoritative_when_switching_context(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $clientB = CommercialClient::factory()->for($laboratoryB)->create();

        $this->commercialClientRequest($user, $laboratoryA, $clientB->id)
            ->assertNotFound();
        $this->commercialClientRequest($user, $laboratoryB, $clientB->id)
            ->assertOk()
            ->assertJsonPath('data.id', $clientB->id);
        $this->commercialClientRequest($user, $laboratoryA, $clientB->id)
            ->assertNotFound();
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_non_numeric_route_identifiers_do_not_reach_lookup(string $identifier): void
    {
        [$user, $laboratory] = $this->activeTenant();
        CommercialClient::factory()->for($laboratory)->create();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'commercial_clients')) {
                $queries[] = $query;
            }
        });

        $this->commercialClientRequest($user, $laboratory, $identifier)
            ->assertNotFound();
        $this->assertCount(0, $queries);
    }

    /** @return array<string, array{string}> */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'letters' => ['abc'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
            'special name' => ['particular'],
        ];
    }

    #[DataProvider('queryParameterProvider')]
    public function test_query_parameters_are_rejected(string $query, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();

        $this->commercialClientRequest($user, $laboratory, $client->id, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'unknown' => ['foo=bar', 'foo'],
            'tenant injection' => ['laboratory_id=999', 'laboratory_id'],
            'price list injection' => ['price_list_id=1', 'price_list_id'],
        ];
    }

    public function test_detail_is_single_query_read_only_and_does_not_load_related_catalogs(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $originalUpdatedAt = $client->getRawOriginal('updated_at');
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $this->commercialClientRequest($user, $laboratory, $client->id)->assertOk();

        $clientQueries = collect($queries)
            ->filter(fn (QueryExecuted $query): bool => str_contains($query->sql, 'commercial_clients'))
            ->values();
        $this->assertCount(1, $clientQueries);
        $this->assertStringStartsWith('select', strtolower(ltrim($clientQueries->first()->sql)));
        $this->assertStringContainsString('laboratory_id', $clientQueries->first()->sql);
        $this->assertContains($laboratory->id, $clientQueries->first()->bindings);
        $this->assertContains($client->id, $clientQueries->first()->bindings);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(insert|update|delete)\b/i', $query->sql);
        }
        foreach (['price_lists', 'price_list_exams', 'patients', 'doctors', 'branches', 'orders'] as $table) {
            $this->assertFalse(collect($queries)->contains(
                fn (QueryExecuted $query): bool => str_contains($query->sql, $table),
            ));
        }
        $this->assertSame($originalUpdatedAt, $client->fresh()->getRawOriginal('updated_at'));
    }

    public function test_saas_pipeline_rejects_invalid_contexts_before_lookup(): void
    {
        $this->getJson('/api/v1/commercial-clients/1')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/commercial-clients/1')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->getJson('/api/v1/commercial-clients/1')->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->getJson('/api/v1/commercial-clients/1')->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);
        $this->commercialClientRequest($user, $withoutMembership, 1)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        $this->createCurrentSubscription($inactiveMembership);
        $this->commercialClientRequest($user, $inactiveMembership, 1)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveLaboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLaboratory, ['is_active' => true]);
        $this->createCurrentSubscription($inactiveLaboratory);
        $this->commercialClientRequest($user, $inactiveLaboratory, 1)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->commercialClientRequest($user, $withoutSubscription, 1)->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_runtime_and_openapi_include_show_with_six_commercial_client_operations(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getActionName(), CommercialClientController::class.'@'))
            ->values();

        $this->assertCount(6, $routes);
        $detail = $routes->first(fn ($route): bool => str_contains($route->uri(), '{commercialClient}'));
        $this->assertNotNull($detail);
        $this->assertSame(['GET', 'HEAD'], $detail->methods());
        $this->assertContains('saas', $detail->middleware());
        $this->assertSame('[0-9]+', $detail->wheres['commercialClient'] ?? null);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $path = $document['paths']['/api/v1/commercial-clients/{commercialClient}'];
        $operation = $path['get'];
        $pathParameter = collect($operation['parameters'])
            ->firstWhere('name', 'commercialClient');

        $this->assertSame(['get', 'patch'], array_keys($path));
        $this->assertSame('integer', $pathParameter['schema']['type']);
        $this->assertSame('int64', $pathParameter['schema']['format']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));

        $operations = collect($document['paths'])->flatMap(
            fn (array $path): array => array_values(array_intersect_key(
                $path,
                array_flip(['get', 'post', 'put', 'patch', 'delete']),
            )),
        );
        $this->assertCount(6, $operations->filter(
            fn (array $operation): bool => in_array('Commercial Clients', $operation['tags'] ?? [], true),
        ));
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'commercial_clients.view');

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

    private function commercialClientRequest(
        User $user,
        Laboratory $laboratory,
        int|string $commercialClient,
        string $query = '',
    ): TestResponse {
        $uri = "/api/v1/commercial-clients/{$commercialClient}";

        if ($query !== '') {
            $uri .= "?{$query}";
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    /** @param list<string> $secrets */
    private function assertResponseDoesNotLeak(TestResponse $response, array $secrets): void
    {
        $content = $response->getContent();

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        foreach (['SQLSTATE', '/home/', '/var/www', 'trace', 'bindings'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }
}
