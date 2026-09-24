<?php

namespace Tests\Feature\Api\V1\SampleTypes;

use App\Models\Laboratory;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SampleTypeStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
    }

    public function test_sample_type_is_created_with_current_tenant_database_default_and_exact_resource(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $response = $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sangre')
            ->assertJsonPath('data.status', SampleType::STATUS_ACTIVE)
            ->assertJsonPath('data.created_at', '2026-09-24T12:00:00.000000Z')
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.updated_at')
            ->assertJsonMissingPath('data.laboratory');

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'status', 'created_at'],
            array_keys($response->json('data')),
        );
        $this->assertDatabaseCount('sample_types', 1);

        $sampleType = SampleType::query()->sole();

        $this->assertSame($laboratory->id, $sampleType->laboratory_id);
        $this->assertSame('Sangre', $sampleType->name);
        $this->assertSame(SampleType::STATUS_ACTIVE, $sampleType->status);
    }

    public function test_name_is_trimmed_and_created_type_is_visible_through_existing_index(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $sampleTypeId = $this->sampleTypeRequest($user, $laboratory, [
            'name' => '  Suero  ',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Suero')
            ->json('data.id');

        $this->assertDatabaseHas('sample_types', [
            'id' => $sampleTypeId,
            'laboratory_id' => $laboratory->id,
            'name' => 'Suero',
            'status' => SampleType::STATUS_ACTIVE,
        ]);

        $this->sampleTypeIndexRequest($user, $laboratory, ['search' => 'sue'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sampleTypeId);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected_without_writes(array $payload, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $response = $this->sampleTypeRequest($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('sample_types', 0);
        $this->assertNoDatabaseDetails($response);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'name missing' => [[], 'name'],
            'name null' => [['name' => null], 'name'],
            'name empty' => [['name' => ''], 'name'],
            'name whitespace only' => [['name' => '   '], 'name'],
            'name too long after normalization' => [['name' => '  '.str_repeat('A', 101).'  '], 'name'],
            'name array' => [['name' => ['Sangre']], 'name'],
            'name object' => [['name' => (object) ['value' => 'Sangre']], 'name'],
            'name boolean' => [['name' => true], 'name'],
            'name integer' => [['name' => 123], 'name'],
            'name float' => [['name' => 1.5], 'name'],
            'id controlled by server' => [['name' => 'Sangre', 'id' => 123], 'id'],
            'laboratory id controlled by server' => [['name' => 'Sangre', 'laboratory_id' => 999], 'laboratory_id'],
            'active status controlled by server' => [['name' => 'Sangre', 'status' => 'active'], 'status'],
            'inactive status controlled by server' => [['name' => 'Sangre', 'status' => 'inactive'], 'status'],
            'created at controlled by server' => [['name' => 'Sangre', 'created_at' => '2020-01-01'], 'created_at'],
            'updated at controlled by server' => [['name' => 'Sangre', 'updated_at' => '2020-01-01'], 'updated_at'],
            'unknown property' => [['name' => 'Sangre', 'foo' => 'bar'], 'foo'],
            'description unknown' => [['name' => 'Sangre', 'description' => 'Texto'], 'description'],
            'code unknown' => [['name' => 'Sangre', 'code' => 'SAN'], 'code'],
            'branch id unknown' => [['name' => 'Sangre', 'branch_id' => 1], 'branch_id'],
        ];
    }

    public function test_duplicate_name_is_rejected_only_within_current_laboratory(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);

        $response = $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, SampleType::forLaboratory($laboratory)->count());
        $this->assertNoDatabaseDetails($response);
    }

    public function test_trimmed_duplicate_is_rejected(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);

        $this->sampleTypeRequest($user, $laboratory, ['name' => '  Sangre  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, SampleType::forLaboratory($laboratory)->count());
    }

    public function test_inactive_duplicate_is_rejected_without_reactivation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->inactive()->for($laboratory)->create([
            'name' => 'Sangre',
        ]);

        $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('sample_types', 1);
        $this->assertSame(SampleType::STATUS_INACTIVE, $sampleType->fresh()->status);
    }

    public function test_same_name_is_allowed_in_different_laboratories(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $sampleTypeA = $this->sampleTypeRequest($user, $labA, ['name' => 'Sangre'])
            ->assertCreated()->json('data.id');
        $sampleTypeB = $this->sampleTypeRequest($user, $labB, ['name' => 'Sangre'])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('sample_types', ['id' => $sampleTypeA, 'laboratory_id' => $labA->id]);
        $this->assertDatabaseHas('sample_types', ['id' => $sampleTypeB, 'laboratory_id' => $labB->id]);
        $this->assertDatabaseCount('sample_types', 2);
    }

    public function test_uniqueness_preserves_database_case_sensitive_semantics(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])->assertCreated();
        $this->sampleTypeRequest($user, $laboratory, ['name' => 'sangre'])->assertCreated();

        $this->assertSame(2, SampleType::forLaboratory($laboratory)->count());
    }

    public function test_context_switching_assigns_ownership_without_residual_tenant_state(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $sampleTypeA1 = $this->sampleTypeRequest($user, $labA, ['name' => 'Sangre'])
            ->assertCreated()->json('data.id');
        $sampleTypeB = $this->sampleTypeRequest($user, $labB, ['name' => 'Suero'])
            ->assertCreated()->json('data.id');
        $sampleTypeA2 = $this->sampleTypeRequest($user, $labA, ['name' => 'Orina'])
            ->assertCreated()->json('data.id');

        $this->assertEqualsCanonicalizing(
            [$sampleTypeA1, $sampleTypeA2],
            SampleType::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertSame(
            [$sampleTypeB],
            SampleType::forLaboratory($labB)->pluck('id')->all(),
        );
    }

    public function test_guest_is_rejected_before_creation(): void
    {
        $response = $this->postJson('/api/v1/sample-types', ['name' => 'Sangre'])
            ->assertUnauthorized();

        $this->assertDatabaseCount('sample_types', 0);
        $this->assertNoDatabaseDetails($response);
    }

    public function test_missing_laboratory_context_is_rejected_before_creation(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/v1/sample-types', ['name' => 'Sangre'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertDatabaseCount('sample_types', 0);
        $this->assertNoDatabaseDetails($response);
    }

    public function test_missing_membership_is_rejected_before_creation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);

        $response = $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertDatabaseCount('sample_types', 0);
        $this->assertNoDatabaseDetails($response);
    }

    public function test_inactive_membership_is_rejected_before_creation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);

        $response = $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertDatabaseCount('sample_types', 0);
        $this->assertNoDatabaseDetails($response);
    }

    public function test_missing_subscription_is_rejected_before_creation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $response = $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertDatabaseCount('sample_types', 0);
        $this->assertNoDatabaseDetails($response);
    }

    public function test_constraint_race_is_converted_to_safe_name_validation_error(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $inserted = false;

        SampleType::creating(function (SampleType $sampleType) use (&$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::table('sample_types')->insert([
                'laboratory_id' => $sampleType->laboratory_id,
                'name' => $sampleType->name,
                'status' => SampleType::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);

            $this->assertNoDatabaseDetails($response);
            $this->assertDatabaseCount('sample_types', 1);
        } finally {
            SampleType::flushEventListeners();
        }
    }

    public function test_unrelated_database_exception_is_not_converted_to_duplicate_validation(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        SampleType::creating(function (SampleType $sampleType): void {
            DB::table('sample_types')->insert([
                'laboratory_id' => $sampleType->laboratory_id,
                'name' => 'Broken insert',
                'status' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->sampleTypeRequest($user, $laboratory, ['name' => 'Sangre']);
        } finally {
            SampleType::flushEventListeners();
        }
    }

    /**
     * @return array{User, Laboratory}
     */
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sampleTypeRequest(
        User $user,
        Laboratory $laboratory,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/sample-types', $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function sampleTypeIndexRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/sample-types';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    private function assertNoDatabaseDetails(TestResponse $response): void
    {
        $json = $response->getContent();

        foreach ([
            'SQLSTATE',
            'sample_types_laboratory_id_name_unique',
            'select ',
            'insert into',
            'bindings',
            '/var/www',
            'App\\Models',
            'stack trace',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }
}
