<?php

namespace Tests\Feature\Api\V1\Doctors;

use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_doctor_lifecycle_is_consistent_across_all_five_operations(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $created = $this->tenantRequest($user, $laboratory)
            ->postJson('/api/v1/doctors', [
                'first_names' => '  José María  ',
                'last_names' => '  Muñoz Álvarez  ',
                'specialty' => '  Cardiología  ',
                'phone' => '5555-1111',
                'email' => 'jose.integration@example.test',
                'license_number' => 'INT-COL-Á123',
                'notes' => 'Registro integrado inicial.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.first_names', 'José María')
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.updated_at')
            ->assertJsonMissingPath('data.laboratory_id');

        $doctorId = $created->json('data.id');

        $this->assertDatabaseHas('doctors', [
            'id' => $doctorId,
            'laboratory_id' => $laboratory->id,
            'status' => Doctor::STATUS_ACTIVE,
        ]);

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/doctors')
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctorId)
            ->assertJsonPath('meta.total', 1);

        foreach (['mar', 'muñ', 'col-'] as $search) {
            $this->tenantRequest($user, $laboratory)
                ->getJson('/api/v1/doctors?search='.urlencode($search))
                ->assertOk()
                ->assertJsonPath('data.0.id', $doctorId);
        }

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/doctors?specialty='.urlencode('cardiología'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctorId);

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/doctors?specialty='.urlencode('cardiología pediátrica'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->tenantRequest($user, $laboratory)
            ->getJson("/api/v1/doctors/{$doctorId}")
            ->assertOk()
            ->assertJsonPath('data.notes', 'Registro integrado inicial.')
            ->assertJsonMissingPath('data.laboratory_id');

        $this->tenantRequest($user, $laboratory)
            ->patchJson("/api/v1/doctors/{$doctorId}", [
                'phone' => '+502 5555-2222',
                'notes' => 'Actualizado antes de desactivar.',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+502 5555-2222')
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->getJson("/api/v1/doctors/{$doctorId}")
            ->assertOk()
            ->assertJsonPath('data.notes', 'Actualizado antes de desactivar.');

        $this->tenantRequest($user, $laboratory)
            ->patchJson("/api/v1/doctors/{$doctorId}/status", [
                'status' => Doctor::STATUS_INACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/doctors?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctorId);

        $this->tenantRequest($user, $laboratory)
            ->getJson("/api/v1/doctors/{$doctorId}")
            ->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->patchJson("/api/v1/doctors/{$doctorId}", [
                'specialty' => 'Medicina Interna',
                'notes' => 'Editable mientras está inactivo.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE)
            ->assertJsonPath('data.specialty', 'Medicina Interna');

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/doctors?search='.urlencode('123').'&status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctorId);

        $this->tenantRequest($user, $laboratory)
            ->patchJson("/api/v1/doctors/{$doctorId}/status", [
                'status' => Doctor::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE);

        $this->tenantRequest($user, $laboratory)
            ->getJson('/api/v1/doctors?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctorId);

        $this->tenantRequest($user, $laboratory)
            ->getJson("/api/v1/doctors/{$doctorId}")
            ->assertOk()
            ->assertJsonPath('data.first_names', 'José María')
            ->assertJsonPath('data.last_names', 'Muñoz Álvarez')
            ->assertJsonPath('data.specialty', 'Medicina Interna')
            ->assertJsonPath('data.phone', '+502 5555-2222')
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE)
            ->assertJsonPath('data.notes', 'Editable mientras está inactivo.')
            ->assertJsonMissingPath('data.laboratory_id');
    }

    public function test_two_laboratories_remain_isolated_during_reads_writes_and_context_switching(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $shared = [
            'email' => 'shared.integration@example.test',
            'license_number' => 'SHARED-INT-001',
        ];

        $doctorA = $this->createDoctorThroughApi($user, $labA, [
            'first_names' => 'Ana',
            'last_names' => 'TenantA',
            ...$shared,
        ]);
        $doctorB = $this->createDoctorThroughApi($user, $labB, [
            'first_names' => 'Beto',
            'last_names' => 'TenantB',
            ...$shared,
        ]);

        $this->assertNotSame($doctorA, $doctorB);
        $this->assertDatabaseHas('doctors', ['id' => $doctorA, 'laboratory_id' => $labA->id]);
        $this->assertDatabaseHas('doctors', ['id' => $doctorB, 'laboratory_id' => $labB->id]);
        $this->assertSame(2, Doctor::query()->where('email', $shared['email'])->count());
        $this->assertSame(2, Doctor::query()->where('license_number', $shared['license_number'])->count());

        $this->tenantRequest($user, $labA)
            ->getJson('/api/v1/doctors?search=SHARED-INT')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $doctorA);
        $this->tenantRequest($user, $labB)
            ->getJson('/api/v1/doctors?search=shared-int')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $doctorB);

        $this->tenantRequest($user, $labA)->getJson("/api/v1/doctors/{$doctorB}")->assertNotFound();
        $this->tenantRequest($user, $labB)->getJson("/api/v1/doctors/{$doctorA}")->assertNotFound();
        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/doctors/{$doctorB}", ['phone' => 'HACKED'])
            ->assertNotFound();
        $this->tenantRequest($user, $labB)
            ->patchJson("/api/v1/doctors/{$doctorA}", ['phone' => 'HACKED'])
            ->assertNotFound();
        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/doctors/{$doctorB}/status", ['status' => Doctor::STATUS_INACTIVE])
            ->assertNotFound();
        $this->tenantRequest($user, $labB)
            ->patchJson("/api/v1/doctors/{$doctorA}/status", ['status' => Doctor::STATUS_INACTIVE])
            ->assertNotFound();

        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/doctors/{$doctorA}", ['phone' => '1111-1111'])
            ->assertOk();
        $this->tenantRequest($user, $labB)
            ->patchJson("/api/v1/doctors/{$doctorB}", ['phone' => '2222-2222'])
            ->assertOk();
        $this->tenantRequest($user, $labA)
            ->patchJson("/api/v1/doctors/{$doctorA}/status", ['status' => Doctor::STATUS_INACTIVE])
            ->assertOk();
        $this->tenantRequest($user, $labB)
            ->getJson("/api/v1/doctors/{$doctorB}")
            ->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE);

        $this->assertDatabaseHas('doctors', [
            'id' => $doctorA,
            'laboratory_id' => $labA->id,
            'phone' => '1111-1111',
            'status' => Doctor::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseHas('doctors', [
            'id' => $doctorB,
            'laboratory_id' => $labB->id,
            'phone' => '2222-2222',
            'status' => Doctor::STATUS_ACTIVE,
        ]);
    }

    public function test_cross_tenant_and_missing_errors_are_indistinguishable_without_debug_leakage(): void
    {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorB = Doctor::factory()->for($labB)->create();
        $missingId = 999999;

        $responses = [
            [
                $this->tenantRequest($user, $labA)->getJson("/api/v1/doctors/{$doctorB->id}"),
                $this->tenantRequest($user, $labA)->getJson("/api/v1/doctors/{$missingId}"),
            ],
            [
                $this->tenantRequest($user, $labA)->patchJson(
                    "/api/v1/doctors/{$doctorB->id}",
                    ['phone' => '5555-9999'],
                ),
                $this->tenantRequest($user, $labA)->patchJson(
                    "/api/v1/doctors/{$missingId}",
                    ['phone' => '5555-9999'],
                ),
            ],
            [
                $this->tenantRequest($user, $labA)->patchJson(
                    "/api/v1/doctors/{$doctorB->id}/status",
                    ['status' => Doctor::STATUS_INACTIVE],
                ),
                $this->tenantRequest($user, $labA)->patchJson(
                    "/api/v1/doctors/{$missingId}/status",
                    ['status' => Doctor::STATUS_INACTIVE],
                ),
            ],
        ];

        foreach ($responses as [$crossTenant, $missing]) {
            $crossTenant->assertNotFound();
            $missing->assertNotFound();
            $this->assertSame($crossTenant->json(), $missing->json());

            foreach ([$crossTenant, $missing] as $response) {
                $body = strtolower($response->getContent());
                $this->assertStringNotContainsString('exception', $body);
                $this->assertStringNotContainsString('trace', $body);
                $this->assertStringNotContainsString('sqlstate', $body);
                $this->assertStringNotContainsString('/var/www', $body);
                $this->assertStringNotContainsString('laboratory_id', $body);
                $this->assertStringNotContainsString((string) $labB->id, $body);
            }
        }

        $this->assertSame(Doctor::STATUS_ACTIVE, $doctorB->refresh()->status);
    }

    /** @return array{User, Laboratory} */
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

    /** @param array<string, mixed> $attributes */
    private function createDoctorThroughApi(User $user, Laboratory $laboratory, array $attributes): int
    {
        $response = $this->tenantRequest($user, $laboratory)
            ->postJson('/api/v1/doctors', [
                'specialty' => 'Medicina General',
                'phone' => '5555-5555',
                'notes' => 'Integración multi-tenant.',
                ...$attributes,
            ])
            ->assertCreated();

        return (int) $response->json('data.id');
    }

    private function tenantRequest(User $user, Laboratory $laboratory): static
    {
        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'doctors.view', 'doctors.create', 'doctors.update', 'doctors.change_status',
        ]);

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }
}
