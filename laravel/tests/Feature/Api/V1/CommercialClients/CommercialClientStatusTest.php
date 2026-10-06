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
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class CommercialClientStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    }

    #[DataProvider('statusTransitionProvider')]
    public function test_status_transition_changes_only_status_and_timestamp(string $initial, string $target): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Entidad Completa',
            'type' => CommercialClient::TYPE_AGREEMENT,
            'tax_id' => 'NIT-1',
            'phone' => '1234-5678',
            'email' => 'client@example.test',
            'address' => 'Dirección',
            'notes' => 'Notas',
            'status' => $initial,
        ]);
        $this->travel(5)->minutes();

        $this->statusRequest($user, $laboratory, $client->id, ['status' => $target])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $client->id,
                'name' => 'Entidad Completa',
                'type' => CommercialClient::TYPE_AGREEMENT,
                'tax_id' => 'NIT-1',
                'phone' => '1234-5678',
                'email' => 'client@example.test',
                'address' => 'Dirección',
                'notes' => 'Notas',
                'status' => $target,
                'created_at' => '2026-10-05T12:00:00.000000Z',
                'updated_at' => '2026-10-05T12:05:00.000000Z',
            ]])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.price_list_id');

        $this->assertDatabaseHas('commercial_clients', [
            'id' => $client->id,
            'laboratory_id' => $laboratory->id,
            'name' => 'Entidad Completa',
            'type' => CommercialClient::TYPE_AGREEMENT,
            'tax_id' => 'NIT-1',
            'phone' => '1234-5678',
            'email' => 'client@example.test',
            'address' => 'Dirección',
            'notes' => 'Notas',
            'status' => $target,
            'updated_at' => '2026-10-05 12:05:00',
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function statusTransitionProvider(): array
    {
        return [
            'active to inactive' => [CommercialClient::STATUS_ACTIVE, CommercialClient::STATUS_INACTIVE],
            'inactive to active' => [CommercialClient::STATUS_INACTIVE, CommercialClient::STATUS_ACTIVE],
        ];
    }

    #[DataProvider('statusNoopProvider')]
    public function test_same_status_is_idempotent_without_update_or_timestamp_change(string $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create(['status' => $status]);
        $updatedAt = $client->updated_at->toISOString();
        $rawUpdatedAt = $client->getRawOriginal('updated_at');
        $updates = [];
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (
                str_contains($query->sql, 'commercial_clients')
                && str_starts_with(strtolower(ltrim($query->sql)), 'update ')
            ) {
                $updates[] = $query->sql;
            }
        });

        $this->travel(10)->minutes();
        $this->statusRequest($user, $laboratory, $client->id, ['status' => $status])
            ->assertOk()
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.updated_at', $updatedAt);

        $this->assertCount(0, $updates);
        $this->assertSame($rawUpdatedAt, $this->commercialClientRow($client)->updated_at);
    }

    /** @return array<string, array{string}> */
    public static function statusNoopProvider(): array
    {
        return [
            'active to active' => [CommercialClient::STATUS_ACTIVE],
            'inactive to inactive' => [CommercialClient::STATUS_INACTIVE],
        ];
    }

    public function test_empty_and_missing_status_payloads_are_rejected_without_mutation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();

        $this->statusRequest($user, $laboratory, $client->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->statusRequest($user, $laboratory, $client->id, ['foo' => 'bar'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'foo']);

        $this->assertSame(CommercialClient::STATUS_ACTIVE, $this->commercialClientRow($client)->status);
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_status_is_strictly_validated(mixed $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();

        $this->statusRequest($user, $laboratory, $client->id, ['status' => $status])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertSame(CommercialClient::STATUS_ACTIVE, $this->commercialClientRow($client)->status);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'title active' => ['Active'],
            'uppercase active' => ['ACTIVE'],
            'uppercase inactive' => ['INACTIVE'],
            'leading whitespace active' => [' active'],
            'trailing whitespace active' => ['active '],
            'leading whitespace inactive' => [' inactive'],
            'trailing whitespace inactive' => ['inactive '],
            'enabled' => ['enabled'],
            'disabled' => ['disabled'],
            'true' => [true],
            'false' => [false],
            'zero' => [0],
            'one' => [1],
            'array' => [['active']],
            'object' => [(object) ['status' => 'active']],
        ];
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_every_other_field_is_rejected_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Original',
            'type' => CommercialClient::TYPE_COMPANY,
            'tax_id' => 'TAX',
            'phone' => '123',
            'email' => 'old@example.test',
            'address' => 'Address',
            'notes' => 'Notes',
        ]);
        $before = $this->commercialClientRow($client);

        $this->statusRequest($user, $laboratory, $client->id, [
            'status' => CommercialClient::STATUS_INACTIVE,
            $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->commercialClientRow($client));
    }

    /** @return array<string, array{string, mixed}> */
    public static function unknownFieldProvider(): array
    {
        return [
            'name' => ['name', 'Injected'],
            'type' => ['type', CommercialClient::TYPE_OTHER],
            'tax id' => ['tax_id', 'NEW'],
            'phone' => ['phone', '5555'],
            'email' => ['email', 'new@example.test'],
            'address' => ['address', 'New'],
            'notes' => ['notes', 'New'],
            'laboratory id' => ['laboratory_id', 999],
            'price list id' => ['price_list_id', 123],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_cross_tenant_and_nonexistent_invalid_requests_share_neutral_404(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $foreign = CommercialClient::factory()->for($laboratoryB)->create([
            'name' => 'Secret Client',
            'notes' => 'Secret Notes',
        ]);

        $crossTenant = $this->statusRequest($user, $laboratoryA, $foreign->id, ['foo' => 'bar'])
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $nonexistent = $this->statusRequest($user, $laboratoryA, 999999999, ['foo' => 'bar'])
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);

        $this->assertSame($crossTenant->getContent(), $nonexistent->getContent());
        $this->assertStringNotContainsString('Secret Client', $crossTenant->getContent());
        $this->assertStringNotContainsString('Secret Notes', $crossTenant->getContent());
        $this->assertSame(CommercialClient::STATUS_ACTIVE, $this->commercialClientRow($foreign)->status);
    }

    public function test_cross_tenant_valid_payload_is_not_found_and_cannot_mutate_foreign_record(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $foreign = CommercialClient::factory()->for($laboratoryB)->create();

        $this->statusRequest($user, $laboratoryA, $foreign->id, [
            'status' => CommercialClient::STATUS_INACTIVE,
        ])->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);

        $this->assertSame(CommercialClient::STATUS_ACTIVE, $this->commercialClientRow($foreign)->status);
    }

    public function test_valid_tenant_header_is_authoritative_when_switching_context(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $clientB = CommercialClient::factory()->for($laboratoryB)->create();

        $this->statusRequest($user, $laboratoryA, $clientB->id, ['status' => CommercialClient::STATUS_INACTIVE])
            ->assertNotFound();
        $this->statusRequest($user, $laboratoryB, $clientB->id, ['status' => CommercialClient::STATUS_INACTIVE])
            ->assertOk()->assertJsonPath('data.status', CommercialClient::STATUS_INACTIVE);
        $this->statusRequest($user, $laboratoryA, $clientB->id, ['status' => CommercialClient::STATUS_ACTIVE])
            ->assertNotFound();

        $this->assertSame(CommercialClient::STATUS_INACTIVE, $this->commercialClientRow($clientB)->status);
    }

    public function test_particular_name_has_no_special_status_behavior(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create(['name' => 'Particular']);

        $this->statusRequest($user, $laboratory, $client->id, ['status' => CommercialClient::STATUS_INACTIVE])
            ->assertOk()->assertJsonPath('data.status', CommercialClient::STATUS_INACTIVE);
    }

    public function test_real_change_uses_one_lookup_one_update_and_no_related_queries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower(ltrim($query->sql));
        });

        $this->statusRequest($user, $laboratory, $client->id, [
            'status' => CommercialClient::STATUS_INACTIVE,
        ])->assertOk();

        $clientQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'commercial_clients')));
        $this->assertCount(2, $clientQueries);
        $this->assertCount(1, array_filter($clientQueries, fn (string $sql): bool => str_starts_with($sql, 'select ')));
        $this->assertCount(1, array_filter($clientQueries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        $this->assertStringContainsString('"status" = ?', collect($clientQueries)->first(
            fn (string $sql): bool => str_starts_with($sql, 'update '),
        ));
        foreach (['price_lists', 'price_list_exams', 'patients', 'doctors', 'orders'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }

    public function test_price_list_injection_performs_no_price_list_queries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->statusRequest($user, $laboratory, $client->id, [
            'status' => CommercialClient::STATUS_INACTIVE,
            'price_list_id' => 123,
        ])->assertUnprocessable()->assertJsonValidationErrors(['price_list_id']);

        foreach (['price_lists', 'price_list_exams'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }

    public function test_saas_pipeline_precedes_lookup_and_validation(): void
    {
        $payload = ['foo' => 'bar'];
        $this->patchJson('/api/v1/commercial-clients/1/status', $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson('/api/v1/commercial-clients/1/status', $payload)
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->patchJson('/api/v1/commercial-clients/1/status', $payload)->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->patchJson('/api/v1/commercial-clients/1/status', $payload)->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);
        $this->statusRequest($user, $withoutMembership, 1, $payload)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        $this->createCurrentSubscription($inactiveMembership);
        $this->statusRequest($user, $inactiveMembership, 1, $payload)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveLaboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLaboratory, ['is_active' => true]);
        $this->createCurrentSubscription($inactiveLaboratory);
        $this->statusRequest($user, $inactiveLaboratory, 1, $payload)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->statusRequest($user, $withoutSubscription, 1, $payload)->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_non_numeric_identifiers_do_not_reach_lookup(string $identifier): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'commercial_clients')) {
                $queries[] = $query;
            }
        });

        $this->statusRequest($user, $laboratory, $identifier, ['status' => CommercialClient::STATUS_INACTIVE])
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
            'particular' => ['particular'],
        ];
    }

    public function test_runtime_controller_and_openapi_contract_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getActionName(), CommercialClientController::class.'@'))
            ->values();
        $statusRoute = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@updateStatus'));

        $this->assertCount(6, $routes);
        $this->assertNotNull($statusRoute);
        $this->assertSame(['PATCH'], $statusRoute->methods());
        $this->assertSame('api/v1/commercial-clients/{commercialClient}/status', $statusRoute->uri());
        $this->assertSame('[0-9]+', $statusRoute->wheres['commercialClient']);
        $this->assertContains('saas', $statusRoute->middleware());
        $this->assertFalse($routes->contains(fn ($route): bool => in_array('PUT', $route->methods(), true)));
        $this->assertFalse($routes->contains(fn ($route): bool => in_array('DELETE', $route->methods(), true)));
        $this->assertTrue($routes->contains(fn ($route): bool => str_ends_with($route->uri(), '/active')));

        $methods = collect((new ReflectionClass(CommercialClientController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === CommercialClientController::class)
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['active', 'index', 'show', 'store', 'update', 'updateStatus'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $document['paths']['/api/v1/commercial-clients/{commercialClient}/status'];
        $operation = $path['patch'];
        $schema = $document['components']['schemas']['UpdateCommercialClientStatusInput'];
        $pathParameter = collect($operation['parameters'])->firstWhere('name', 'commercialClient');

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['patch'], array_keys($path));
        $this->assertSame(['status'], $schema['required']);
        $this->assertSame(['status'], array_keys($schema['properties']));
        $this->assertSame(['active', 'inactive'], $schema['properties']['status']['enum']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('integer', $pathParameter['schema']['type']);
        $this->assertSame('int64', $pathParameter['schema']['format']);
        $this->assertSame(1, $pathParameter['schema']['minimum']);
        $this->assertSame('#/components/schemas/UpdateCommercialClientStatusInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));

        $verbs = array_flip(['get', 'post', 'put', 'patch', 'delete']);
        $operations = collect($document['paths'])->flatMap(
            fn (array $item): array => array_values(array_intersect_key($item, $verbs)),
        );
        $this->assertCount(65, $operations);
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

    /** @param array<string, mixed> $payload */
    private function statusRequest(
        User $user,
        Laboratory $laboratory,
        int|string $commercialClient,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/commercial-clients/{$commercialClient}/status", $payload);
    }

    private function commercialClientRow(CommercialClient $commercialClient): object
    {
        return DB::table('commercial_clients')->where('id', $commercialClient->id)->firstOrFail();
    }
}
