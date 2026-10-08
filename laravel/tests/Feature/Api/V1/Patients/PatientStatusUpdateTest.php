<?php

namespace Tests\Feature\Api\V1\Patients;

use App\Models\Laboratory;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    #[DataProvider('statusTransitionProvider')]
    public function test_status_transitions_are_persisted_and_return_the_full_detail(
        string $initialStatus,
        string $requestedStatus,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, ['status' => $initialStatus]);
        $originalUpdatedAt = $patient->updated_at;

        $this->travelTo(now()->addMinute());

        $this->statusRequest($user, $laboratory, $patient->id, [
            'status' => $requestedStatus,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $patient->id)
            ->assertJsonPath('data.status', $requestedStatus)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'first_names',
                    'last_names',
                    'birth_date',
                    'gender',
                    'phone',
                    'mobile',
                    'email',
                    'address',
                    'affiliation_number',
                    'weight',
                    'height',
                    'status',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id');

        $patient->refresh();

        $this->assertSame($requestedStatus, $patient->status);

        if ($initialStatus === $requestedStatus) {
            $this->assertTrue($patient->updated_at->equalTo($originalUpdatedAt));
        } else {
            $this->assertTrue($patient->updated_at->greaterThan($originalUpdatedAt));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function statusTransitionProvider(): array
    {
        return [
            'active to inactive' => [Patient::STATUS_ACTIVE, Patient::STATUS_INACTIVE],
            'inactive to active' => [Patient::STATUS_INACTIVE, Patient::STATUS_ACTIVE],
            'active to active' => [Patient::STATUS_ACTIVE, Patient::STATUS_ACTIVE],
            'inactive to inactive' => [Patient::STATUS_INACTIVE, Patient::STATUS_INACTIVE],
        ];
    }

    public function test_only_status_and_the_natural_update_timestamp_change(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);
        $original = $patient->getRawOriginal();

        $this->travelTo(now()->addMinute());

        $this->statusRequest($user, $laboratory, $patient->id, [
            'status' => Patient::STATUS_INACTIVE,
        ])->assertOk();

        $current = $patient->refresh()->getRawOriginal();

        foreach (array_keys($original) as $field) {
            if (in_array($field, ['status', 'updated_at'], true)) {
                continue;
            }

            $this->assertEquals($original[$field], $current[$field], "The {$field} field changed.");
        }

        $this->assertSame(Patient::STATUS_INACTIVE, $current['status']);
        $this->assertSame($laboratory->id, $current['laboratory_id']);
        $this->assertNotSame($original['updated_at'], $current['updated_at']);
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_payloads_are_rejected_without_changes(array $payload): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $this->statusRequest($user, $laboratory, $patient->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertPatientUnchanged($patient);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidStatusProvider(): array
    {
        return [
            'missing' => [[]],
            'null' => [['status' => null]],
            'empty' => [['status' => '']],
            'pending' => [['status' => 'pending']],
            'deleted' => [['status' => 'deleted']],
            'uppercase' => [['status' => 'ACTIVE']],
            'leading whitespace' => [['status' => ' active']],
        ];
    }

    #[DataProvider('unexpectedFieldProvider')]
    public function test_unexpected_fields_reject_the_whole_payload_without_changes(string $field, mixed $value): void
    {
        $user = User::factory()->create();
        [, $laboratory] = $this->activeTenant($user);
        [, $otherLaboratory] = $this->activeTenant($user);
        $patient = $this->patient($laboratory);

        if ($field === 'laboratory_id') {
            $value = $otherLaboratory->id;
        }

        $this->statusRequest($user, $laboratory, $patient->id, [
            'status' => Patient::STATUS_INACTIVE,
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertPatientUnchanged($patient);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function unexpectedFieldProvider(): array
    {
        return [
            'patient field' => ['first_names', 'Injected'],
            'ownership field' => ['laboratory_id', 999],
            'arbitrary field' => ['unexpected', true],
        ];
    }

    public function test_cross_tenant_and_missing_patients_have_the_same_not_found_response(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientB = $this->patient($labB);

        $crossTenant = $this->statusRequest($user, $labA, $patientB->id, [
            'status' => Patient::STATUS_INACTIVE,
        ])->assertNotFound();
        $missing = $this->statusRequest($user, $labA, 999999, [
            'status' => Patient::STATUS_INACTIVE,
        ])->assertNotFound();

        $this->assertSame($crossTenant->status(), $missing->status());
        $crossTenant->assertJsonMissingPath('code');
        $missing->assertJsonMissingPath('code');
        $this->assertPatientUnchanged($patientB);
    }

    public function test_same_user_can_switch_laboratories_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientA = $this->patient($labA);
        $patientB = $this->patient($labB);

        $this->statusRequest($user, $labA, $patientA->id, ['status' => Patient::STATUS_INACTIVE])
            ->assertOk();
        $this->statusRequest($user, $labB, $patientB->id, ['status' => Patient::STATUS_INACTIVE])
            ->assertOk();
        $this->statusRequest($user, $labB, $patientA->id, ['status' => Patient::STATUS_ACTIVE])
            ->assertNotFound();
        $this->statusRequest($user, $labA, $patientB->id, ['status' => Patient::STATUS_ACTIVE])
            ->assertNotFound();

        $this->assertSame(Patient::STATUS_INACTIVE, $patientA->refresh()->status);
        $this->assertSame(Patient::STATUS_INACTIVE, $patientB->refresh()->status);
    }

    #[DataProvider('invalidIdProvider')]
    public function test_invalid_patient_ids_return_not_found(string $patient): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->statusRequest($user, $laboratory, $patient, ['status' => Patient::STATUS_INACTIVE])
            ->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'non numeric' => ['abc'],
        ];
    }

    public function test_guest_is_rejected_before_patient_resolution(): void
    {
        $this->patchJson('/api/v1/patients/999999/status', ['status' => Patient::STATUS_INACTIVE])
            ->assertUnauthorized();
    }

    public function test_missing_laboratory_context_is_rejected_before_patient_resolution(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->patchJson('/api/v1/patients/999999/status', ['status' => Patient::STATUS_INACTIVE])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_invalid_membership_is_rejected_before_patient_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);

        $this->statusRequest($user, $laboratory, 999999, ['status' => Patient::STATUS_INACTIVE])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_missing_subscription_is_rejected_before_patient_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->statusRequest($user, $laboratory, 999999, ['status' => Patient::STATUS_INACTIVE])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_general_patient_patch_still_rejects_status(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/patients/{$patient->id}", ['status' => Patient::STATUS_INACTIVE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertPatientUnchanged($patient);
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

        $this->assignDirectLaboratoryPermissions($user, $laboratory, ['patients.update', 'patients.change_status']);

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
     * @param  array<string, mixed>  $attributes
     */
    private function patient(Laboratory $laboratory, array $attributes = []): Patient
    {
        return Patient::factory()->for($laboratory)->create([
            'first_names' => 'Daniel',
            'last_names' => 'Lara',
            'birth_date' => '1995-08-21',
            'gender' => 'male',
            'phone' => '77777777',
            'mobile' => '55555555',
            'email' => 'daniel@example.com',
            'address' => 'Retalhuleu',
            'affiliation_number' => 'ABC-123',
            'weight' => '75.50',
            'height' => '165.00',
            'status' => Patient::STATUS_ACTIVE,
            'notes' => 'Observaciones del paciente',
            ...$attributes,
        ]);
    }

    private function assertPatientUnchanged(Patient $patient): void
    {
        $original = $patient->getRawOriginal();
        $current = Patient::query()->findOrFail($patient->getKey())->getRawOriginal();

        $this->assertEquals($original, $current);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function statusRequest(
        User $user,
        Laboratory $laboratory,
        int|string $patient,
        array $payload,
    ): TestResponse {
        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'patients.update', 'patients.change_status',
        ]);

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/patients/{$patient}/status", $payload);
    }
}
