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

class LaboratoryAreaUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    #[DataProvider('partialUpdateProvider')]
    public function test_only_sent_fields_are_updated(array $payload, array $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);

        $this->areaRequest($user, $laboratory, $area->id, $payload)
            ->assertOk();

        $area->refresh();

        foreach ($expected as $field => $value) {
            $this->assertSame($value, $area->{$field});
        }

        $this->assertSame(LaboratoryArea::STATUS_ACTIVE, $area->status);
        $this->assertSame($laboratory->id, $area->laboratory_id);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function partialUpdateProvider(): array
    {
        return [
            'only code' => [
                ['code' => 'HEM2'],
                ['code' => 'HEM2', 'name' => 'Hematología', 'description' => 'Pruebas hematológicas.'],
            ],
            'only name' => [
                ['name' => 'Hematología Clínica'],
                ['code' => 'HEM', 'name' => 'Hematología Clínica', 'description' => 'Pruebas hematológicas.'],
            ],
            'only description' => [
                ['description' => 'Descripción nueva.'],
                ['code' => 'HEM', 'name' => 'Hematología', 'description' => 'Descripción nueva.'],
            ],
            'code and name' => [
                ['code' => 'HEM2', 'name' => 'Hematología Clínica'],
                ['code' => 'HEM2', 'name' => 'Hematología Clínica', 'description' => 'Pruebas hematológicas.'],
            ],
            'all fields' => [
                ['code' => 'HEM2', 'name' => 'Hematología Clínica', 'description' => 'Descripción nueva.'],
                ['code' => 'HEM2', 'name' => 'Hematología Clínica', 'description' => 'Descripción nueva.'],
            ],
        ];
    }

    #[DataProvider('normalizationProvider')]
    public function test_input_is_normalized_without_changing_casing(array $payload, string $field, mixed $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);

        $this->areaRequest($user, $laboratory, $area->id, $payload)
            ->assertOk()
            ->assertJsonPath("data.{$field}", $expected);

        $this->assertSame($expected, $area->refresh()->{$field});
    }

    /**
     * @return array<string, array{array<string, mixed>, string, mixed}>
     */
    public static function normalizationProvider(): array
    {
        return [
            'trim code and preserve casing' => [['code' => '  hem-Clinical  '], 'code', 'hem-Clinical'],
            'trim name and preserve casing' => [['name' => '  heMatología Clínica  '], 'name', 'heMatología Clínica'],
            'trim description' => [['description' => '  Nueva descripción.  '], 'description', 'Nueva descripción.'],
            'null description' => [['description' => null], 'description', null],
            'empty description' => [['description' => ''], 'description', null],
            'spaces description' => [['description' => '   '], 'description', null],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected_atomically(array $payload, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);

        $this->areaRequest($user, $laboratory, $area->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertAreaUnchanged($area);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'empty payload' => [[], 'fields'],
            'code null' => [['code' => null], 'code'],
            'code empty' => [['code' => ''], 'code'],
            'code spaces' => [['code' => '   '], 'code'],
            'code too long' => [['code' => str_repeat('A', 31)], 'code'],
            'name null' => [['name' => null], 'name'],
            'name empty' => [['name' => ''], 'name'],
            'name spaces' => [['name' => '   '], 'name'],
            'name too long' => [['name' => str_repeat('A', 101)], 'name'],
            'description too long' => [['description' => str_repeat('A', 256)], 'description'],
            'description not string' => [['description' => ['invalid']], 'description'],
            'id controlled by server' => [['name' => 'Nuevo', 'id' => 99], 'id'],
            'laboratory controlled by server' => [['name' => 'Nuevo', 'laboratory_id' => 99], 'laboratory_id'],
            'status controlled by server' => [['name' => 'Nuevo', 'status' => 'inactive'], 'status'],
            'created at controlled by server' => [['name' => 'Nuevo', 'created_at' => '2020-01-01'], 'created_at'],
            'updated at controlled by server' => [['name' => 'Nuevo', 'updated_at' => '2020-01-01'], 'updated_at'],
            'unknown foo' => [['name' => 'Nuevo', 'foo' => 'bar'], 'foo'],
            'unknown display order' => [['display_order' => 1], 'display_order'],
            'unknown exam id' => [['exam_id' => 1], 'exam_id'],
            'unknown active' => [['active' => true], 'active'],
            'unknown estado' => [['estado' => 'activo'], 'estado'],
            'unknown nombre' => [['nombre' => 'Nuevo'], 'nombre'],
            'unknown descripcion' => [['descripcion' => 'Nueva'], 'descripcion'],
            'unknown laboratory typo' => [['laboratorios_id' => 1], 'laboratorios_id'],
        ];
    }

    public function test_response_uses_the_exact_detail_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $this->travel(1)->minute();

        $response = $this->areaRequest($user, $laboratory, $area->id, ['name' => 'Hematología Clínica'])
            ->assertOk()
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams');

        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name', 'description', 'status', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
        $this->assertSame('2026-09-23T12:01:00.000000Z', $response->json('data.updated_at'));
    }

    public function test_same_code_and_name_are_allowed_without_forcing_updated_at(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $updatedAt = $area->updated_at?->toISOString();
        $this->travel(1)->hour();

        $this->areaRequest($user, $laboratory, $area->id, [
            'code' => $area->code,
            'name' => $area->name,
        ])->assertOk();

        $this->assertSame($updatedAt, $area->refresh()->updated_at?->toISOString());
    }

    #[DataProvider('sameTenantDuplicateProvider')]
    public function test_same_tenant_duplicates_are_rejected_after_trim(
        string $field,
        string $existing,
        string $submitted,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $target = $this->area($laboratory, ['code' => 'QUI', 'name' => 'Química Clínica']);
        $other = $this->area($laboratory, ['code' => 'HEM2', 'name' => 'Hematología Especial']);
        $other->{$field} = $existing;
        $other->save();

        $this->areaRequest($user, $laboratory, $target->id, [
            $field => $submitted,
            'description' => 'No debe persistirse.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertAreaUnchanged($target, ['code' => 'QUI', 'name' => 'Química Clínica']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function sameTenantDuplicateProvider(): array
    {
        return [
            'duplicate code' => ['code', 'HEM', 'HEM'],
            'duplicate padded code' => ['code', 'HEM', '  HEM  '],
            'duplicate name' => ['name', 'Hematología', 'Hematología'],
            'duplicate padded name' => ['name', 'Hematología', '  Hematología  '],
        ];
    }

    public function test_uniqueness_is_tenant_scoped_and_case_sensitive(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $this->area($labA, ['code' => 'HEM', 'name' => 'Hematología']);
        $target = $this->area($labB, ['code' => 'QUI', 'name' => 'Química Clínica']);

        $this->areaRequest($user, $labB, $target->id, [
            'code' => 'HEM',
            'name' => 'Hematología',
        ])->assertOk();

        $this->areaRequest($user, $labB, $target->id, [
            'code' => 'hem',
            'name' => 'hematología',
        ])->assertOk();

        $target->refresh();
        $this->assertSame('hem', $target->code);
        $this->assertSame('hematología', $target->name);
        $this->assertSame($labB->id, $target->laboratory_id);
    }

    #[DataProvider('statusProvider')]
    public function test_active_and_inactive_areas_are_editable_without_status_change(string $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory, ['status' => $status]);

        $this->areaRequest($user, $laboratory, $area->id, [
            'name' => 'Nombre actualizado',
            'description' => 'Descripción actualizada.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', $status);

        $this->assertSame($status, $area->refresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function statusProvider(): array
    {
        return [
            'active' => [LaboratoryArea::STATUS_ACTIVE],
            'inactive' => [LaboratoryArea::STATUS_INACTIVE],
        ];
    }

    public function test_cross_tenant_and_nonexistent_updates_share_neutral_404_without_mutation(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $areaB = $this->area($labB);
        $before = $areaB->getRawOriginal();

        $crossTenant = $this->areaRequest($user, $labA, $areaB->id, ['name' => 'HACKED'])
            ->assertNotFound();
        $missing = $this->areaRequest($user, $labA, 999999999, ['name' => 'HACKED'])
            ->assertNotFound();

        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());
        $this->assertSame($crossTenant->json(), $missing->json());
        $this->assertRawStateUnchanged($before, $areaB);

        foreach ([$crossTenant, $missing] as $response) {
            $this->assertNoDatabaseDetails($response);
        }
    }

    public function test_switching_context_updates_only_the_selected_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $areaA = $this->area($labA, ['code' => 'A', 'name' => 'Área A']);
        $areaB = $this->area($labB, ['code' => 'B', 'name' => 'Área B']);

        $this->areaRequest($user, $labA, $areaA->id, ['name' => 'Área A1'])->assertOk();
        $this->areaRequest($user, $labB, $areaB->id, ['name' => 'Área B1'])->assertOk();
        $this->areaRequest($user, $labA, $areaA->id, ['name' => 'Área A2'])->assertOk();
        $this->areaRequest($user, $labA, $areaB->id, ['name' => 'HACKED'])->assertNotFound();

        $this->assertSame('Área A2', $areaA->refresh()->name);
        $this->assertSame('Área B1', $areaB->refresh()->name);
    }

    public function test_real_change_updates_timestamp_naturally(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $createdAt = $area->created_at?->toISOString();
        $this->travel(10)->minutes();

        $this->areaRequest($user, $laboratory, $area->id, ['name' => 'Nombre actualizado'])
            ->assertOk();

        $area->refresh();
        $this->assertSame($createdAt, $area->created_at?->toISOString());
        $this->assertSame('2026-09-23T12:10:00.000000Z', $area->updated_at?->toISOString());
    }

    #[DataProvider('raceProvider')]
    public function test_known_constraint_races_become_safe_validation_errors(
        string $field,
        string $value,
    ): void {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $target = $this->area($laboratory, ['code' => 'QUI', 'name' => 'Química Clínica']);
        $inserted = false;

        LaboratoryArea::updating(function (LaboratoryArea $area) use (&$inserted, $field, $value): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::table('laboratory_areas')->insert([
                'laboratory_id' => $area->laboratory_id,
                'code' => $field === 'code' ? $value : 'RACE-WINNER',
                'name' => $field === 'name' ? $value : 'Race Winner',
                'description' => null,
                'status' => LaboratoryArea::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->areaRequest($user, $laboratory, $target->id, [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertAreaUnchanged($target, ['code' => 'QUI', 'name' => 'Química Clínica']);
        $this->assertNoDatabaseDetails($response);
    }

    /** @return array<string, array{string, string}> */
    public static function raceProvider(): array
    {
        return [
            'code race' => ['code', 'RACE-CODE'],
            'name race' => ['name', 'Race Name'],
        ];
    }

    public function test_guest_is_rejected_without_mutation(): void
    {
        $area = LaboratoryArea::factory()->create();
        $before = $area->getRawOriginal();

        $this->patchJson("/api/v1/laboratory-areas/{$area->id}", ['name' => 'HACKED'])
            ->assertUnauthorized();

        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_missing_context_is_rejected_without_mutation(): void
    {
        $area = LaboratoryArea::factory()->create();
        $before = $area->getRawOriginal();

        $this->actingAs(User::factory()->create(), 'web')
            ->patchJson("/api/v1/laboratory-areas/{$area->id}", ['name' => 'HACKED'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_inactive_membership_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        $area = $this->area($laboratory);
        $before = $area->getRawOriginal();

        $this->areaRequest($user, $laboratory, $area->id, ['name' => 'HACKED'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_missing_subscription_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $area = $this->area($laboratory);
        $before = $area->getRawOriginal();

        $this->areaRequest($user, $laboratory, $area->id, ['name' => 'HACKED'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertRawStateUnchanged($before, $area);
    }

    public function test_updated_values_are_visible_in_detail_listing_and_search(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory, [
            'code' => 'OLD-CODE',
            'name' => 'Área Original',
        ]);

        $this->areaRequest($user, $laboratory, $area->id, [
            'code' => 'CLIN',
            'name' => 'Hematología Clínica',
            'description' => 'Área actualizada.',
        ])->assertOk();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-areas/{$area->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'CLIN')
            ->assertJsonPath('data.description', 'Área actualizada.');

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory-areas?search=clínica')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $area->id);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory-areas?search=OLD-CODE')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidRouteProvider')]
    public function test_invalid_route_identifiers_return_not_found(string $area): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory, $area, ['name' => 'Nuevo'])
            ->assertNotFound();
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

    /** @param array<string, mixed> $attributes */
    private function area(Laboratory $laboratory, array $attributes = []): LaboratoryArea
    {
        return LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => 'Pruebas hematológicas.',
            'status' => LaboratoryArea::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function areaRequest(
        User $user,
        Laboratory $laboratory,
        int|string $area,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-areas/{$area}", $payload);
    }

    /** @param array<string, mixed> $expected */
    private function assertAreaUnchanged(LaboratoryArea $area, array $expected = []): void
    {
        $area->refresh();

        $this->assertSame($expected['code'] ?? 'HEM', $area->code);
        $this->assertSame($expected['name'] ?? 'Hematología', $area->name);
        $this->assertSame('Pruebas hematológicas.', $area->description);
        $this->assertSame(LaboratoryArea::STATUS_ACTIVE, $area->status);
    }

    private function assertNoDatabaseDetails(TestResponse $response): void
    {
        $body = strtolower($response->getContent());

        foreach (['sqlstate', 'laboratory_areas_laboratory_id_', 'select ', 'update ', '/var/www', 'exception', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /** @param array<string, mixed> $before */
    private function assertRawStateUnchanged(array $before, LaboratoryArea $area): void
    {
        $after = $area->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }
}
