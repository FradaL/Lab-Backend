<?php

namespace Tests\Feature\Api\V1\LaboratoryAreas;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryAreaStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_area_is_created_with_current_tenant_database_defaults_and_exact_resource(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $response = $this->areaRequest($user, $laboratory, [
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => null,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.code', 'HEM')
            ->assertJsonPath('data.name', 'Hematología')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_ACTIVE)
            ->assertJsonPath('data.created_at', '2026-09-23T12:00:00.000000Z')
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.updated_at')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams');

        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name', 'description', 'status', 'created_at'],
            array_keys($response->json('data')),
        );

        $area = LaboratoryArea::query()->sole();

        $this->assertSame($laboratory->id, $area->laboratory_id);
        $this->assertSame(LaboratoryArea::STATUS_ACTIVE, $area->status);
        $this->assertNull($area->description);
    }

    public function test_input_is_trimmed_without_changing_casing_or_valid_code_format(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory, [
            'code' => '  hem-01  ',
            'name' => '  heMatología Especial  ',
            'description' => '  Descripción Mixta.  ',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'hem-01')
            ->assertJsonPath('data.name', 'heMatología Especial')
            ->assertJsonPath('data.description', 'Descripción Mixta.');

        $this->assertDatabaseHas('laboratory_areas', [
            'laboratory_id' => $laboratory->id,
            'code' => 'hem-01',
            'name' => 'heMatología Especial',
            'description' => 'Descripción Mixta.',
        ]);
    }

    #[DataProvider('emptyDescriptionProvider')]
    public function test_empty_description_is_normalized_to_null(?string $description): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory, [
            ...$this->validPayload(),
            'description' => $description,
        ])
            ->assertCreated()
            ->assertJsonPath('data.description', null);

        $this->assertNull(LaboratoryArea::query()->sole()->description);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function emptyDescriptionProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces' => ['   '],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected_atomically(array $payload, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('laboratory_areas', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        $valid = [
            'code' => 'HEM',
            'name' => 'Hematología',
        ];

        return [
            'code missing' => [['name' => 'Hematología'], 'code'],
            'code null' => [[...$valid, 'code' => null], 'code'],
            'code empty' => [[...$valid, 'code' => ''], 'code'],
            'code spaces' => [[...$valid, 'code' => '   '], 'code'],
            'code too long' => [[...$valid, 'code' => str_repeat('A', 31)], 'code'],
            'name missing' => [['code' => 'HEM'], 'name'],
            'name null' => [[...$valid, 'name' => null], 'name'],
            'name empty' => [[...$valid, 'name' => ''], 'name'],
            'name spaces' => [[...$valid, 'name' => '   '], 'name'],
            'name too long' => [[...$valid, 'name' => str_repeat('A', 101)], 'name'],
            'description too long' => [[...$valid, 'description' => str_repeat('A', 256)], 'description'],
            'description not string' => [[...$valid, 'description' => ['invalid']], 'description'],
            'id controlled by server' => [[...$valid, 'id' => 123], 'id'],
            'laboratory id controlled by server' => [[...$valid, 'laboratory_id' => 999], 'laboratory_id'],
            'active status controlled by server' => [[...$valid, 'status' => 'active'], 'status'],
            'inactive status controlled by server' => [[...$valid, 'status' => 'inactive'], 'status'],
            'created at controlled by server' => [[...$valid, 'created_at' => '2020-01-01'], 'created_at'],
            'updated at controlled by server' => [[...$valid, 'updated_at' => '2020-01-01'], 'updated_at'],
            'unknown property' => [[...$valid, 'foo' => 'bar'], 'foo'],
            'display order unknown' => [[...$valid, 'display_order' => 1], 'display_order'],
            'spanish property unknown' => [[...$valid, 'nombre' => 'Hematología'], 'nombre'],
        ];
    }

    public function test_duplicate_code_is_rejected_only_within_the_current_laboratory(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);

        $response = $this->areaRequest($user, $laboratory, [
            'code' => 'HEM',
            'name' => 'Hematología Especial',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
        $this->assertSame(1, LaboratoryArea::forLaboratory($laboratory)->count());
        $this->assertNoDatabaseDetails($response);
    }

    public function test_duplicate_name_is_rejected_only_within_the_current_laboratory(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);

        $response = $this->areaRequest($user, $laboratory, [
            'code' => 'HEM2',
            'name' => 'Hematología',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $this->assertSame(1, LaboratoryArea::forLaboratory($laboratory)->count());
        $this->assertNoDatabaseDetails($response);
    }

    public function test_duplicate_code_and_name_report_both_validation_errors(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        LaboratoryArea::factory()->for($laboratory)->create($this->validPayload());

        $this->areaRequest($user, $laboratory, $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name']);

        $this->assertSame(1, LaboratoryArea::forLaboratory($laboratory)->count());
    }

    public function test_trimmed_values_are_checked_for_uniqueness(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        LaboratoryArea::factory()->for($laboratory)->create($this->validPayload());

        $this->areaRequest($user, $laboratory, [
            'code' => '  HEM  ',
            'name' => 'Otro nombre',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->areaRequest($user, $laboratory, [
            'code' => 'HEM2',
            'name' => '  Hematología  ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, LaboratoryArea::forLaboratory($laboratory)->count());
    }

    public function test_same_code_and_name_are_allowed_in_different_laboratories(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $areaA = $this->areaRequest($user, $labA, $this->validPayload())
            ->assertCreated()
            ->json('data.id');
        $areaB = $this->areaRequest($user, $labB, $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('laboratory_areas', ['id' => $areaA, 'laboratory_id' => $labA->id]);
        $this->assertDatabaseHas('laboratory_areas', ['id' => $areaB, 'laboratory_id' => $labB->id]);
    }

    public function test_uniqueness_preserves_the_database_case_sensitive_semantics(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory, $this->validPayload())->assertCreated();
        $this->areaRequest($user, $laboratory, [
            'code' => 'hem',
            'name' => 'hematología',
        ])->assertCreated();

        $this->assertSame(2, LaboratoryArea::forLaboratory($laboratory)->count());
    }

    public function test_context_switching_assigns_ownership_without_residual_tenant_state(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $areaA1 = $this->areaRequest($user, $labA, ['code' => 'A1', 'name' => 'Área A1'])
            ->assertCreated()->json('data.id');
        $areaB = $this->areaRequest($user, $labB, ['code' => 'B1', 'name' => 'Área B1'])
            ->assertCreated()->json('data.id');
        $areaA2 = $this->areaRequest($user, $labA, ['code' => 'A2', 'name' => 'Área A2'])
            ->assertCreated()->json('data.id');

        $this->assertEqualsCanonicalizing(
            [$areaA1, $areaA2],
            LaboratoryArea::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertSame(
            [$areaB],
            LaboratoryArea::forLaboratory($labB)->pluck('id')->all(),
        );
    }

    public function test_created_area_is_visible_and_searchable_only_in_its_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $areaId = $this->areaRequest($user, $labA, $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->areaIndexRequest($user, $labA)
            ->assertOk()
            ->assertJsonPath('data.0.id', $areaId);
        $this->areaIndexRequest($user, $labA, ['search' => 'hematología'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $areaId);
        $this->areaIndexRequest($user, $labB)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_guest_is_rejected_before_creation(): void
    {
        $this->postJson('/api/v1/laboratory-areas', $this->validPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('laboratory_areas', 0);
    }

    public function test_missing_laboratory_context_is_rejected_before_creation(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/v1/laboratory-areas', $this->validPayload())
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertDatabaseCount('laboratory_areas', 0);
    }

    public function test_inactive_membership_is_rejected_before_creation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);

        $this->areaRequest($user, $laboratory, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertDatabaseCount('laboratory_areas', 0);
    }

    public function test_missing_subscription_is_rejected_before_creation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->areaRequest($user, $laboratory, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertDatabaseCount('laboratory_areas', 0);
    }

    public function test_constraint_race_is_converted_to_safe_validation_error(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $inserted = false;

        LaboratoryArea::creating(function (LaboratoryArea $area) use (&$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::table('laboratory_areas')->insert([
                'laboratory_id' => $area->laboratory_id,
                'code' => $area->code,
                'name' => 'Concurrent Winner',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->areaRequest($user, $laboratory, $this->validPayload())
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);

            $this->assertNoDatabaseDetails($response);
        } finally {
            LaboratoryArea::flushEventListeners();
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
     * @return array{code: string, name: string}
     */
    private function validPayload(): array
    {
        return [
            'code' => 'HEM',
            'name' => 'Hematología',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function areaRequest(User $user, Laboratory $laboratory, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-areas', $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function areaIndexRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/laboratory-areas';

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

        foreach (['SQLSTATE', 'laboratory_areas_laboratory_id_', 'select ', 'insert into', '/var/www'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }
}
