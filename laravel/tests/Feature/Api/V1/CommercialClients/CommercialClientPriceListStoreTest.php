<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Http\Controllers\Api\V1\CommercialClientPriceListController;
use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class CommercialClientPriceListStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payload_creates_active_assignment_and_returns_exact_201_resource(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();

        $response = $this->request($user, $laboratory, $client->id, [
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ])->assertCreated();

        $assignment = CommercialClientPriceList::query()->sole();
        $response->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.commercial_client.id', $client->id)
            ->assertJsonPath('data.commercial_client.name', $client->name)
            ->assertJsonPath('data.commercial_client.type', $client->type)
            ->assertJsonPath('data.price_list.id', $priceList->id)
            ->assertJsonPath('data.price_list.name', $priceList->name)
            ->assertJsonPath('data.price_list.currency', $priceList->currency)
            ->assertJsonPath('data.starts_at', '2026-01-01')
            ->assertJsonPath('data.ends_at', '2026-12-31')
            ->assertJsonPath('data.status', CommercialClientPriceList::STATUS_ACTIVE);
        $this->assertSame([
            'id', 'commercial_client', 'price_list', 'starts_at', 'ends_at',
            'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertSame(['id', 'name', 'type'], array_keys($response->json('data.commercial_client')));
        $this->assertSame(['id', 'name', 'currency'], array_keys($response->json('data.price_list')));
        $this->assertArrayNotHasKey('laboratory_id', $response->json('data'));
        $this->assertDatabaseHas('commercial_client_price_lists', [
            'id' => $assignment->id,
            'laboratory_id' => $laboratory->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'status' => CommercialClientPriceList::STATUS_ACTIVE,
        ]);
        $this->assertSame('2026-01-01', $assignment->starts_at->toDateString());
        $this->assertSame('2026-12-31', $assignment->ends_at?->toDateString());
    }

    public function test_omitted_end_date_creates_open_ended_assignment(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();

        $this->request($user, $laboratory, $client->id, [
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('data.ends_at', null);

        $this->assertNull(CommercialClientPriceList::query()->sole()->ends_at);
    }

    public function test_cross_tenant_client_returns_neutral_404_before_invalid_payload_validation(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $foreignClient = CommercialClient::factory()->create();

        $this->request($user, $laboratory, $foreignClient->id, ['foo' => 'invalid'])
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['foo', 'price_list_id', 'starts_at']);

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
    }

    public function test_nonexistent_client_returns_same_neutral_404_before_validation(): void
    {
        [$user, $laboratory] = $this->activeContext();

        $this->request($user, $laboratory, 999999, ['foo' => 'invalid'])
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['foo', 'price_list_id', 'starts_at']);

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
    }

    public function test_inactive_commercial_client_returns_422_without_writing(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $client->update(['status' => CommercialClient::STATUS_INACTIVE]);

        $this->request($user, $laboratory, $client->id, $this->payload($priceList))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commercial_client']);

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
    }

    public function test_nonexistent_and_cross_tenant_price_lists_return_the_same_422_contract(): void
    {
        [$user, $laboratory, $client] = $this->activeContext();
        $foreignPriceList = PriceList::factory()->create();
        $payload = ['starts_at' => '2026-01-01', 'ends_at' => null];

        $missingResponse = $this->request($user, $laboratory, $client->id, $payload + ['price_list_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['price_list_id']);
        $foreignResponse = $this->request($user, $laboratory, $client->id, $payload + ['price_list_id' => $foreignPriceList->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['price_list_id']);

        $this->assertSame($missingResponse->json('errors'), $foreignResponse->json('errors'));
        $this->assertDatabaseCount('commercial_client_price_lists', 0);
    }

    public function test_inactive_price_list_returns_422_without_writing(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $priceList->update(['status' => PriceList::STATUS_INACTIVE]);

        $this->request($user, $laboratory, $client->id, $this->payload($priceList))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_list_id']);

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_dates_and_identifiers_return_422_without_writing(array $overrides, string $errorKey): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();

        $this->request($user, $laboratory, $client->id, array_merge($this->payload($priceList), $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing price list' => [['price_list_id' => null], 'price_list_id'],
            'non integer price list' => [['price_list_id' => '1'], 'price_list_id'],
            'missing start' => [['starts_at' => null], 'starts_at'],
            'slash date' => [['starts_at' => '2026/01/01'], 'starts_at'],
            'short date' => [['starts_at' => '2026-1-1'], 'starts_at'],
            'impossible date' => [['starts_at' => '2026-02-30'], 'starts_at'],
            'datetime' => [['starts_at' => '2026-01-01T00:00:00Z'], 'starts_at'],
            'natural language' => [['starts_at' => 'tomorrow'], 'starts_at'],
            'array start' => [['starts_at' => ['2026-01-01']], 'starts_at'],
            'integer start' => [['starts_at' => 20260101], 'starts_at'],
            'end before start' => [['ends_at' => '2025-12-31'], 'ends_at'],
            'invalid end' => [['ends_at' => '2026-02-30'], 'ends_at'],
            'datetime end' => [['ends_at' => '2026-12-31T00:00:00Z'], 'ends_at'],
        ];
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_unknown_or_server_owned_fields_return_422_without_writing(string $field): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();

        $this->request($user, $laboratory, $client->id, $this->payload($priceList) + [$field => 'injected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return collect([
            'status', 'laboratory_id', 'commercial_client_id', 'id', 'is_default',
            'price', 'currency', 'discount', 'branch_id', 'patient_id', 'doctor_id',
            'order_id', 'foo', 'created_at', 'updated_at',
        ])->mapWithKeys(fn (string $field): array => [$field => [$field]])->all();
    }

    public function test_inclusive_boundary_conflicts_but_next_day_is_allowed(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);

        $this->request($user, $laboratory, $client->id, [
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-12-31',
            'ends_at' => '2027-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors(['period']);
        $this->request($user, $laboratory, $client->id, [
            'price_list_id' => $priceList->id,
            'starts_at' => '2027-01-01',
            'ends_at' => '2027-12-31',
        ])->assertCreated();

        $this->assertDatabaseCount('commercial_client_price_lists', 2);
    }

    public function test_different_price_list_does_not_bypass_overlap_and_existing_row_is_unchanged(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $existing = CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        $original = $existing->getAttributes();

        $this->request($user, $laboratory, $client->id, [
            'price_list_id' => $otherPriceList->id,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-30',
        ])->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertEqualsCanonicalizing($original, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('commercial_client_price_lists', 1);
    }

    public function test_open_ended_and_same_day_active_periods_conflict_inclusively(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-06-15',
            'ends_at' => null,
        ]);

        $this->request($user, $laboratory, $client->id, [
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-06-15',
            'ends_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertDatabaseCount('commercial_client_price_lists', 1);
    }

    public function test_inactive_overlap_and_identical_periods_for_other_clients_and_laboratories_are_allowed(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        CommercialClientPriceList::factory()->inactive()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ]);
        $otherClient = CommercialClient::factory()->for($laboratory)->create();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();

        $this->request($user, $laboratory, $client->id, $this->payload($otherPriceList))->assertCreated();
        $this->request($user, $laboratory, $otherClient->id, $this->payload($priceList))->assertCreated();

        $otherLaboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($otherLaboratory, ['is_active' => true]);
        Subscription::factory()->for($otherLaboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $thirdClient = CommercialClient::factory()->for($otherLaboratory)->create();
        $thirdPriceList = PriceList::factory()->for($otherLaboratory)->create();

        $this->request($user, $otherLaboratory, $thirdClient->id, $this->payload($thirdPriceList))->assertCreated();
        $this->assertDatabaseCount('commercial_client_price_lists', 4);
    }

    public function test_exact_duplicate_returns_period_422_without_a_second_write(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $payload = $this->payload($priceList);

        $this->request($user, $laboratory, $client->id, $payload)->assertCreated();
        $this->request($user, $laboratory, $client->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);

        $this->assertDatabaseCount('commercial_client_price_lists', 1);
    }

    public function test_saas_pipeline_and_numeric_route_constraint_are_enforced(): void
    {
        $payload = ['price_list_id' => 1, 'starts_at' => '2026-01-01'];
        $this->postJson('/api/v1/commercial-clients/1/price-list-assignments', $payload)->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/api/v1/commercial-clients/1/price-list-assignments', $payload)
            ->assertBadRequest();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->postJson('/api/v1/commercial-clients/1/price-list-assignments', $payload)->assertNotFound();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '1')
            ->postJson('/api/v1/commercial-clients/abc/price-list-assignments', $payload)->assertNotFound();
    }

    public function test_successful_flow_has_four_domain_queries_and_no_unrelated_queries(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, $client->id, $this->payload($priceList))->assertCreated();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        $domainQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'commercial_clients')
            || str_contains($sql, 'price_lists')
            || str_contains($sql, 'commercial_client_price_lists'));
        $this->assertCount(4, $domainQueries);
        foreach (['patients', 'doctors', 'branches', 'orders', 'price_list_exams'] as $table) {
            $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }

    public function test_route_controller_and_openapi_inventories_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $assignmentRoutes = $routes->filter(fn ($route): bool => str_contains($route->uri(), 'price-list-assignments'));

        $this->assertCount(4, $assignmentRoutes);
        $storeRoute = $assignmentRoutes->first(fn ($route): bool => $route->methods() === ['POST']);
        $updateRoute = $assignmentRoutes->first(fn ($route): bool => $route->uri() === 'api/v1/commercial-clients/{commercialClient}/price-list-assignments/{assignment}');
        $statusRoute = $assignmentRoutes->first(fn ($route): bool => str_ends_with($route->uri(), '/status'));
        $this->assertNotNull($storeRoute);
        $this->assertNotNull($updateRoute);
        $this->assertNotNull($statusRoute);
        $this->assertSame(['PATCH'], $updateRoute->methods());
        $this->assertSame(['PATCH'], $statusRoute->methods());
        $this->assertContains('saas', $storeRoute->middleware());
        $this->assertContains('saas', $updateRoute->middleware());
        $this->assertContains('saas', $statusRoute->middleware());
        $this->assertSame('[0-9]+', $storeRoute->wheres['commercialClient'] ?? null);
        $this->assertSame('[0-9]+', $updateRoute->wheres['commercialClient'] ?? null);
        $this->assertSame('[0-9]+', $updateRoute->wheres['assignment'] ?? null);
        $this->assertSame('[0-9]+', $statusRoute->wheres['commercialClient'] ?? null);
        $this->assertSame('[0-9]+', $statusRoute->wheres['assignment'] ?? null);
        $methods = collect((new ReflectionClass(CommercialClientPriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === CommercialClientPriceListController::class)
            ->pluck('name')->all();
        $this->assertSame(['index', 'store', 'update', 'updateStatus'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/commercial-clients/{commercialClient}/price-list-assignments']['post'];
        $updateOperation = $document['paths']['/api/v1/commercial-clients/{commercialClient}/price-list-assignments/{assignment}']['patch'];
        $statusOperation = $document['paths']['/api/v1/commercial-clients/{commercialClient}/price-list-assignments/{assignment}/status']['patch'];
        $schema = $document['components']['schemas']['CreateCommercialClientPriceListInput'];
        $updateSchema = $document['components']['schemas']['UpdateCommercialClientPriceListInput'];
        $statusSchema = $document['components']['schemas']['UpdateCommercialClientPriceListStatusInput'];
        $operations = collect($document['paths'])->flatMap(fn (array $path): array => array_values(array_intersect_key(
            $path,
            array_flip(['get', 'post', 'put', 'patch', 'delete']),
        )));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(69, $operations);
        $this->assertCount(5, $operations->filter(fn (array $item): bool => in_array('Commercial Client Price List Assignments', $item['tags'] ?? [], true)));
        $this->assertCount(6, $operations->filter(fn (array $item): bool => in_array('Commercial Clients', $item['tags'] ?? [], true)));
        $this->assertCount(5, $operations->filter(fn (array $item): bool => in_array('Exam Prices', $item['tags'] ?? [], true)));
        $this->assertSame(['price_list_id', 'starts_at'], $schema['required']);
        $this->assertSame(['price_list_id', 'starts_at', 'ends_at'], array_keys($schema['properties']));
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame([201, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertSame(['price_list_id', 'starts_at', 'ends_at'], array_keys($updateSchema['properties']));
        $this->assertSame(1, $updateSchema['minProperties']);
        $this->assertFalse($updateSchema['additionalProperties']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($updateOperation['responses']));
        $this->assertSame(['status'], $statusSchema['required']);
        $this->assertSame(['status'], array_keys($statusSchema['properties']));
        $this->assertSame(['active', 'inactive'], $statusSchema['properties']['status']['enum']);
        $this->assertFalse($statusSchema['additionalProperties']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($statusOperation['responses']));
    }

    /** @return array{User, Laboratory, CommercialClient, PriceList} */
    private function activeContext(): array
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [
            $user,
            $laboratory,
            CommercialClient::factory()->for($laboratory)->create(),
            PriceList::factory()->for($laboratory)->create(),
        ];
    }

    /** @return array{price_list_id: int, starts_at: string, ends_at: null} */
    private function payload(PriceList $priceList): array
    {
        return [
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ];
    }

    private function request(User $user, Laboratory $laboratory, int $commercialClient, array $payload): TestResponse
    {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'commercial_price_assignments.manage');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson("/api/v1/commercial-clients/{$commercialClient}/price-list-assignments", $payload);

    }
}
