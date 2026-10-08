<?php

namespace Tests\Feature\Api\V1\Doctors;

use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DoctorShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_detail_returns_the_complete_doctor_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan Carlos',
            'last_names' => 'Pérez López',
            'specialty' => 'Cardiología',
            'phone' => '+502 5555-5555',
            'email' => 'juan@example.com',
            'license_number' => 'COL-12345',
            'status' => Doctor::STATUS_ACTIVE,
            'notes' => 'Médico referente.',
        ]);

        $this->doctorRequest($user, $laboratory, $doctor->id)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $doctor->id,
                    'first_names' => 'Juan Carlos',
                    'last_names' => 'Pérez López',
                    'specialty' => 'Cardiología',
                    'phone' => '+502 5555-5555',
                    'email' => 'juan@example.com',
                    'license_number' => 'COL-12345',
                    'status' => Doctor::STATUS_ACTIVE,
                    'notes' => 'Médico referente.',
                    'created_at' => '2026-09-23T12:00:00.000000Z',
                    'updated_at' => '2026-09-23T12:00:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.patients')
            ->assertJsonMissingPath('data.orders');
    }

    public function test_detail_keeps_all_nullable_keys_with_null_values(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = Doctor::query()->create([
            'laboratory_id' => $laboratory->id,
            'first_names' => 'Nullable',
            'last_names' => 'Doctor',
        ]);

        $response = $this->doctorRequest($user, $laboratory, $doctor->id)
            ->assertOk();

        foreach (['specialty', 'phone', 'email', 'license_number', 'notes'] as $attribute) {
            $response->assertJsonPath("data.{$attribute}", null);
        }
    }

    public function test_inactive_doctor_can_be_viewed(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = Doctor::factory()->for($laboratory)->create([
            'status' => Doctor::STATUS_INACTIVE,
        ]);

        $this->doctorRequest($user, $laboratory, $doctor->id)
            ->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);
    }

    public function test_cross_tenant_and_nonexistent_doctors_share_the_same_public_not_found_contract(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorB = Doctor::factory()->for($labB)->create();

        $crossTenant = $this->doctorRequest($user, $labA, $doctorB->id)
            ->assertNotFound();

        $missing = $this->doctorRequest($user, $labA, 999999)
            ->assertNotFound();

        $this->assertSame($crossTenant->status(), $missing->status());
        $this->assertSame(array_keys($crossTenant->json()), array_keys($missing->json()));

        foreach ([$crossTenant, $missing] as $response) {
            $response
                ->assertJsonMissingPath('code')
                ->assertJsonMissingPath('exception')
                ->assertJsonMissingPath('file')
                ->assertJsonMissingPath('trace')
                ->assertJsonMissingPath('laboratory_id');

            $json = json_encode($response->json(), JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString('SQLSTATE', $json);
            $this->assertStringNotContainsString('/var/www', $json);
        }
    }

    public function test_tenant_isolation_is_symmetric_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorA = Doctor::factory()->for($labA)->create();
        $doctorB = Doctor::factory()->for($labB)->create();

        $this->doctorRequest($user, $labA, $doctorA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $doctorA->id);
        $this->doctorRequest($user, $labB, $doctorB->id)
            ->assertOk()
            ->assertJsonPath('data.id', $doctorB->id);
        $this->doctorRequest($user, $labA, $doctorB->id)->assertNotFound();
        $this->doctorRequest($user, $labB, $doctorA->id)->assertNotFound();
    }

    #[DataProvider('invalidIdProvider')]
    public function test_invalid_doctor_ids_return_not_found(string $doctor): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, $doctor)
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

    public function test_guest_is_rejected_before_doctor_resolution(): void
    {
        $doctor = Doctor::factory()->create();

        $this->getJson("/api/v1/doctors/{$doctor->id}")
            ->assertUnauthorized();
    }

    public function test_missing_laboratory_context_is_rejected_before_doctor_resolution(): void
    {
        $doctor = Doctor::factory()->create();

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson("/api/v1/doctors/{$doctor->id}")
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_invalid_membership_is_rejected_before_doctor_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $doctor = Doctor::factory()->for($laboratory)->create();
        $this->createCurrentSubscription($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_missing_subscription_is_rejected_before_doctor_resolution(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $doctor = Doctor::factory()->for($laboratory)->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->doctorRequest($user, $laboratory, $doctor->id)
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

    private function doctorRequest(
        User $user,
        Laboratory $laboratory,
        int|string $doctor,
    ): TestResponse {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'doctors.view');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/doctors/{$doctor}");
    }
}
