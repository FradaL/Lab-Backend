<?php

namespace Tests\Feature\Api\V1\SampleTypes;

use App\Models\Laboratory;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SampleTypeIntegrationTest extends TestCase
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
        $sampleTypeB = SampleType::factory()->for($laboratoryB)->create([
            'name' => 'Sangre',
        ]);

        $created = $this->tenantRequest($user, $laboratoryA)
            ->postJson('/api/v1/sample-types', ['name' => 'Sangre'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sangre')
            ->assertJsonPath('data.status', SampleType::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id');

        $sampleTypeId = $created->json('data.id');

        $this->assertDatabaseHas('sample_types', [
            'id' => $sampleTypeId,
            'laboratory_id' => $laboratoryA->id,
            'name' => 'Sangre',
            'status' => SampleType::STATUS_ACTIVE,
        ]);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson("/api/v1/sample-types/{$sampleTypeId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Sangre')
            ->assertJsonPath('data.status', SampleType::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id');

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sampleTypeId)
            ->assertJsonPath('data.0.status', SampleType::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types/active')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $sampleTypeId,
                    'name' => 'Sangre',
                ]],
            ]);

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/sample-types/{$sampleTypeId}", ['name' => 'Sangre total'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sangre total')
            ->assertJsonPath('data.status', SampleType::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson("/api/v1/sample-types/{$sampleTypeId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Sangre total');

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sangre total');

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types/active')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sangre total');

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/sample-types/{$sampleTypeId}/status", [
                'status' => SampleType::STATUS_INACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SampleType::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson("/api/v1/sample-types/{$sampleTypeId}")
            ->assertOk()
            ->assertJsonPath('data.status', SampleType::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sampleTypeId);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types/active')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/sample-types/{$sampleTypeId}", ['name' => 'Sangre completa'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sangre completa')
            ->assertJsonPath('data.status', SampleType::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson("/api/v1/sample-types/{$sampleTypeId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Sangre completa')
            ->assertJsonPath('data.status', SampleType::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sangre completa');

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types/active')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/sample-types/{$sampleTypeId}/status", [
                'status' => SampleType::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SampleType::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson('/api/v1/sample-types/active')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $sampleTypeId,
                    'name' => 'Sangre completa',
                ]],
            ]);

        $this->assertSame($laboratoryB->id, $sampleTypeB->fresh()->laboratory_id);
        $this->assertSame('Sangre', $sampleTypeB->name);
        $this->assertSame(SampleType::STATUS_ACTIVE, $sampleTypeB->status);
    }

    public function test_tenant_isolation_is_symmetric_neutral_and_stable_across_context_switching(): void
    {
        $user = User::factory()->create();
        $laboratoryA = $this->activeTenant($user);
        $laboratoryB = $this->activeTenant($user);
        $sampleTypeA = SampleType::factory()->for($laboratoryA)->create(['name' => 'Sangre']);
        $sampleTypeB = SampleType::factory()->for($laboratoryB)->create(['name' => 'Sangre']);
        $beforeA = $sampleTypeA->getRawOriginal();
        $beforeB = $sampleTypeB->getRawOriginal();

        foreach ([
            [$laboratoryA, $sampleTypeB],
            [$laboratoryB, $sampleTypeA],
        ] as [$context, $foreignSampleType]) {
            $missingId = $foreignSampleType->id + 10_000;

            $this->assertNeutralNotFound(
                $this->tenantRequest($user, $context)
                    ->getJson("/api/v1/sample-types/{$foreignSampleType->id}"),
                $this->tenantRequest($user, $context)
                    ->getJson("/api/v1/sample-types/{$missingId}"),
            );

            $this->assertNeutralNotFound(
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/sample-types/{$foreignSampleType->id}", ['name' => 'Injected']),
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/sample-types/{$missingId}", ['name' => 'Injected']),
            );

            $this->assertNeutralNotFound(
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/sample-types/{$foreignSampleType->id}/status", ['status' => 'inactive']),
                $this->tenantRequest($user, $context)
                    ->patchJson("/api/v1/sample-types/{$missingId}/status", ['status' => 'inactive']),
            );
        }

        foreach ([
            [$laboratoryA, $sampleTypeA->id],
            [$laboratoryB, $sampleTypeB->id],
            [$laboratoryA, $sampleTypeA->id],
            [$laboratoryB, $sampleTypeB->id],
        ] as [$context, $expectedId]) {
            $this->tenantRequest($user, $context)
                ->getJson('/api/v1/sample-types')
                ->assertOk()
                ->assertJsonPath('data.0.id', $expectedId)
                ->assertJsonCount(1, 'data');

            $this->tenantRequest($user, $context)
                ->getJson('/api/v1/sample-types/active')
                ->assertOk()
                ->assertJsonPath('data.0.id', $expectedId)
                ->assertJsonCount(1, 'data');

            $this->tenantRequest($user, $context)
                ->getJson("/api/v1/sample-types/{$expectedId}")
                ->assertOk()
                ->assertJsonPath('data.id', $expectedId);
        }

        $afterA = $sampleTypeA->fresh()->getRawOriginal();
        $afterB = $sampleTypeB->fresh()->getRawOriginal();
        ksort($beforeA);
        ksort($beforeB);
        ksort($afterA);
        ksort($afterB);

        $this->assertSame($beforeA, $afterA);
        $this->assertSame($beforeB, $afterB);
    }

    public function test_tenant_ownership_cannot_be_injected_through_any_sample_type_operation(): void
    {
        $user = User::factory()->create();
        $laboratoryA = $this->activeTenant($user);
        $laboratoryB = $this->activeTenant($user);

        $this->tenantRequest($user, $laboratoryA)
            ->postJson('/api/v1/sample-types', [
                'name' => 'Injected creation',
                'laboratory_id' => $laboratoryB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['laboratory_id']);

        $sampleType = SampleType::factory()->for($laboratoryA)->create(['name' => 'Original']);
        $before = $sampleType->getRawOriginal();

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/sample-types/{$sampleType->id}", [
                'name' => 'Injected update',
                'laboratory_id' => $laboratoryB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['laboratory_id']);

        $this->tenantRequest($user, $laboratoryA)
            ->patchJson("/api/v1/sample-types/{$sampleType->id}/status", [
                'status' => SampleType::STATUS_INACTIVE,
                'laboratory_id' => $laboratoryB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['laboratory_id']);

        $this->tenantRequest($user, $laboratoryA)
            ->getJson("/api/v1/sample-types/active?laboratory_id={$laboratoryB->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['laboratory_id']);

        $this->assertDatabaseMissing('sample_types', ['name' => 'Injected creation']);
        $after = $sampleType->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
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
        $this->assignDirectLaboratoryPermissions($user, $laboratory, ['sample_types.view', 'sample_types.create', 'sample_types.update', 'sample_types.change_status']);

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
