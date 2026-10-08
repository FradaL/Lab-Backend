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

class DoctorStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_doctor_is_created_with_minimum_data_and_database_defaults(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $response = $this->doctorRequest($user, $laboratory, [
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.first_names', 'Juan')
            ->assertJsonPath('data.last_names', 'Pérez')
            ->assertJsonPath('data.specialty', null)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.license_number', null)
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE)
            ->assertJsonPath('data.created_at', '2026-09-23T12:00:00.000000Z')
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.updated_at');

        $doctor = Doctor::query()->sole();

        $this->assertSame($laboratory->id, $doctor->laboratory_id);
        $this->assertSame(Doctor::STATUS_ACTIVE, $doctor->status);
        $this->assertNull($doctor->specialty);
        $this->assertNull($doctor->phone);
        $this->assertNull($doctor->email);
        $this->assertNull($doctor->license_number);
        $this->assertNull($doctor->notes);
    }

    public function test_complete_doctor_is_trimmed_without_changing_casing_and_long_notes_are_persisted(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $notes = str_repeat('Observación extensa. ', 12);

        $response = $this->doctorRequest($user, $laboratory, [
            'first_names' => '  Dr. José  ',
            'last_names' => '  Pérez McDonald  ',
            'specialty' => '  Cardiología  ',
            'phone' => '  +502 5555-5555 ext. 12  ',
            'email' => '  Jose.Perez@Example.COM  ',
            'license_number' => '  COL-AbC-123  ',
            'notes' => "  {$notes}  ",
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.first_names', 'Dr. José')
            ->assertJsonPath('data.last_names', 'Pérez McDonald')
            ->assertJsonPath('data.specialty', 'Cardiología')
            ->assertJsonPath('data.phone', '+502 5555-5555 ext. 12')
            ->assertJsonPath('data.email', 'Jose.Perez@Example.COM')
            ->assertJsonPath('data.license_number', 'COL-AbC-123')
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.updated_at');

        $doctor = Doctor::query()->sole();

        $this->assertSame($laboratory->id, $doctor->laboratory_id);
        $this->assertSame('Dr. José', $doctor->first_names);
        $this->assertSame('Pérez McDonald', $doctor->last_names);
        $this->assertSame('Cardiología', $doctor->specialty);
        $this->assertSame('+502 5555-5555 ext. 12', $doctor->phone);
        $this->assertSame('Jose.Perez@Example.COM', $doctor->email);
        $this->assertSame('COL-AbC-123', $doctor->license_number);
        $this->assertSame(trim($notes), $doctor->notes);
        $this->assertGreaterThan(150, mb_strlen($doctor->notes));
    }

    public function test_empty_optional_fields_are_persisted_as_null(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, [
            'first_names' => 'Ana',
            'last_names' => 'López',
            'specialty' => '',
            'phone' => '   ',
            'email' => '',
            'license_number' => '   ',
            'notes' => '',
        ])->assertCreated();

        $doctor = Doctor::query()->sole();

        foreach (['specialty', 'phone', 'email', 'license_number', 'notes'] as $attribute) {
            $this->assertNull($doctor->getAttribute($attribute));
        }
    }

    public function test_doctor_is_owned_exclusively_by_the_current_laboratory(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $doctorId = $this->doctorRequest($user, $labA, $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('doctors', [
            'id' => $doctorId,
            'laboratory_id' => $labA->id,
        ]);
        $this->assertTrue(Doctor::forLaboratory($labA)->whereKey($doctorId)->exists());
        $this->assertFalse(Doctor::forLaboratory($labB)->whereKey($doctorId)->exists());
    }

    public function test_same_user_can_create_in_two_laboratories_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        $doctorAId = $this->doctorRequest($user, $labA, [
            'first_names' => 'Doctor',
            'last_names' => 'A',
        ])->assertCreated()->json('data.id');

        $doctorBId = $this->doctorRequest($user, $labB, [
            'first_names' => 'Doctor',
            'last_names' => 'B',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('doctors', [
            'id' => $doctorAId,
            'laboratory_id' => $labA->id,
        ]);
        $this->assertDatabaseHas('doctors', [
            'id' => $doctorBId,
            'laboratory_id' => $labB->id,
        ]);
    }

    public function test_laboratory_id_injection_is_rejected_for_unknown_and_valid_other_tenants(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);

        foreach ([999999, $labB->id] as $injectedLaboratoryId) {
            $this->doctorRequest($user, $labA, [
                ...$this->validPayload(),
                'laboratory_id' => $injectedLaboratoryId,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['laboratory_id']);
        }

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_status_injection_is_rejected_without_creating_a_doctor(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, [
            ...$this->validPayload(),
            'status' => Doctor::STATUS_INACTIVE,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_other_server_controlled_fields_are_rejected(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, [
            ...$this->validPayload(),
            'id' => 123,
            'created_at' => '2020-01-01T00:00:00Z',
            'updated_at' => '2020-01-01T00:00:00Z',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'created_at', 'updated_at']);

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_unknown_property_is_rejected_without_creating_a_doctor(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, [
            ...$this->validPayload(),
            'foo' => 'bar',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['foo']);

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_duplicate_license_number_and_email_create_distinct_doctors(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $duplicates = [
            'email' => 'shared.doctor@example.test',
            'license_number' => 'COL-123',
        ];

        $firstId = $this->doctorRequest($user, $laboratory, [
            'first_names' => 'Juan',
            'last_names' => 'Uno',
            ...$duplicates,
        ])->assertCreated()->json('data.id');

        $secondId = $this->doctorRequest($user, $laboratory, [
            'first_names' => 'Juan',
            'last_names' => 'Dos',
            ...$duplicates,
        ])->assertCreated()->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, Doctor::query()
            ->where('email', $duplicates['email'])
            ->where('license_number', $duplicates['license_number'])
            ->count());
    }

    public function test_guest_is_rejected_before_creation(): void
    {
        $this->postJson('/api/v1/doctors', $this->validPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_missing_laboratory_context_is_rejected_before_creation(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/v1/doctors', $this->validPayload())
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_inactive_membership_is_rejected_before_creation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);

        $this->doctorRequest($user, $laboratory, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_missing_subscription_is_rejected_before_creation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->doctorRequest($user, $laboratory, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertDatabaseCount('doctors', 0);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected_atomically(
        array $payload,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('doctors', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        $valid = [
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
        ];

        return [
            'first names missing' => [['last_names' => 'Pérez'], 'first_names'],
            'last names missing' => [['first_names' => 'Juan'], 'last_names'],
            'first names null' => [[...$valid, 'first_names' => null], 'first_names'],
            'last names null' => [[...$valid, 'last_names' => null], 'last_names'],
            'first names empty' => [[...$valid, 'first_names' => ''], 'first_names'],
            'last names empty' => [[...$valid, 'last_names' => ''], 'last_names'],
            'first names spaces only' => [[...$valid, 'first_names' => '   '], 'first_names'],
            'last names spaces only' => [[...$valid, 'last_names' => '   '], 'last_names'],
            'first names too long' => [[...$valid, 'first_names' => str_repeat('a', 126)], 'first_names'],
            'last names too long' => [[...$valid, 'last_names' => str_repeat('a', 126)], 'last_names'],
            'specialty too long' => [[...$valid, 'specialty' => str_repeat('a', 126)], 'specialty'],
            'phone too long' => [[...$valid, 'phone' => str_repeat('1', 31)], 'phone'],
            'email invalid' => [[...$valid, 'email' => 'not-an-email'], 'email'],
            'email too long' => [[...$valid, 'email' => str_repeat('a', 140).'@example.com'], 'email'],
            'license number too long' => [[...$valid, 'license_number' => str_repeat('a', 31)], 'license_number'],
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
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function doctorRequest(
        User $user,
        Laboratory $laboratory,
        array $payload,
    ): TestResponse {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'doctors.create');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/doctors', $payload);
    }
}
