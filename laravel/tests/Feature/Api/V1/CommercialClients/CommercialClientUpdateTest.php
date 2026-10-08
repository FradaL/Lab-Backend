<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Http\Controllers\Api\V1\CommercialClientController;
use App\Models\CommercialClient;
use App\Models\Laboratory;
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

class CommercialClientUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
    }

    public function test_single_field_patch_is_partial_and_returns_exact_resource(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->inactive()->for($laboratory)->create([
            'name' => 'Empresa Original',
            'type' => CommercialClient::TYPE_COMPANY,
            'tax_id' => 'NIT-1',
            'phone' => '1111-1111',
            'email' => 'original@example.test',
            'address' => 'Dirección original',
            'notes' => 'Notas originales',
        ]);
        $this->travel(5)->minutes();

        $this->updateRequest($user, $laboratory, $client->id, ['phone' => '  5555-5555  '])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $client->id,
                'name' => 'Empresa Original',
                'type' => CommercialClient::TYPE_COMPANY,
                'tax_id' => 'NIT-1',
                'phone' => '5555-5555',
                'email' => 'original@example.test',
                'address' => 'Dirección original',
                'notes' => 'Notas originales',
                'status' => CommercialClient::STATUS_INACTIVE,
                'created_at' => '2026-10-04T12:00:00.000000Z',
                'updated_at' => '2026-10-04T12:05:00.000000Z',
            ]])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.price_list_id');

        $this->assertDatabaseHas('commercial_clients', [
            'id' => $client->id,
            'laboratory_id' => $laboratory->id,
            'name' => 'Empresa Original',
            'type' => CommercialClient::TYPE_COMPANY,
            'tax_id' => 'NIT-1',
            'phone' => '5555-5555',
            'email' => 'original@example.test',
            'address' => 'Dirección original',
            'notes' => 'Notas originales',
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);
    }

    public function test_all_editable_fields_update_together(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();

        $payload = [
            'name' => '  Convenio Actualizado  ',
            'type' => CommercialClient::TYPE_AGREEMENT,
            'tax_id' => '  TAX-2  ',
            'phone' => '  2222-2222  ',
            'email' => '  nuevo@example.test  ',
            'address' => '  Nueva dirección  ',
            'notes' => '  Nuevas notas  ',
        ];

        $this->updateRequest($user, $laboratory, $client->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Convenio Actualizado')
            ->assertJsonPath('data.type', CommercialClient::TYPE_AGREEMENT)
            ->assertJsonPath('data.tax_id', 'TAX-2')
            ->assertJsonPath('data.phone', '2222-2222')
            ->assertJsonPath('data.email', 'nuevo@example.test')
            ->assertJsonPath('data.address', 'Nueva dirección')
            ->assertJsonPath('data.notes', 'Nuevas notas');

        $this->assertDatabaseHas('commercial_clients', [
            'id' => $client->id,
            'name' => 'Convenio Actualizado',
            'type' => CommercialClient::TYPE_AGREEMENT,
            'tax_id' => 'TAX-2',
            'phone' => '2222-2222',
            'email' => 'nuevo@example.test',
            'address' => 'Nueva dirección',
            'notes' => 'Nuevas notas',
        ]);
    }

    public function test_nullable_fields_can_be_cleared_without_changing_omitted_fields(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Original',
            'type' => CommercialClient::TYPE_INSURANCE,
            'tax_id' => 'TAX',
            'phone' => '123',
            'email' => 'old@example.test',
            'address' => 'Address',
            'notes' => 'Notes',
        ]);

        $this->updateRequest($user, $laboratory, $client->id, [
            'tax_id' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'notes' => null,
        ])->assertOk();

        $row = $this->commercialClientRow($client);
        $this->assertSame('Original', $row->name);
        $this->assertSame(CommercialClient::TYPE_INSURANCE, $row->type);
        $this->assertSame(CommercialClient::STATUS_ACTIVE, $row->status);
        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $this->assertNull($row->{$field});
        }
    }

    public function test_updating_only_email_preserves_every_other_field(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->inactive()->for($laboratory)->create([
            'name' => 'Original',
            'type' => CommercialClient::TYPE_OTHER,
            'tax_id' => 'TAX',
            'phone' => '123',
            'email' => 'old@example.test',
            'address' => 'Address',
            'notes' => 'Notes',
        ]);

        $this->updateRequest($user, $laboratory, $client->id, ['email' => 'new@example.test'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.test');

        $this->assertDatabaseHas('commercial_clients', [
            'id' => $client->id,
            'laboratory_id' => $laboratory->id,
            'name' => 'Original',
            'type' => CommercialClient::TYPE_OTHER,
            'tax_id' => 'TAX',
            'phone' => '123',
            'address' => 'Address',
            'notes' => 'Notes',
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);
    }

    public function test_empty_and_unknown_only_payloads_are_rejected_without_writes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $before = $this->commercialClientRow($client);

        $this->updateRequest($user, $laboratory, $client->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['payload']);
        $this->updateRequest($user, $laboratory, $client->id, ['foo' => 'bar'])
            ->assertUnprocessable()->assertJsonValidationErrors(['foo', 'payload']);

        $this->assertEquals($before, $this->commercialClientRow($client));
    }

    #[DataProvider('invalidNameProvider')]
    public function test_name_validation_is_strict_and_atomic(mixed $name): void
    {
        $this->assertInvalidField(['name' => $name], 'name');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidNameProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'integer' => [123],
            'array' => [['name']],
            'too long' => [str_repeat('n', 151)],
        ];
    }

    #[DataProvider('validTypeProvider')]
    public function test_every_valid_type_can_be_assigned(string $type): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();

        $this->updateRequest($user, $laboratory, $client->id, ['type' => $type])
            ->assertOk()->assertJsonPath('data.type', $type);
    }

    /** @return array<string, array{string}> */
    public static function validTypeProvider(): array
    {
        return [
            'insurance' => [CommercialClient::TYPE_INSURANCE],
            'company' => [CommercialClient::TYPE_COMPANY],
            'agreement' => [CommercialClient::TYPE_AGREEMENT],
            'other' => [CommercialClient::TYPE_OTHER],
        ];
    }

    #[DataProvider('invalidTypeProvider')]
    public function test_type_validation_is_exact_and_atomic(mixed $type): void
    {
        $this->assertInvalidField(['type' => $type], 'type');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidTypeProvider(): array
    {
        return [
            'title case' => ['Insurance'],
            'uppercase' => ['INSURANCE'],
            'translated' => ['seguro'],
            'leading whitespace' => [' insurance'],
            'trailing whitespace' => ['insurance '],
            'invalid' => ['invalid'],
            'null' => [null],
            'integer' => [1],
            'array' => [['insurance']],
        ];
    }

    public function test_text_boundaries_and_long_notes_are_accepted(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 21);
        $payload = [
            'name' => str_repeat('n', 150),
            'tax_id' => str_repeat('t', 50),
            'phone' => str_repeat('p', 30),
            'email' => $email,
            'address' => str_repeat('a', 255),
            'notes' => str_repeat('nota ', 999).'nota',
        ];

        $this->updateRequest($user, $laboratory, $client->id, $payload)->assertOk();

        foreach ($payload as $field => $value) {
            $this->assertSame($value, $this->commercialClientRow($client)->{$field});
        }
    }

    #[DataProvider('overBoundaryProvider')]
    public function test_text_fields_reject_boundary_plus_one(string $field, string $value): void
    {
        $this->assertInvalidField([$field => $value], $field);
    }

    /** @return array<string, array{string, string}> */
    public static function overBoundaryProvider(): array
    {
        return [
            'name' => ['name', str_repeat('n', 151)],
            'tax id' => ['tax_id', str_repeat('t', 51)],
            'phone' => ['phone', str_repeat('p', 31)],
            'email' => ['email', str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 22)],
            'address' => ['address', str_repeat('a', 256)],
        ];
    }

    public function test_email_accepts_valid_value_and_null_but_rejects_invalid_value(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create(['email' => 'old@example.test']);

        $this->updateRequest($user, $laboratory, $client->id, ['email' => 'valid@example.test'])
            ->assertOk()->assertJsonPath('data.email', 'valid@example.test');
        $this->updateRequest($user, $laboratory, $client->id, ['email' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->updateRequest($user, $laboratory, $client->id, ['email' => null])
            ->assertOk()->assertJsonPath('data.email', null);
    }

    #[DataProvider('protectedFieldProvider')]
    public function test_protected_and_unknown_fields_are_rejected_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create(['name' => 'Original']);

        $this->updateRequest($user, $laboratory, $client->id, [
            'name' => 'Would change',
            $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame('Original', $this->commercialClientRow($client)->name);
        $this->assertSame($laboratory->id, $this->commercialClientRow($client)->laboratory_id);
    }

    /** @return array<string, array{string, mixed}> */
    public static function protectedFieldProvider(): array
    {
        return [
            'id' => ['id', 999],
            'laboratory id' => ['laboratory_id', 999],
            'status' => ['status', CommercialClient::STATUS_INACTIVE],
            'price list id' => ['price_list_id', 1],
            'branch id' => ['branch_id', 1],
            'patient id' => ['patient_id', 1],
            'doctor id' => ['doctor_id', 1],
            'created at' => ['created_at', '2020-01-01'],
            'updated at' => ['updated_at', '2020-01-01'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_name_uniqueness_is_tenant_scoped_case_sensitive_and_ignores_current_row(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $targetA = CommercialClient::factory()->for($laboratoryA)->create(['name' => 'Original']);
        CommercialClient::factory()->for($laboratoryA)->create(['name' => 'Empresa X']);
        $targetB = CommercialClient::factory()->for($laboratoryB)->create(['name' => 'Empresa Y']);

        $this->updateRequest($user, $laboratoryA, $targetA->id, ['name' => ' Original '])
            ->assertOk()->assertJsonPath('data.name', 'Original');
        $this->updateRequest($user, $laboratoryA, $targetA->id, ['name' => 'Empresa X'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->updateRequest($user, $laboratoryA, $targetA->id, ['name' => 'empresa x'])
            ->assertOk()->assertJsonPath('data.name', 'empresa x');
        $this->updateRequest($user, $laboratoryB, $targetB->id, ['name' => 'Empresa X'])
            ->assertOk()->assertJsonPath('data.name', 'Empresa X');
    }

    public function test_unique_race_maps_to_name_validation_without_partial_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Original',
            'phone' => 'Original phone',
        ]);
        $postgresSavepoint = DB::getDriverName() === 'pgsql';

        if ($postgresSavepoint) {
            DB::statement('SAVEPOINT commercial_client_update_race');
        }

        CommercialClient::updating(function (CommercialClient $updating): void {
            DB::table('commercial_clients')->insert([
                'laboratory_id' => $updating->laboratory_id,
                'name' => 'RACE',
                'type' => CommercialClient::TYPE_COMPANY,
                'status' => CommercialClient::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->updateRequest($user, $laboratory, $client->id, [
                'name' => 'RACE',
                'phone' => 'Must not persist',
            ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

            if ($postgresSavepoint) {
                DB::statement('ROLLBACK TO SAVEPOINT commercial_client_update_race');
                DB::statement('RELEASE SAVEPOINT commercial_client_update_race');
                $postgresSavepoint = false;
            }

            $this->assertDatabaseHas('commercial_clients', [
                'id' => $client->id,
                'name' => 'Original',
                'phone' => 'Original phone',
            ]);
        } finally {
            if ($postgresSavepoint) {
                DB::statement('ROLLBACK TO SAVEPOINT commercial_client_update_race');
                DB::statement('RELEASE SAVEPOINT commercial_client_update_race');
            }

            CommercialClient::flushEventListeners();
        }
    }

    public function test_unrelated_query_exception_is_rethrown(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();

        CommercialClient::updating(function (CommercialClient $updating): void {
            DB::table('commercial_clients')->where('id', $updating->id)->update(['status' => null]);
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->updateRequest($user, $laboratory, $client->id, ['phone' => 'Changed']);
        } finally {
            CommercialClient::flushEventListeners();
        }
    }

    public function test_cross_tenant_and_nonexistent_ids_return_same_404_before_validation(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $foreign = CommercialClient::factory()->for($laboratoryB)->create(['name' => 'Secret']);
        $invalid = ['foo' => 'bar', 'status' => 'inactive', 'name' => []];

        $crossTenant = $this->updateRequest($user, $laboratoryA, $foreign->id, $invalid)
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $nonexistent = $this->updateRequest($user, $laboratoryA, 999999999, $invalid)
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);

        $this->assertSame($crossTenant->getContent(), $nonexistent->getContent());
        $this->assertSame('Secret', $this->commercialClientRow($foreign)->name);
    }

    public function test_valid_resource_with_invalid_payload_returns_validation_error(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();

        $this->updateRequest($user, $laboratory, $client->id, ['foo' => 'bar', 'name' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['foo', 'name']);
    }

    public function test_noop_does_not_update_or_check_uniqueness_and_preserves_timestamp(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Original',
            'type' => CommercialClient::TYPE_COMPANY,
            'phone' => '123',
        ]);
        $updatedAt = $client->updated_at->toISOString();
        $rawUpdatedAt = $client->getRawOriginal('updated_at');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'commercial_clients')) {
                $queries[] = strtolower(ltrim($query->sql));
            }
        });

        $this->travel(10)->minutes();
        $this->updateRequest($user, $laboratory, $client->id, [
            'name' => ' Original ',
            'type' => CommercialClient::TYPE_COMPANY,
            'phone' => ' 123 ',
        ])->assertOk()->assertJsonPath('data.updated_at', $updatedAt);

        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select count(*)')));
        $this->assertSame($rawUpdatedAt, $this->commercialClientRow($client)->updated_at);
    }

    public function test_simple_update_uses_one_lookup_one_update_and_no_related_queries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->inactive()->for($laboratory)->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower(ltrim($query->sql));
        });

        $this->updateRequest($user, $laboratory, $client->id, ['phone' => '5555'])
            ->assertOk()->assertJsonPath('data.status', CommercialClient::STATUS_INACTIVE);

        $clientQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'commercial_clients')));
        $this->assertCount(2, $clientQueries);
        $this->assertCount(1, array_filter($clientQueries, fn (string $sql): bool => str_starts_with($sql, 'select ')));
        $this->assertCount(1, array_filter($clientQueries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        foreach (['price_lists', 'price_list_exams', 'patients', 'doctors', 'orders'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }

    public function test_empty_strings_follow_laravel_normalization(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'tax_id' => 'TAX',
            'phone' => '123',
            'email' => 'old@example.test',
            'address' => 'Address',
            'notes' => 'Notes',
        ]);

        $this->updateRequest($user, $laboratory, $client->id, [
            'tax_id' => '',
            'phone' => '   ',
            'email' => '',
            'address' => '   ',
            'notes' => '',
        ])->assertOk();

        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $this->assertNull($this->commercialClientRow($client)->{$field});
        }
    }

    public function test_saas_pipeline_precedes_lookup_and_validation(): void
    {
        $this->patchJson('/api/v1/commercial-clients/1', [])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson('/api/v1/commercial-clients/1', [])
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->patchJson('/api/v1/commercial-clients/1', [])->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->patchJson('/api/v1/commercial-clients/1', [])->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);
        $this->updateRequest($user, $withoutMembership, 1, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        $this->createCurrentSubscription($inactiveMembership);
        $this->updateRequest($user, $inactiveMembership, 1, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveLaboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLaboratory, ['is_active' => true]);
        $this->createCurrentSubscription($inactiveLaboratory);
        $this->updateRequest($user, $inactiveLaboratory, 1, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->updateRequest($user, $withoutSubscription, 1, [])->assertForbidden()
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

        $this->updateRequest($user, $laboratory, $identifier, ['name' => 'Changed'])
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
        $patch = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@update'));

        $this->assertCount(6, $routes);
        $this->assertNotNull($patch);
        $this->assertSame(['PATCH'], $patch->methods());
        $this->assertSame('[0-9]+', $patch->wheres['commercialClient']);
        $this->assertContains('saas', $patch->middleware());
        $this->assertFalse($routes->contains(fn ($route): bool => in_array('PUT', $route->methods(), true)));

        $methods = collect((new ReflectionClass(CommercialClientController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === CommercialClientController::class)
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['active', 'index', 'show', 'store', 'update', 'updateStatus'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $document['paths']['/api/v1/commercial-clients/{commercialClient}'];
        $operation = $path['patch'];
        $schema = $document['components']['schemas']['UpdateCommercialClientInput'];

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['get', 'patch'], array_keys($path));
        $this->assertEqualsCanonicalizing(
            ['name', 'type', 'tax_id', 'phone', 'email', 'address', 'notes'],
            array_keys($schema['properties']),
        );
        $this->assertArrayNotHasKey('required', $schema);
        $this->assertSame(1, $schema['minProperties']);
        $this->assertFalse($schema['additionalProperties']);
        foreach (['status', 'laboratory_id', 'price_list_id'] as $field) {
            $this->assertArrayNotHasKey($field, $schema['properties']);
        }
        $this->assertSame('#/components/schemas/UpdateCommercialClientInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));

        $verbs = array_flip(['get', 'post', 'put', 'patch', 'delete']);
        $operations = collect($document['paths'])->flatMap(
            fn (array $item): array => array_values(array_intersect_key($item, $verbs)),
        );
        $this->assertCount(69, $operations);
        $this->assertCount(6, $operations->filter(
            fn (array $item): bool => in_array('Commercial Clients', $item['tags'] ?? [], true),
        ));
        $this->assertCount(5, $operations->filter(
            fn (array $item): bool => in_array('Exam Prices', $item['tags'] ?? [], true),
        ));
    }

    /** @param array<string, mixed> $payload */
    private function assertInvalidField(array $payload, string $field): void
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

        $this->updateRequest($user, $laboratory, $client->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->commercialClientRow($client));
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
    private function updateRequest(
        User $user,
        Laboratory $laboratory,
        int|string $commercialClient,
        array $payload,
    ): TestResponse {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'commercial_clients.update');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/commercial-clients/{$commercialClient}", $payload);
    }

    private function commercialClientRow(CommercialClient $commercialClient): object
    {
        return DB::table('commercial_clients')->where('id', $commercialClient->id)->firstOrFail();
    }
}
