<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialClientStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 15:00:00', 'UTC'));
    }

    public function test_complete_commercial_client_is_created_active_for_current_tenant(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $notes = str_repeat('Observación extensa. ', 100);

        $response = $this->commercialClientRequest($user, $laboratory, [
            'name' => '  Seguros Ejemplo  ',
            'type' => CommercialClient::TYPE_INSURANCE,
            'tax_id' => '  NIT-123  ',
            'phone' => '  +502 2222-3333  ',
            'email' => '  Contacto@Example.COM  ',
            'address' => '  Ciudad de Guatemala  ',
            'notes' => "  {$notes}  ",
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Seguros Ejemplo')
            ->assertJsonPath('data.type', CommercialClient::TYPE_INSURANCE)
            ->assertJsonPath('data.tax_id', 'NIT-123')
            ->assertJsonPath('data.phone', '+502 2222-3333')
            ->assertJsonPath('data.email', 'Contacto@Example.COM')
            ->assertJsonPath('data.address', 'Ciudad de Guatemala')
            ->assertJsonPath('data.notes', trim($notes))
            ->assertJsonPath('data.status', CommercialClient::STATUS_ACTIVE)
            ->assertJsonPath('data.created_at', '2026-10-02T15:00:00.000000Z')
            ->assertJsonPath('data.updated_at', '2026-10-02T15:00:00.000000Z');

        $client = CommercialClient::query()->sole();
        $this->assertSame($laboratory->id, $client->laboratory_id);
        $this->assertSame(CommercialClient::STATUS_ACTIVE, $client->status);
        $this->assertSame(trim($notes), $client->notes);
        $this->assertGreaterThan(1000, mb_strlen($client->notes));
    }

    public function test_minimal_create_uses_database_defaults_and_null_optionals(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $response = $this->commercialClientRequest($user, $laboratory, [
            'name' => 'Empresa X',
            'type' => CommercialClient::TYPE_COMPANY,
        ])->assertCreated();

        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $response->assertJsonPath("data.{$field}", null);
        }
        $response->assertJsonPath('data.status', CommercialClient::STATUS_ACTIVE);

        $client = CommercialClient::query()->sole();
        $this->assertSame($laboratory->id, $client->laboratory_id);
        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $this->assertNull($client->getAttribute($field));
        }
    }

    #[DataProvider('validTypeProvider')]
    public function test_every_approved_type_can_be_created(string $type): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory, [
            'name' => "Entidad {$type}",
            'type' => $type,
        ])->assertCreated()->assertJsonPath('data.type', $type);

        $this->assertDatabaseHas('commercial_clients', [
            'laboratory_id' => $laboratory->id,
            'type' => $type,
        ]);
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
    public function test_invalid_type_is_rejected_strictly(mixed $type): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory, [
            'name' => 'Entidad inválida',
            'type' => $type,
        ])->assertUnprocessable()->assertJsonValidationErrors(['type']);

        $this->assertDatabaseCount('commercial_clients', 0);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidTypeProvider(): array
    {
        return [
            'invalid' => ['invalid'],
            'title case' => ['Insurance'],
            'uppercase' => ['INSURANCE'],
            'translated' => ['seguro'],
            'surrounding whitespace' => [' insurance '],
            'empty' => [''],
            'integer' => [1],
            'array' => [['insurance']],
        ];
    }

    #[DataProvider('missingRequiredProvider')]
    public function test_required_fields_are_enforced(array $payload, array $errors): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('commercial_clients', 0);
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function missingRequiredProvider(): array
    {
        return [
            'name missing' => [['type' => 'company'], ['name']],
            'type missing' => [['name' => 'Empresa'], ['type']],
            'both missing' => [[], ['name', 'type']],
            'blank name' => [['name' => '   ', 'type' => 'company'], ['name']],
        ];
    }

    public function test_length_boundaries_and_unbounded_text_notes_are_accepted(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $email = str_repeat('a', 40).'@'.str_repeat('b', 52).'.'.str_repeat('c', 52).'.com';

        $this->commercialClientRequest($user, $laboratory, [
            'name' => str_repeat('n', 150),
            'type' => 'company',
            'tax_id' => str_repeat('t', 50),
            'phone' => str_repeat('p', 30),
            'email' => $email,
            'address' => str_repeat('a', 255),
            'notes' => str_repeat('nota ', 1000),
        ])->assertCreated();

        $client = CommercialClient::query()->sole();
        $this->assertSame(150, mb_strlen($client->name));
        $this->assertSame(150, mb_strlen($client->email));
        $this->assertSame(4999, mb_strlen($client->notes));
    }

    #[DataProvider('overLengthProvider')]
    public function test_length_overflow_is_rejected(string $field, string $value): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory, array_merge($this->validPayload(), [
            $field => $value,
        ]))->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('commercial_clients', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function overLengthProvider(): array
    {
        return [
            'name 151' => ['name', str_repeat('n', 151)],
            'tax id 51' => ['tax_id', str_repeat('t', 51)],
            'phone 31' => ['phone', str_repeat('p', 31)],
            'email 151' => ['email', str_repeat('a', 60).'@'.str_repeat('b', 42).'.'.str_repeat('c', 43).'.com'],
            'address 256' => ['address', str_repeat('a', 256)],
        ];
    }

    public function test_email_validation_accepts_valid_null_and_omitted_but_rejects_invalid(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach ([['email' => 'valid@example.test'], ['email' => null], []] as $index => $email) {
            $this->commercialClientRequest($user, $laboratory, array_merge([
                'name' => "Empresa {$index}",
                'type' => 'company',
            ], $email))->assertCreated();
        }

        $this->commercialClientRequest($user, $laboratory, [
            'name' => 'Email inválido',
            'type' => 'company',
            'email' => 'not-an-email',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_explicit_null_optionals_are_persisted_as_null(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $payload = $this->validPayload();

        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $payload[$field] = null;
        }

        $this->commercialClientRequest($user, $laboratory, $payload)->assertCreated();

        $client = CommercialClient::query()->sole();
        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $this->assertNull($client->getAttribute($field));
        }
    }

    public function test_empty_optional_strings_follow_global_null_conversion(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $payload = $this->validPayload();

        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $index => $field) {
            $payload[$field] = $index % 2 === 0 ? '' : '   ';
        }

        $this->commercialClientRequest($user, $laboratory, $payload)->assertCreated();

        $client = CommercialClient::query()->sole();
        foreach (['tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $this->assertNull($client->getAttribute($field));
        }
    }

    #[DataProvider('prohibitedFieldProvider')]
    public function test_prohibited_and_unknown_fields_are_rejected(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory, array_merge($this->validPayload(), [
            $field => $value,
        ]))->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('commercial_clients', 0);
    }

    /** @return array<string, array{string, mixed}> */
    public static function prohibitedFieldProvider(): array
    {
        return [
            'id' => ['id', 123],
            'laboratory' => ['laboratory_id', 123],
            'status' => ['status', 'inactive'],
            'price list' => ['price_list_id', 1],
            'branch' => ['branch_id', 1],
            'patient' => ['patient_id', 1],
            'doctor' => ['doctor_id', 1],
            'created at' => ['created_at', '2026-01-01'],
            'updated at' => ['updated_at', '2026-01-01'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_tenant_injection_cannot_create_any_row(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);

        $this->commercialClientRequest($user, $laboratoryA, array_merge($this->validPayload(), [
            'laboratory_id' => $laboratoryB->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['laboratory_id']);

        $this->assertDatabaseCount('commercial_clients', 0);
    }

    public function test_successful_create_is_owned_only_by_header_tenant(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);

        $id = $this->commercialClientRequest($user, $laboratoryA, $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->assertTrue(CommercialClient::forLaboratory($laboratoryA)->whereKey($id)->exists());
        $this->assertFalse(CommercialClient::forLaboratory($laboratoryB)->whereKey($id)->exists());
    }

    public function test_duplicate_name_is_rejected_only_within_current_tenant(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        CommercialClient::factory()->for($laboratoryA)->create(['name' => 'Empresa X']);

        $this->commercialClientRequest($user, $laboratoryA, $this->validPayload(['name' => 'Empresa X']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $this->commercialClientRequest($user, $laboratoryB, $this->validPayload(['name' => 'Empresa X']))
            ->assertCreated();

        $this->assertSame(1, CommercialClient::forLaboratory($laboratoryA)->where('name', 'Empresa X')->count());
        $this->assertSame(1, CommercialClient::forLaboratory($laboratoryB)->where('name', 'Empresa X')->count());
    }

    public function test_name_uniqueness_preserves_case_sensitive_database_semantics(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        CommercialClient::factory()->for($laboratory)->create(['name' => 'Empresa X']);

        $this->commercialClientRequest($user, $laboratory, $this->validPayload([
            'name' => 'empresa x',
        ]))->assertCreated();

        $this->assertSame(2, CommercialClient::forLaboratory($laboratory)->count());
    }

    public function test_tax_id_email_and_phone_are_not_unique(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $duplicates = [
            'tax_id' => 'SHARED-TAX',
            'phone' => '+502 2222-3333',
            'email' => 'shared@example.test',
        ];

        CommercialClient::factory()->for($laboratory)->create(array_merge($duplicates, ['name' => 'Primera']));
        $this->commercialClientRequest($user, $laboratory, array_merge(
            $this->validPayload(['name' => 'Segunda']),
            $duplicates,
        ))->assertCreated();

        foreach (array_keys($duplicates) as $field) {
            $this->assertSame(2, CommercialClient::query()->where($field, $duplicates[$field])->count());
        }
    }

    public function test_particular_is_allowed_only_as_an_explicit_commercial_name(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->commercialClientRequest($user, $laboratory, $this->validPayload([
            'name' => 'Particular',
        ]))->assertCreated()->assertJsonPath('data.name', 'Particular');

        $this->assertDatabaseCount('commercial_clients', 1);
    }

    public function test_response_uses_exact_resource_contract_without_internal_fields(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $response = $this->commercialClientRequest($user, $laboratory, $this->validPayload())
            ->assertCreated();

        $this->assertSame([
            'id', 'name', 'type', 'tax_id', 'phone', 'email', 'address', 'notes',
            'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $response
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.price_list_id');
    }

    public function test_saas_pipeline_rejects_invalid_contexts_before_creation(): void
    {
        $this->postJson('/api/v1/commercial-clients', [])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/api/v1/commercial-clients', [])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->postJson('/api/v1/commercial-clients', [])->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->postJson('/api/v1/commercial-clients', [])->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);
        $this->commercialClientRequest($user, $withoutMembership, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        $this->createCurrentSubscription($inactiveMembership);
        $this->commercialClientRequest($user, $inactiveMembership, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->createCurrentSubscription($inactive);
        $this->commercialClientRequest($user, $inactive, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->commercialClientRequest($user, $withoutSubscription, [])->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $futureSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($futureSubscription, ['is_active' => true]);
        Subscription::factory()->for($futureSubscription)->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $this->commercialClientRequest($user, $futureSubscription, [])->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_NOT_STARTED');

        $this->assertDatabaseCount('commercial_clients', 0);
    }

    public function test_unique_constraint_race_becomes_safe_name_validation_error(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $inserted = false;

        CommercialClient::creating(function (CommercialClient $client) use (&$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::table('commercial_clients')->insert([
                'laboratory_id' => $client->laboratory_id,
                'name' => $client->name,
                'type' => CommercialClient::TYPE_COMPANY,
                'status' => CommercialClient::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->commercialClientRequest($user, $laboratory, $this->validPayload())
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);

            $this->assertNoDatabaseDetails($response);
            $this->assertTrue($inserted);
            if (DB::getDriverName() !== 'pgsql') {
                $this->assertDatabaseCount('commercial_clients', 0);
            }
        } finally {
            CommercialClient::flushEventListeners();
        }
    }

    public function test_unrelated_database_exception_is_rethrown(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        CommercialClient::creating(function (CommercialClient $client): void {
            DB::table('commercial_clients')->insert([
                'laboratory_id' => $client->laboratory_id,
                'name' => 'Broken insert',
                'type' => 'invalid',
                'status' => CommercialClient::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->commercialClientRequest($user, $laboratory, $this->validPayload());
        } finally {
            CommercialClient::flushEventListeners();
        }
    }

    public function test_create_has_stable_query_shape_without_related_catalog_queries_or_refresh(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->commercialClientRequest($user, $laboratory, $this->validPayload())->assertCreated();

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $clientQueries = $queries
            ->filter(fn (array $query): bool => str_contains($query['query'], 'commercial_clients'))
            ->values();

        $this->assertCount(2, $clientQueries);
        $this->assertStringStartsWith('select', strtolower(ltrim($clientQueries->first()['query'])));
        $this->assertStringStartsWith('insert', strtolower(ltrim($clientQueries->last()['query'])));
        foreach (['price_lists', 'price_list_exams', 'patients', 'doctors', 'orders'] as $table) {
            $this->assertFalse($queries->contains(fn (array $query): bool => str_contains($query['query'], $table)));
        }
    }

    public function test_runtime_and_openapi_expose_only_get_and_post_commercial_client_operations(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'api/v1/commercial-clients')
            ->values();

        $this->assertCount(2, $routes);
        $this->assertEqualsCanonicalizing(['GET|HEAD', 'POST'], $routes->map(
            fn ($route): string => implode('|', $route->methods()),
        )->all());
        foreach ($routes as $route) {
            $this->assertContains('saas', $route->middleware());
        }

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $path = $document['paths']['/api/v1/commercial-clients'];
        $post = $path['post'];
        $schema = $document['components']['schemas']['CreateCommercialClientInput'];

        $this->assertSame(['get', 'post'], array_keys($path));
        $this->assertSame([201, 400, 401, 403, 422], array_keys($post['responses']));
        $this->assertSame(['name', 'type'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertEqualsCanonicalizing([
            'name', 'type', 'tax_id', 'phone', 'email', 'address', 'notes',
        ], array_keys($schema['properties']));
        $this->assertArrayNotHasKey('status', $schema['properties']);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Empresa X',
            'type' => CommercialClient::TYPE_COMPANY,
        ], $overrides);
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
    private function commercialClientRequest(
        User $user,
        Laboratory $laboratory,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/commercial-clients', $payload);
    }

    private function assertNoDatabaseDetails(TestResponse $response): void
    {
        $body = $response->getContent();

        foreach (['SQLSTATE', 'constraint', '/home/', '/var/www', 'App\\', 'Illuminate\\', 'trace', 'bindings'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
