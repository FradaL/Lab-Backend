<?php

namespace Tests\Feature\Api\V1\Patients;

use App\Models\Laboratory;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_patient_lifecycle_works_as_one_integrated_flow(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $created = $this->tenantRequest($user, $laboratory)
            ->postJson('/api/v1/patients', [
                'first_names' => 'Integration',
                'last_names' => 'Patient',
                'email' => 'integration@example.test',
                'weight' => '72.50',
                'height' => '168.25',
                'notes' => 'Created in integration flow',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Patient::STATUS_ACTIVE);

        $patientId = $created->json('data.id');

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/patients?search=integration&sort=last_names&direction=asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patientId);

        $this->tenantRequest($user, $laboratory)
            ->getJson("/api/v1/patients/{$patientId}")
            ->assertOk()
            ->assertJsonPath('data.email', 'integration@example.test');

        $this->tenantRequest($user, $laboratory)
            ->patchJson("/api/v1/patients/{$patientId}", [
                'first_names' => 'Integrated',
                'notes' => 'Updated in integration flow',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_names', 'Integrated')
            ->assertJsonPath('data.status', Patient::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->getJson("/api/v1/patients/{$patientId}")
            ->assertOk()
            ->assertJsonPath('data.notes', 'Updated in integration flow');

        $this->tenantRequest($user, $laboratory)
            ->patchJson("/api/v1/patients/{$patientId}/status", [
                'status' => Patient::STATUS_INACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Patient::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/patients?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $patientId)
            ->assertJsonPath('data.0.status', Patient::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->patchJson("/api/v1/patients/{$patientId}/status", [
                'status' => Patient::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Patient::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->getJson("/api/v1/patients/{$patientId}")
            ->assertOk()
            ->assertJsonPath('data.first_names', 'Integrated')
            ->assertJsonPath('data.status', Patient::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id');

        $this->assertDatabaseHas('patients', [
            'id' => $patientId,
            'laboratory_id' => $laboratory->id,
            'status' => Patient::STATUS_ACTIVE,
            'notes' => 'Updated in integration flow',
        ]);
    }

    public function test_two_laboratories_are_isolated_across_the_complete_patient_api(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $patientA = $this->tenantRequest($user, $labA)
            ->postJson('/api/v1/patients', [
                'first_names' => 'Alpha',
                'last_names' => 'Tenant',
            ])
            ->assertCreated()
            ->json('data.id');
        $patientB = $this->tenantRequest($user, $labB)
            ->postJson('/api/v1/patients', [
                'first_names' => 'Beta',
                'last_names' => 'Tenant',
            ])
            ->assertCreated()
            ->json('data.id');

        $listA = $this->tenantRequest($user, $labA)->getJson('/api/v1/patients')->assertOk();
        $listB = $this->tenantRequest($user, $labB)->getJson('/api/v1/patients')->assertOk();

        $this->assertSame([$patientA], collect($listA->json('data'))->pluck('id')->all());
        $this->assertSame([$patientB], collect($listB->json('data'))->pluck('id')->all());

        $this->tenantRequest($user, $labA)->getJson("/api/v1/patients/{$patientA}")->assertOk();
        $this->tenantRequest($user, $labA)->getJson("/api/v1/patients/{$patientB}")->assertNotFound();
        $this->tenantRequest($user, $labB)->getJson("/api/v1/patients/{$patientB}")->assertOk();
        $this->tenantRequest($user, $labB)->getJson("/api/v1/patients/{$patientA}")->assertNotFound();

        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/patients/{$patientA}", ['notes' => 'Updated by A'])
            ->assertOk();
        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/patients/{$patientB}", ['notes' => 'Injected by A'])
            ->assertNotFound();
        $this->tenantRequest($user, $labB)
            ->patchJson("/api/v1/patients/{$patientB}", ['notes' => 'Updated by B'])
            ->assertOk();
        $this->tenantRequest($user, $labB)
            ->patchJson("/api/v1/patients/{$patientA}", ['notes' => 'Injected by B'])
            ->assertNotFound();

        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/patients/{$patientA}/status", ['status' => Patient::STATUS_INACTIVE])
            ->assertOk();
        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/patients/{$patientB}/status", ['status' => Patient::STATUS_INACTIVE])
            ->assertNotFound();
        $this->tenantRequest($user, $labB)
            ->patchJson("/api/v1/patients/{$patientB}/status", ['status' => Patient::STATUS_INACTIVE])
            ->assertOk();
        $this->tenantRequest($user, $labB)
            ->patchJson("/api/v1/patients/{$patientA}/status", ['status' => Patient::STATUS_ACTIVE])
            ->assertNotFound();

        $this->assertDatabaseHas('patients', [
            'id' => $patientA,
            'laboratory_id' => $labA->id,
            'notes' => 'Updated by A',
            'status' => Patient::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseHas('patients', [
            'id' => $patientB,
            'laboratory_id' => $labB->id,
            'notes' => 'Updated by B',
            'status' => Patient::STATUS_INACTIVE,
        ]);
    }

    #[DataProvider('individualEndpointProvider')]
    public function test_cross_tenant_and_missing_patient_responses_are_identical_in_non_debug_mode(
        string $method,
        string $suffix,
        array $payload,
    ): void {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientB = Patient::factory()->for($labB)->create();
        $path = "/api/v1/patients/{$patientB->id}{$suffix}";

        $crossTenant = $this->individualRequest($user, $labA, $method, $path, $payload)
            ->assertNotFound()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('trace');

        $patientB->delete();

        $missing = $this->individualRequest($user, $labA, $method, $path, $payload)
            ->assertNotFound()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('trace');

        $this->assertSame($crossTenant->json(), $missing->json());
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function individualEndpointProvider(): array
    {
        return [
            'detail' => ['get', '', []],
            'update' => ['patch', '', ['notes' => 'Should not persist']],
            'status' => ['patch', '/status', ['status' => Patient::STATUS_INACTIVE]],
        ];
    }

    /**
     * @return array{User, Laboratory}
     */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [$user, $laboratory];
    }

    private function tenantRequest(User $user, Laboratory $laboratory): static
    {
        $this->assignDirectLaboratoryPermissions($user, $laboratory, ['patients.view', 'patients.create', 'patients.update', 'patients.change_status']);

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function individualRequest(
        User $user,
        Laboratory $laboratory,
        string $method,
        string $path,
        array $payload,
    ): TestResponse {
        $request = $this->tenantRequest($user, $laboratory);

        return $method === 'get'
            ? $request->getJson($path)
            : $request->patchJson($path, $payload);
    }
}
