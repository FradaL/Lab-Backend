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

class PatientShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_detail_returns_the_complete_patient_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = Patient::factory()->for($laboratory)->create([
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
        ]);

        $this->patientRequest($user, $laboratory, $patient->id)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $patient->id,
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
                    'created_at' => '2026-09-23T12:00:00.000000Z',
                    'updated_at' => '2026-09-23T12:00:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.orders');
    }

    public function test_detail_keeps_all_nullable_keys_with_null_values(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = Patient::query()->create([
            'laboratory_id' => $laboratory->id,
            'first_names' => 'Nullable',
            'last_names' => 'Patient',
        ]);

        $response = $this->patientRequest($user, $laboratory, $patient->id)
            ->assertOk();

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
            $response->assertJsonPath("data.{$attribute}", null);
        }
    }

    public function test_cross_tenant_patient_and_missing_patient_both_return_not_found(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientB = Patient::factory()->for($labB)->create();

        $crossTenant = $this->patientRequest($user, $labA, $patientB->id)
            ->assertNotFound();

        $missing = $this->patientRequest($user, $labA, 999999)
            ->assertNotFound();

        $this->assertSame($crossTenant->status(), $missing->status());
        $crossTenant->assertJsonMissingPath('code');
        $missing->assertJsonMissingPath('code');

        $this->patientRequest($user, $labB, $patientB->id)
            ->assertOk()
            ->assertJsonPath('data.id', $patientB->id);
    }

    public function test_same_user_can_switch_laboratories_without_residual_patient_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientA = Patient::factory()->for($labA)->create();
        $patientB = Patient::factory()->for($labB)->create();

        $this->patientRequest($user, $labA, $patientA->id)->assertOk();
        $this->patientRequest($user, $labB, $patientB->id)->assertOk();
        $this->patientRequest($user, $labB, $patientA->id)->assertNotFound();
        $this->patientRequest($user, $labA, $patientB->id)->assertNotFound();
    }

    public function test_inactive_patient_can_be_viewed(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = Patient::factory()->for($laboratory)->create([
            'status' => Patient::STATUS_INACTIVE,
        ]);

        $this->patientRequest($user, $laboratory, $patient->id)
            ->assertOk()
            ->assertJsonPath('data.status', Patient::STATUS_INACTIVE);
    }

    #[DataProvider('invalidIdProvider')]
    public function test_invalid_patient_ids_return_not_found(string $patient): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory, $patient)
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
        $this->getJson('/api/v1/patients/999999')
            ->assertUnauthorized();
    }

    public function test_missing_laboratory_context_is_rejected_before_patient_resolution(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/patients/999999')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_invalid_membership_is_rejected_before_patient_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);

        $this->patientRequest($user, $laboratory, 999999)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_missing_subscription_is_rejected_before_patient_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->patientRequest($user, $laboratory, 999999)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
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

    private function patientRequest(
        User $user,
        Laboratory $laboratory,
        int|string $patient,
    ): TestResponse {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'patients.view');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/patients/{$patient}");
    }
}
