<?php

namespace Tests\Feature\Api\V1\SampleTypes;

use App\Models\Laboratory;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SampleTypeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
    }

    public function test_name_is_trimmed_and_response_uses_the_exact_detail_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $this->travel(1)->minute();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id, [
            'name' => '  Sangre total  ',
        ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $sampleType->id,
                    'name' => 'Sangre total',
                    'status' => SampleType::STATUS_ACTIVE,
                    'created_at' => '2026-09-24T12:00:00.000000Z',
                    'updated_at' => '2026-09-24T12:01:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams');

        $this->assertSame('Sangre total', $sampleType->refresh()->name);
        $this->assertSame($laboratory->id, $sampleType->laboratory_id);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[DataProvider('statusProvider')]
    public function test_active_and_inactive_sample_types_are_editable_without_status_change(string $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory, ['status' => $status]);

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'Nombre actualizado'])
            ->assertOk()
            ->assertJsonPath('data.status', $status);

        $sampleType->refresh();
        $this->assertSame('Nombre actualizado', $sampleType->name);
        $this->assertSame($status, $sampleType->status);
        $this->assertSame($laboratory->id, $sampleType->laboratory_id);
    }

    /** @return array<string, array{string}> */
    public static function statusProvider(): array
    {
        return [
            'active' => [SampleType::STATUS_ACTIVE],
            'inactive' => [SampleType::STATUS_INACTIVE],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected_atomically(array $payload, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $before = $sampleType->getRawOriginal();

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertRawStateUnchanged($before, $sampleType);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloadProvider(): array
    {
        return [
            'empty payload' => [[], 'fields'],
            'name null' => [['name' => null], 'name'],
            'name empty' => [['name' => ''], 'name'],
            'name whitespace' => [['name' => '   '], 'name'],
            'name too long' => [['name' => str_repeat('A', 101)], 'name'],
            'name array' => [['name' => ['invalid']], 'name'],
            'name object' => [['name' => (object) ['invalid' => true]], 'name'],
            'name boolean' => [['name' => true], 'name'],
            'name integer' => [['name' => 123], 'name'],
            'name float' => [['name' => 12.3], 'name'],
            'unknown field' => [['foo' => 'bar'], 'foo'],
            'id injection' => [['name' => 'Nuevo', 'id' => 999], 'id'],
            'laboratory injection' => [['name' => 'Nuevo', 'laboratory_id' => 999], 'laboratory_id'],
            'status only' => [['status' => SampleType::STATUS_INACTIVE], 'status'],
            'mixed name and status' => [['name' => 'Nuevo', 'status' => SampleType::STATUS_INACTIVE], 'status'],
            'created at injection' => [['name' => 'Nuevo', 'created_at' => '2020-01-01'], 'created_at'],
            'updated at injection' => [['name' => 'Nuevo', 'updated_at' => '2020-01-01'], 'updated_at'],
        ];
    }

    #[DataProvider('sameValueProvider')]
    public function test_same_value_after_normalization_returns_ok_without_touching_timestamp(string $submitted): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory, ['name' => 'Sangre']);
        $updatedAt = $sampleType->updated_at?->toISOString();
        $this->travel(1)->hour();

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => $submitted])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sangre')
            ->assertJsonPath('data.updated_at', $updatedAt);

        $this->assertSame($updatedAt, $sampleType->refresh()->updated_at?->toISOString());
    }

    /** @return array<string, array{string}> */
    public static function sameValueProvider(): array
    {
        return [
            'same value' => ['Sangre'],
            'trimmed same value' => ['  Sangre  '],
        ];
    }

    public function test_real_change_updates_timestamp_naturally(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $createdAt = $sampleType->created_at?->toISOString();
        $this->travel(10)->minutes();

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'Sangre total'])
            ->assertOk();

        $sampleType->refresh();
        $this->assertSame($createdAt, $sampleType->created_at?->toISOString());
        $this->assertSame('2026-09-24T12:10:00.000000Z', $sampleType->updated_at?->toISOString());
    }

    public function test_same_tenant_duplicate_is_rejected_with_name_error(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = $this->sampleType($laboratory, ['name' => 'Sangre']);
        $this->sampleType($laboratory, ['name' => 'Plasma']);

        $this->sampleTypeRequest($user, $laboratory, $target->id, ['name' => 'Plasma'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame('Sangre', $target->refresh()->name);
    }

    public function test_current_record_is_ignored_and_same_name_in_another_tenant_is_allowed(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $target = $this->sampleType($labA, ['name' => 'Sangre']);
        $this->sampleType($labB, ['name' => 'Plasma']);

        $this->sampleTypeRequest($user, $labA, $target->id, ['name' => 'Sangre'])->assertOk();
        $this->sampleTypeRequest($user, $labA, $target->id, ['name' => 'Plasma'])->assertOk();

        $this->assertSame('Plasma', $target->refresh()->name);
        $this->assertSame($labA->id, $target->laboratory_id);
    }

    public function test_case_sensitive_rename_is_allowed(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = $this->sampleType($laboratory, ['name' => 'Sangre']);

        $this->sampleTypeRequest($user, $laboratory, $target->id, ['name' => 'sangre'])
            ->assertOk();

        $this->assertSame('sangre', $target->refresh()->name);
    }

    #[DataProvider('duplicateNormalizationProvider')]
    public function test_exact_and_trimmed_duplicates_are_rejected(string $submitted): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = $this->sampleType($laboratory, ['name' => 'Sangre']);
        $this->sampleType($laboratory, ['name' => 'sangre']);

        $this->sampleTypeRequest($user, $laboratory, $target->id, ['name' => $submitted])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame('Sangre', $target->refresh()->name);
    }

    /** @return array<string, array{string}> */
    public static function duplicateNormalizationProvider(): array
    {
        return [
            'exact casing duplicate' => ['sangre'],
            'trim duplicate' => ['  sangre  '],
        ];
    }

    public function test_cross_tenant_and_nonexistent_updates_share_neutral_404_without_writes(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleTypeB = $this->sampleType($labB, ['name' => 'SECRET-SERUM']);
        $before = $sampleTypeB->getRawOriginal();

        $crossTenant = $this->sampleTypeRequest($user, $labA, $sampleTypeB->id, ['name' => 'HACKED'])
            ->assertNotFound();
        $missing = $this->sampleTypeRequest($user, $labA, 999999999, ['name' => 'HACKED'])
            ->assertNotFound();

        $this->assertSame($crossTenant->getStatusCode(), $missing->getStatusCode());
        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());
        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertRawStateUnchanged($before, $sampleTypeB);
        $this->assertNoDatabaseDetails($crossTenant, ['SECRET-SERUM']);
        $this->assertNoDatabaseDetails($missing, ['SECRET-SERUM']);
    }

    public function test_context_switching_updates_only_the_selected_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleTypeA = $this->sampleType($labA, ['name' => 'Sangre']);
        $sampleTypeB = $this->sampleType($labB, ['name' => 'Suero']);

        $this->sampleTypeRequest($user, $labA, $sampleTypeA->id, ['name' => 'Sangre total'])->assertOk();
        $this->sampleTypeRequest($user, $labB, $sampleTypeB->id, ['name' => 'Suero sérico'])->assertOk();
        $this->sampleTypeRequest($user, $labA, $sampleTypeB->id, ['name' => 'HACKED'])->assertNotFound();

        $this->assertSame('Sangre total', $sampleTypeA->refresh()->name);
        $this->assertSame('Suero sérico', $sampleTypeB->refresh()->name);
    }

    #[DataProvider('queryParameterProvider')]
    public function test_query_parameters_are_rejected_without_writes(string $query, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $before = $sampleType->getRawOriginal();

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'Nuevo'], $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertRawStateUnchanged($before, $sampleType);
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'unknown query' => ['foo=bar', 'foo'],
            'tenant injection query' => ['laboratory_id=999', 'laboratory_id'],
        ];
    }

    #[DataProvider('invalidRouteProvider')]
    public function test_non_numeric_routes_return_not_found_without_writes(string $sampleType): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $count = SampleType::query()->count();

        $this->sampleTypeRequest($user, $laboratory, $sampleType, ['name' => 'Nuevo'])
            ->assertNotFound();

        $this->assertSame($count, SampleType::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function invalidRouteProvider(): array
    {
        return [
            'letters' => ['abc'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    public function test_lookup_is_tenant_scoped_before_the_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory);
        $lookups = [];

        DB::listen(function (QueryExecuted $query) use (&$lookups): void {
            if (str_contains($query->sql, 'select * from "sample_types"')) {
                $lookups[] = $query;
            }
        });

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'Nuevo'])
            ->assertOk();

        $this->assertCount(1, $lookups);
        $this->assertStringContainsString('laboratory_id', $lookups[0]->sql);
        $this->assertStringContainsString('id', $lookups[0]->sql);
        $this->assertContains($laboratory->id, $lookups[0]->bindings);
        $this->assertContains($sampleType->id, $lookups[0]->bindings);
    }

    public function test_constraint_race_is_converted_to_safe_name_validation_error(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $target = $this->sampleType($laboratory, ['name' => 'Sangre']);
        $inserted = false;

        SampleType::updating(function (SampleType $sampleType) use (&$inserted): void {
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
            $response = $this->sampleTypeRequest($user, $laboratory, $target->id, ['name' => 'Race Winner'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);

            $this->assertSame('Sangre', $target->refresh()->name);
            $this->assertNoDatabaseDetails($response);
        } finally {
            SampleType::flushEventListeners();
        }
    }

    public function test_unrelated_database_exception_is_not_converted_to_duplicate_validation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = $this->sampleType($laboratory);

        SampleType::updating(function (SampleType $sampleType): void {
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
            $this->sampleTypeRequest($user, $laboratory, $target->id, ['name' => 'Nuevo']);
        } finally {
            SampleType::flushEventListeners();
        }
    }

    public function test_guest_is_rejected_without_mutation(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'SECRET-GUEST']);
        $before = $sampleType->getRawOriginal();

        $response = $this->patchJson("/api/v1/sample-types/{$sampleType->id}", ['name' => 'HACKED'])
            ->assertUnauthorized();

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoDatabaseDetails($response, ['SECRET-GUEST']);
    }

    public function test_missing_context_is_rejected_without_mutation(): void
    {
        $sampleType = SampleType::factory()->create(['name' => 'SECRET-CONTEXT']);
        $before = $sampleType->getRawOriginal();

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->patchJson("/api/v1/sample-types/{$sampleType->id}", ['name' => 'HACKED'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoDatabaseDetails($response, ['SECRET-CONTEXT']);
    }

    public function test_inactive_membership_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $sampleType = $this->sampleType($laboratory, ['name' => 'SECRET-INACTIVE']);
        $before = $sampleType->getRawOriginal();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'HACKED'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoDatabaseDetails($response, ['SECRET-INACTIVE']);
    }

    public function test_nonexistent_membership_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        $sampleType = $this->sampleType($laboratory, ['name' => 'SECRET-NO-MEMBER']);
        $before = $sampleType->getRawOriginal();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'HACKED'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoDatabaseDetails($response, ['SECRET-NO-MEMBER']);
    }

    public function test_missing_subscription_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $sampleType = $this->sampleType($laboratory, ['name' => 'SECRET-SUBSCRIPTION']);
        $before = $sampleType->getRawOriginal();

        $response = $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'HACKED'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertRawStateUnchanged($before, $sampleType);
        $this->assertNoDatabaseDetails($response, ['SECRET-SUBSCRIPTION']);
    }

    public function test_updated_name_is_immediately_visible_in_detail_and_index(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = $this->sampleType($laboratory, ['name' => 'Nombre original']);

        $this->sampleTypeRequest($user, $laboratory, $sampleType->id, ['name' => 'Plasma actualizado'])
            ->assertOk();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/sample-types/{$sampleType->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Plasma actualizado');

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/sample-types?search=actualizado')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sampleType->id);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        $this->assignDirectLaboratoryPermissions($user, $laboratory, ['sample_types.view', 'sample_types.update', 'sample_types.change_status']);

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

    /** @param array<string, mixed> $attributes */
    private function sampleType(Laboratory $laboratory, array $attributes = []): SampleType
    {
        return SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'status' => SampleType::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function sampleTypeRequest(
        User $user,
        Laboratory $laboratory,
        int|string $sampleType,
        array $payload,
        string $query = '',
    ): TestResponse {
        $suffix = $query === '' ? '' : "?{$query}";

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'sample_types.update');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/sample-types/{$sampleType}{$suffix}", $payload);
    }

    /** @param array<string, mixed> $before */
    private function assertRawStateUnchanged(array $before, SampleType $sampleType): void
    {
        $after = $sampleType->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }

    /** @param list<string> $secrets */
    private function assertNoDatabaseDetails(TestResponse $response, array $secrets = []): void
    {
        $body = strtolower($response->getContent());

        foreach ([...$secrets, 'sqlstate', 'sample_types_laboratory_id_name_unique', 'select ', 'update ', 'bindings', '/var/www', 'exception', 'trace'] as $secret) {
            $this->assertStringNotContainsString(strtolower($secret), $body);
        }
    }
}
