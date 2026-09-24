<?php

namespace Tests\Feature\Api\V1\LaboratoryAreas;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LaboratoryAreaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_complete_lifecycle_integrates_create_detail_listing_update_active_and_status(): void
    {
        $user = User::factory()->create();
        $laboratoryA = $this->activeTenant($user);
        $laboratoryB = $this->activeTenant($user);
        $areaB = LaboratoryArea::factory()->for($laboratoryB)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);

        $created = $this->tenantRequest($user, $laboratoryA)
            ->postJson('/api/v1/laboratory-areas', [
                'code' => 'HEM',
                'name' => 'Hematología',
                'description' => 'Área hematológica',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id');

        $areaId = $created->json('data.id');

        $this->assertDatabaseHas('laboratory_areas', [
            'id' => $areaId,
            'laboratory_id' => $laboratoryA->id,
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => 'Área hematológica',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson("/api/v1/laboratory-areas/{$areaId}")
            ->assertOk()
            ->assertJsonPath('data.code', 'HEM')
            ->assertJsonPath('data.name', 'Hematología')
            ->assertJsonPath('data.description', 'Área hematológica')
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id');

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/laboratory-areas')
            ->assertOk()
            ->assertJsonPath('data.0.id', $areaId)
            ->assertJsonPath('data.0.status', LaboratoryArea::STATUS_ACTIVE);

        foreach (['search=hem', 'search=matolog'] as $query) {
            $this->tenantRequest($user, $laboratoryA)
                ->getJson("/api/v1/laboratory-areas?{$query}")
                ->assertOk()
                ->assertJsonPath('data.0.id', $areaId);

            $this->tenantRequest($user, $laboratoryB)
                ->getJson("/api/v1/laboratory-areas?{$query}")
                ->assertOk()
                ->assertJsonMissing(['id' => $areaId]);
        }

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/laboratory-areas/{$areaId}", [
                'code' => 'HEM-CL',
                'name' => 'Hematología Clínica',
                'description' => 'Área hematológica clínica',
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'HEM-CL')
            ->assertJsonPath('data.name', 'Hematología Clínica')
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_ACTIVE);

        $this->assertDatabaseHas('laboratory_areas', [
            'id' => $areaId,
            'laboratory_id' => $laboratoryA->id,
            'code' => 'HEM-CL',
            'name' => 'Hematología Clínica',
            'description' => 'Área hematológica clínica',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson("/api/v1/laboratory-areas/{$areaId}")
            ->assertOk()
            ->assertJsonPath('data.code', 'HEM-CL')
            ->assertJsonPath('data.name', 'Hematología Clínica')
            ->assertJsonPath('data.description', 'Área hematológica clínica')
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/laboratory-areas/active')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $areaId,
                    'code' => 'HEM-CL',
                    'name' => 'Hematología Clínica',
                ]],
            ]);

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/laboratory-areas/{$areaId}/status", [
                'status' => LaboratoryArea::STATUS_INACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_INACTIVE);

        $this->assertDatabaseHas('laboratory_areas', [
            'id' => $areaId,
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/laboratory-areas')
            ->assertOk()
            ->assertJsonPath('data.0.id', $areaId);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/laboratory-areas?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $areaId);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/laboratory-areas?status=active')
            ->assertOk()
            ->assertExactJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(0, 'data');

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/laboratory-areas/active')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/laboratory-areas/{$areaId}", [
                'description' => 'Área inactiva todavía editable',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Área inactiva todavía editable')
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/laboratory-areas/{$areaId}/status", [
                'status' => LaboratoryArea::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryArea::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/laboratory-areas/active')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $areaId,
                    'code' => 'HEM-CL',
                    'name' => 'Hematología Clínica',
                ]],
            ]);

        $this->assertSame($laboratoryB->id, $areaB->fresh()->laboratory_id);
        $this->assertSame('HEM', $areaB->code);
        $this->assertSame(LaboratoryArea::STATUS_ACTIVE, $areaB->status);
    }

    public function test_tenant_isolation_is_symmetric_neutral_and_stable_across_context_switching(): void
    {
        $user = User::factory()->create();
        $laboratoryA = $this->activeTenant($user);
        $laboratoryB = $this->activeTenant($user);
        $areaA = LaboratoryArea::factory()->for($laboratoryA)->create([
            'code' => 'A-ONLY',
            'name' => 'Área A',
        ]);
        $areaB = LaboratoryArea::factory()->for($laboratoryB)->create([
            'code' => 'B-ONLY',
            'name' => 'Área B',
        ]);
        $beforeA = $areaA->getRawOriginal();
        $beforeB = $areaB->getRawOriginal();

        foreach ([
            [$laboratoryA, $areaB],
            [$laboratoryB, $areaA],
        ] as [$context, $foreignArea]) {
            $missingId = $foreignArea->id + 10_000;

            $this->assertNeutralNotFound(
                $this->tenantRequest($user, $context)
                    ->getJson("/api/v1/laboratory-areas/{$foreignArea->id}"),
                $this->tenantRequest($user, $context)
                    ->getJson("/api/v1/laboratory-areas/{$missingId}"),
            );

            $this->assertNeutralNotFound(
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/laboratory-areas/{$foreignArea->id}", ['name' => 'Injected']),
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/laboratory-areas/{$missingId}", ['name' => 'Injected']),
            );

            $this->assertNeutralNotFound(
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/laboratory-areas/{$foreignArea->id}/status", ['status' => 'inactive']),
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/laboratory-areas/{$missingId}/status", ['status' => 'inactive']),
            );
        }

        foreach ([
            [$laboratoryA, $areaA->id],
            [$laboratoryB, $areaB->id],
            [$laboratoryA, $areaA->id],
            [$laboratoryB, $areaB->id],
        ] as [$context, $expectedId]) {
            $this->tenantRequest($user, $context)
                ->getJson('/api/v1/laboratory-areas/active')
                ->assertOk()
                ->assertJsonPath('data.0.id', $expectedId)
                ->assertJsonCount(1, 'data');
        }

        $afterA = $areaA->fresh()->getRawOriginal();
        $afterB = $areaB->fresh()->getRawOriginal();
        ksort($beforeA);
        ksort($beforeB);
        ksort($afterA);
        ksort($afterB);

        $this->assertSame($beforeA, $afterA);
        $this->assertSame($beforeB, $afterB);
    }

    public function test_tenant_ownership_cannot_be_injected_during_creation(): void
    {
        $user = User::factory()->create();
        $laboratoryA = $this->activeTenant($user);
        $laboratoryB = $this->activeTenant($user);

        $this->tenantRequest($user, $laboratoryA)
            ->postJson('/api/v1/laboratory-areas', [
                'code' => 'INJECTED',
                'name' => 'Injected ownership',
                'laboratory_id' => $laboratoryB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['laboratory_id']);

        $this->assertDatabaseMissing('laboratory_areas', ['code' => 'INJECTED']);
    }

    private function activeTenant(User $user): Laboratory
    {
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return $laboratory;
    }

    private function tenantRequest(User $user, Laboratory $laboratory): self
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }

    private function assertNeutralNotFound(TestResponse $crossTenant, TestResponse $missing): void
    {
        $crossTenant->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $missing->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);

        $this->assertSame($missing->status(), $crossTenant->status());
        $this->assertSame($missing->getContent(), $crossTenant->getContent());
    }
}
