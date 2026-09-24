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

class PatientStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_patient_is_created_active_with_normalized_validated_data(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $response = $this->patientRequest($user, $laboratory, [
            'first_names' => '  Daniel Alejandro  ',
            'last_names' => '  Lara López  ',
            'birth_date' => '1995-08-21',
            'gender' => '  male  ',
            'phone' => '  +502 2222-2222  ',
            'mobile' => '  +502 5555-5555  ',
            'email' => '  Daniel@example.com  ',
            'address' => '  Ciudad de Guatemala  ',
            'affiliation_number' => '  AFF-12345  ',
            'weight' => '999.99',
            'height' => '0.01',
            'notes' => '  Primera consulta  ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.first_names', 'Daniel Alejandro')
            ->assertJsonPath('data.last_names', 'Lara López')
            ->assertJsonPath('data.birth_date', '1995-08-21')
            ->assertJsonPath('data.phone', '+502 2222-2222')
            ->assertJsonPath('data.mobile', '+502 5555-5555')
            ->assertJsonPath('data.email', 'Daniel@example.com')
            ->assertJsonPath('data.affiliation_number', 'AFF-12345')
            ->assertJsonPath('data.status', Patient::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.weight')
            ->assertJsonMissingPath('data.height')
            ->assertJsonMissingPath('data.notes');

        $patient = Patient::query()->sole();

        $this->assertSame($laboratory->id, $patient->laboratory_id);
        $this->assertSame(Patient::STATUS_ACTIVE, $patient->status);
        $this->assertSame('Ciudad de Guatemala', $patient->address);
        $this->assertSame('999.99', $patient->weight);
        $this->assertSame('0.01', $patient->height);
        $this->assertSame('Primera consulta', $patient->notes);
    }

    public function test_empty_optional_fields_are_persisted_as_null(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory, [
            'first_names' => 'Ana',
            'last_names' => 'López',
            'birth_date' => '',
            'gender' => '',
            'phone' => '',
            'mobile' => '',
            'email' => '',
            'address' => '',
            'affiliation_number' => '',
            'weight' => '',
            'height' => '',
            'notes' => '',
        ])->assertCreated();

        $patient = Patient::query()->sole();

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
        ] as $attribute) {
            $this->assertNull($patient->getAttribute($attribute));
        }
    }

    public function test_patient_is_owned_exclusively_by_the_current_laboratory(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $response = $this->patientRequest($user, $labA, $this->validPayload());
        $patientId = $response->assertCreated()->json('data.id');

        $this->assertDatabaseHas('patients', [
            'id' => $patientId,
            'laboratory_id' => $labA->id,
        ]);
        $this->assertTrue(Patient::forLaboratory($labA)->whereKey($patientId)->exists());
        $this->assertFalse(Patient::forLaboratory($labB)->whereKey($patientId)->exists());
    }

    public function test_same_user_can_create_in_two_laboratories_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $patientAId = $this->patientRequest($user, $labA, [
            'first_names' => 'Patient',
            'last_names' => 'A',
        ])->assertCreated()->json('data.id');

        $patientBId = $this->patientRequest($user, $labB, [
            'first_names' => 'Patient',
            'last_names' => 'B',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('patients', [
            'id' => $patientAId,
            'laboratory_id' => $labA->id,
        ]);
        $this->assertDatabaseHas('patients', [
            'id' => $patientBId,
            'laboratory_id' => $labB->id,
        ]);
    }

    public function test_laboratory_id_injection_is_rejected_for_unknown_and_valid_other_tenants(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        foreach ([999999, $labB->id] as $injectedLaboratoryId) {
            $this->patientRequest($user, $labA, [
                ...$this->validPayload(),
                'laboratory_id' => $injectedLaboratoryId,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['laboratory_id']);
        }

        $this->assertDatabaseCount('patients', 0);
    }

    public function test_status_injection_is_rejected_and_does_not_create_a_patient(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory, [
            ...$this->validPayload(),
            'status' => Patient::STATUS_INACTIVE,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('patients', 0);
    }

    public function test_other_server_controlled_fields_are_rejected(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory, [
            ...$this->validPayload(),
            'id' => 123,
            'created_at' => '2020-01-01T00:00:00Z',
            'updated_at' => '2020-01-01T00:00:00Z',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'created_at', 'updated_at']);

        $this->assertDatabaseCount('patients', 0);
    }

    public function test_guest_is_rejected_by_the_saas_pipeline(): void
    {
        $this->postJson('/api/v1/patients', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_missing_laboratory_context_is_rejected_by_the_saas_pipeline(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/v1/patients', $this->validPayload())
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_inactive_membership_is_rejected_by_the_saas_pipeline(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);

        $this->patientRequest($user, $laboratory, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertDatabaseCount('patients', 0);
    }

    public function test_missing_subscription_is_rejected_by_the_saas_pipeline(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->patientRequest($user, $laboratory, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertDatabaseCount('patients', 0);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected_without_creating_partial_data(
        array $payload,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('patients', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        $valid = [
            'first_names' => 'Daniel',
            'last_names' => 'Lara',
        ];

        return [
            'first names required' => [['last_names' => 'Lara'], 'first_names'],
            'last names required' => [['first_names' => 'Daniel'], 'last_names'],
            'first names empty after trim' => [[...$valid, 'first_names' => '   '], 'first_names'],
            'first names too long' => [[...$valid, 'first_names' => str_repeat('a', 76)], 'first_names'],
            'last names too long' => [[...$valid, 'last_names' => str_repeat('a', 76)], 'last_names'],
            'birth date wrong format' => [[...$valid, 'birth_date' => '09/23/2020'], 'birth_date'],
            'birth date in future' => [[...$valid, 'birth_date' => '2026-09-24'], 'birth_date'],
            'gender too long' => [[...$valid, 'gender' => str_repeat('a', 21)], 'gender'],
            'phone too long' => [[...$valid, 'phone' => str_repeat('1', 31)], 'phone'],
            'mobile too long' => [[...$valid, 'mobile' => str_repeat('1', 31)], 'mobile'],
            'email invalid' => [[...$valid, 'email' => 'not-an-email'], 'email'],
            'email too long' => [[...$valid, 'email' => str_repeat('a', 140).'@example.com'], 'email'],
            'affiliation too long' => [[...$valid, 'affiliation_number' => str_repeat('a', 51)], 'affiliation_number'],
            'weight zero' => [[...$valid, 'weight' => 0], 'weight'],
            'weight over column capacity' => [[...$valid, 'weight' => 1000], 'weight'],
            'weight excessive scale' => [[...$valid, 'weight' => 1.234], 'weight'],
            'height negative' => [[...$valid, 'height' => -1], 'height'],
            'height over column capacity' => [[...$valid, 'height' => 1000], 'height'],
            'height excessive scale' => [[...$valid, 'height' => 1.234], 'height'],
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
     * @return array{first_names: string, last_names: string}
     */
    private function validPayload(): array
    {
        return [
            'first_names' => 'Daniel',
            'last_names' => 'Lara',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function patientRequest(
        User $user,
        Laboratory $laboratory,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/patients', $payload);
    }
}
