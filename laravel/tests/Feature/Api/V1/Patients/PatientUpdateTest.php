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

class PatientUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_a_single_field_can_be_updated_without_changing_other_data(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);
        $original = $patient->getAttributes();

        $this->patientRequest($user, $laboratory, $patient->id, [
            'phone' => '  +502 2222-9999  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $patient->id)
            ->assertJsonPath('data.phone', '+502 2222-9999')
            ->assertJsonPath('data.first_names', $original['first_names'])
            ->assertJsonPath('data.last_names', $original['last_names'])
            ->assertJsonPath('data.address', $original['address'])
            ->assertJsonPath('data.status', Patient::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id');

        $patient->refresh();

        $this->assertSame('+502 2222-9999', $patient->phone);
        $this->assertSame($laboratory->id, $patient->laboratory_id);
        $this->assertSame(Patient::STATUS_ACTIVE, $patient->status);

        foreach (['first_names', 'last_names', 'email', 'address', 'notes'] as $field) {
            $this->assertSame($original[$field], $patient->getAttribute($field));
        }
    }

    public function test_multiple_fields_are_normalized_updated_and_returned_in_the_full_detail_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $response = $this->patientRequest($user, $laboratory, $patient->id, [
            'first_names' => '  María Fernanda  ',
            'last_names' => '  Pérez López  ',
            'birth_date' => '1993-04-15',
            'gender' => '  female  ',
            'phone' => '  22223333  ',
            'mobile' => '  55556666  ',
            'email' => '  maria@example.com  ',
            'address' => '  Quetzaltenango  ',
            'affiliation_number' => '  AFF-999  ',
            'weight' => '61.25',
            'height' => '164.50',
            'notes' => '  Control anual  ',
        ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $patient->id,
                    'first_names' => 'María Fernanda',
                    'last_names' => 'Pérez López',
                    'birth_date' => '1993-04-15',
                    'gender' => 'female',
                    'phone' => '22223333',
                    'mobile' => '55556666',
                    'email' => 'maria@example.com',
                    'address' => 'Quetzaltenango',
                    'affiliation_number' => 'AFF-999',
                    'weight' => '61.25',
                    'height' => '164.50',
                    'status' => Patient::STATUS_ACTIVE,
                    'notes' => 'Control anual',
                    'created_at' => '2026-09-23T12:00:00.000000Z',
                    'updated_at' => '2026-09-23T12:00:00.000000Z',
                ],
            ]);

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'laboratory_id' => $laboratory->id,
            'first_names' => 'María Fernanda',
            'last_names' => 'Pérez López',
            'email' => 'maria@example.com',
            'status' => Patient::STATUS_ACTIVE,
        ]);
    }

    public function test_nullable_fields_can_be_cleared_with_null_or_empty_strings(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $response = $this->patientRequest($user, $laboratory, $patient->id, [
            'birth_date' => null,
            'gender' => '',
            'phone' => null,
            'mobile' => '',
            'email' => null,
            'address' => '',
            'affiliation_number' => null,
            'weight' => '',
            'height' => null,
            'notes' => '',
        ])->assertOk();

        foreach ([
            'birth_date',
            'gender',
            'phone',
            'mobile',
            'email',
            'address',
            'affiliation_number',
            'weight',
            'height',
            'notes',
        ] as $field) {
            $response->assertJsonPath("data.{$field}", null);
            $this->assertNull($patient->refresh()->getAttribute($field));
        }
    }

    public function test_empty_patch_is_rejected_without_modifying_the_patient(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $this->patientRequest($user, $laboratory, $patient->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields']);

        $this->assertPatientUnchanged($patient);
    }

    public function test_server_controlled_fields_are_rejected_without_modifying_the_patient(): void
    {
        $user = User::factory()->create();
        [, $laboratory] = $this->activeTenant($user);
        [, $otherLaboratory] = $this->activeTenant($user);
        $patient = $this->patient($laboratory);

        $this->patientRequest($user, $laboratory, $patient->id, [
            'phone' => '99999999',
            'id' => 987654,
            'laboratory_id' => $otherLaboratory->id,
            'status' => Patient::STATUS_INACTIVE,
            'created_at' => '2020-01-01T00:00:00Z',
            'updated_at' => '2020-01-01T00:00:00Z',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'laboratory_id',
                'status',
                'created_at',
                'updated_at',
            ]);

        $this->assertPatientUnchanged($patient);
    }

    #[DataProvider('prohibitedOnlyPayloadProvider')]
    public function test_a_payload_with_only_a_prohibited_field_is_not_a_valid_patch(
        array $payload,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $this->patientRequest($user, $laboratory, $patient->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field, 'fields']);

        $this->assertPatientUnchanged($patient);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function prohibitedOnlyPayloadProvider(): array
    {
        return [
            'status' => [['status' => Patient::STATUS_INACTIVE], 'status'],
            'laboratory id' => [['laboratory_id' => 2], 'laboratory_id'],
        ];
    }

    #[DataProvider('invalidNameProvider')]
    public function test_names_cannot_be_null_or_blank_when_present(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $this->patientRequest($user, $laboratory, $patient->id, [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertPatientUnchanged($patient);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'first names null' => ['first_names', null],
            'first names blank' => ['first_names', '   '],
            'last names null' => ['last_names', null],
            'last names blank' => ['last_names', '   '],
        ];
    }

    public function test_cross_tenant_and_missing_patients_return_the_same_not_found_response_without_changes(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientB = $this->patient($labB);

        $crossTenant = $this->patientRequest($user, $labA, $patientB->id, ['phone' => '99999999'])
            ->assertNotFound();
        $missing = $this->patientRequest($user, $labA, 999999, ['phone' => '99999999'])
            ->assertNotFound();

        $this->assertSame($crossTenant->status(), $missing->status());
        $crossTenant->assertJsonMissingPath('code');
        $missing->assertJsonMissingPath('code');
        $this->assertPatientUnchanged($patientB);
    }

    public function test_same_user_can_update_in_two_laboratories_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientA = $this->patient($labA);
        $patientB = $this->patient($labB);

        $this->patientRequest($user, $labA, $patientA->id, ['phone' => '11111111'])
            ->assertOk()
            ->assertJsonPath('data.phone', '11111111');
        $this->patientRequest($user, $labB, $patientB->id, ['phone' => '22222222'])
            ->assertOk()
            ->assertJsonPath('data.phone', '22222222');
        $this->patientRequest($user, $labB, $patientA->id, ['phone' => '33333333'])
            ->assertNotFound();
        $this->patientRequest($user, $labA, $patientB->id, ['phone' => '44444444'])
            ->assertNotFound();

        $this->assertSame('11111111', $patientA->refresh()->phone);
        $this->assertSame('22222222', $patientB->refresh()->phone);
    }

    public function test_inactive_patient_can_be_updated_without_reactivating_it(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, [
            'status' => Patient::STATUS_INACTIVE,
        ]);

        $this->patientRequest($user, $laboratory, $patient->id, [
            'notes' => '  Seguimiento inactivo  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Seguimiento inactivo')
            ->assertJsonPath('data.status', Patient::STATUS_INACTIVE);

        $this->assertSame(Patient::STATUS_INACTIVE, $patient->refresh()->status);
    }

    #[DataProvider('invalidIdProvider')]
    public function test_invalid_patient_ids_return_not_found(string $patient): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory, $patient, ['phone' => '99999999'])
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
        $this->patchJson('/api/v1/patients/999999', ['phone' => '99999999'])
            ->assertUnauthorized();
    }

    public function test_missing_laboratory_context_is_rejected_before_patient_resolution(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->patchJson('/api/v1/patients/999999', ['phone' => '99999999'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_invalid_membership_is_rejected_before_patient_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);

        $this->patientRequest($user, $laboratory, 999999, ['phone' => '99999999'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_missing_subscription_is_rejected_before_patient_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->patientRequest($user, $laboratory, 999999, ['phone' => '99999999'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected_without_modifying_the_patient(
        array $payload,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory);

        $this->patientRequest($user, $laboratory, $patient->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertPatientUnchanged($patient);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'first names too long' => [['first_names' => str_repeat('a', 76)], 'first_names'],
            'last names too long' => [['last_names' => str_repeat('a', 76)], 'last_names'],
            'birth date wrong format' => [['birth_date' => '09/23/2020'], 'birth_date'],
            'birth date in future' => [['birth_date' => '2026-09-24'], 'birth_date'],
            'gender too long' => [['gender' => str_repeat('a', 21)], 'gender'],
            'phone too long' => [['phone' => str_repeat('1', 31)], 'phone'],
            'mobile too long' => [['mobile' => str_repeat('1', 31)], 'mobile'],
            'email invalid' => [['email' => 'not-an-email'], 'email'],
            'email too long' => [['email' => str_repeat('a', 140).'@example.com'], 'email'],
            'affiliation too long' => [['affiliation_number' => str_repeat('a', 51)], 'affiliation_number'],
            'weight zero' => [['weight' => 0], 'weight'],
            'weight over column capacity' => [['weight' => 1000], 'weight'],
            'weight excessive scale' => [['weight' => 1.234], 'weight'],
            'height negative' => [['height' => -1], 'height'],
            'height over column capacity' => [['height' => 1000], 'height'],
            'height excessive scale' => [['height' => 1.234], 'height'],
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
    private function patientRequest(
        User $user,
        Laboratory $laboratory,
        int|string $patient,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/patients/{$patient}", $payload);
    }
}
