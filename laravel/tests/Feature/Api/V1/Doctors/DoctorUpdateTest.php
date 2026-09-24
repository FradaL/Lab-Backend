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

class DoctorUpdateTest extends TestCase
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
        $doctor = $this->doctor($laboratory);
        $original = $doctor->getAttributes();

        $this->doctorRequest($user, $laboratory, $doctor->id, [
            'phone' => '  +502 2222-9999  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+502 2222-9999')
            ->assertJsonPath('data.first_names', $original['first_names'])
            ->assertJsonPath('data.last_names', $original['last_names'])
            ->assertJsonPath('data.specialty', $original['specialty'])
            ->assertJsonPath('data.email', $original['email'])
            ->assertJsonPath('data.license_number', $original['license_number'])
            ->assertJsonPath('data.notes', $original['notes'])
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory');

        $doctor->refresh();

        $this->assertSame('+502 2222-9999', $doctor->phone);
        $this->assertSame($laboratory->id, $doctor->laboratory_id);
        $this->assertSame(Doctor::STATUS_ACTIVE, $doctor->status);

        foreach (['first_names', 'last_names', 'specialty', 'email', 'license_number', 'notes'] as $field) {
            $this->assertSame($original[$field], $doctor->getAttribute($field));
        }
    }

    public function test_multiple_fields_are_trimmed_with_casing_preserved_and_return_full_detail(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:01:00', 'UTC'));

        $this->doctorRequest($user, $laboratory, $doctor->id, [
            'first_names' => '  José María  ',
            'last_names' => '  Pérez McDonald  ',
            'specialty' => '  Cardiología Pediátrica  ',
            'phone' => '  +502 5555-5555 ext. 12  ',
            'email' => '  Jose.Mixed@example.com  ',
            'license_number' => '  Col-AbC-123  ',
            'notes' => '  Información actualizada.  ',
        ])->assertOk()->assertExactJson([
            'data' => [
                'id' => $doctor->id,
                'first_names' => 'José María',
                'last_names' => 'Pérez McDonald',
                'specialty' => 'Cardiología Pediátrica',
                'phone' => '+502 5555-5555 ext. 12',
                'email' => 'Jose.Mixed@example.com',
                'license_number' => 'Col-AbC-123',
                'status' => Doctor::STATUS_ACTIVE,
                'notes' => 'Información actualizada.',
                'created_at' => '2026-09-23T12:00:00.000000Z',
                'updated_at' => '2026-09-23T12:01:00.000000Z',
            ],
        ]);

        $this->assertDatabaseHas('doctors', [
            'id' => $doctor->id,
            'laboratory_id' => $laboratory->id,
            'first_names' => 'José María',
            'specialty' => 'Cardiología Pediátrica',
            'license_number' => 'Col-AbC-123',
            'status' => Doctor::STATUS_ACTIVE,
        ]);
    }

    public function test_nullable_fields_can_be_cleared_with_null_or_blank_strings(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $response = $this->doctorRequest($user, $laboratory, $doctor->id, [
            'specialty' => null,
            'phone' => '   ',
            'email' => null,
            'license_number' => '',
            'notes' => null,
        ])->assertOk();

        foreach (['specialty', 'phone', 'email', 'license_number', 'notes'] as $field) {
            $response->assertJsonPath("data.{$field}", null);
            $this->assertNull($doctor->refresh()->getAttribute($field));
        }
    }

    #[DataProvider('invalidNameProvider')]
    public function test_names_cannot_be_null_or_blank_when_present(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDoctorUnchanged($doctor);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidNameProvider(): array
    {
        return [
            'first names null' => ['first_names', null],
            'first names empty' => ['first_names', ''],
            'first names spaces' => ['first_names', '   '],
            'last names null' => ['last_names', null],
            'last names empty' => ['last_names', ''],
            'last names spaces' => ['last_names', '   '],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_field_constraints_are_enforced_atomically(array $payload, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDoctorUnchanged($doctor);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloadProvider(): array
    {
        return [
            'first names too long' => [['first_names' => str_repeat('a', 126)], 'first_names'],
            'last names too long' => [['last_names' => str_repeat('a', 126)], 'last_names'],
            'specialty too long' => [['specialty' => str_repeat('a', 126)], 'specialty'],
            'phone too long' => [['phone' => str_repeat('1', 31)], 'phone'],
            'email invalid' => [['email' => 'not-an-email'], 'email'],
            'email too long' => [['email' => str_repeat('a', 139).'@example.com'], 'email'],
            'license number too long' => [['license_number' => str_repeat('a', 31)], 'license_number'],
        ];
    }

    public function test_duplicate_license_number_and_email_are_allowed(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctorA = $this->doctor($laboratory, [
            'email' => 'shared@example.com',
            'license_number' => 'COL-123',
        ]);
        $doctorB = $this->doctor($laboratory, [
            'email' => 'other@example.com',
            'license_number' => 'COL-456',
        ]);

        $this->doctorRequest($user, $laboratory, $doctorB->id, [
            'email' => $doctorA->email,
            'license_number' => $doctorA->license_number,
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'shared@example.com')
            ->assertJsonPath('data.license_number', 'COL-123');

        $this->assertSame(2, Doctor::query()->where('email', 'shared@example.com')->count());
        $this->assertSame(2, Doctor::query()->where('license_number', 'COL-123')->count());
    }

    public function test_notes_longer_than_150_characters_are_persisted_completely(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);
        $notes = trim(str_repeat('Información clínica extensa. ', 10));

        $this->doctorRequest($user, $laboratory, $doctor->id, ['notes' => $notes])
            ->assertOk()
            ->assertJsonPath('data.notes', $notes);

        $this->assertSame($notes, $doctor->refresh()->notes);
        $this->assertGreaterThan(150, mb_strlen($doctor->notes));
    }

    public function test_empty_patch_is_rejected_without_modifying_the_doctor(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields']);

        $this->assertDoctorUnchanged($doctor);
    }

    #[DataProvider('serverControlledFieldProvider')]
    public function test_server_controlled_fields_are_rejected_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, [
            'phone' => '9999-9999',
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDoctorUnchanged($doctor);
    }

    /** @return array<string, array{string, mixed}> */
    public static function serverControlledFieldProvider(): array
    {
        return [
            'id' => ['id', 999999],
            'laboratory id' => ['laboratory_id', 999999],
            'status' => ['status', Doctor::STATUS_INACTIVE],
            'created at' => ['created_at', '2020-01-01T00:00:00Z'],
            'updated at' => ['updated_at', '2020-01-01T00:00:00Z'],
        ];
    }

    public function test_laboratory_injection_is_rejected_even_when_user_can_access_target_laboratory(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctor = $this->doctor($labA);

        $this->doctorRequest($user, $labA, $doctor->id, [
            'first_names' => 'HACKED',
            'laboratory_id' => $labB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['laboratory_id']);

        $this->assertDoctorUnchanged($doctor);
    }

    public function test_unknown_fields_are_rejected_without_partial_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, [
            'phone' => '9999-9999',
            'foo' => 'bar',
        ])->assertUnprocessable()->assertJsonValidationErrors(['foo']);

        $this->assertDoctorUnchanged($doctor);
    }

    public function test_validation_failure_does_not_apply_any_valid_field(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, [
            'phone' => '2222-2222',
            'email' => 'not-an-email',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertDoctorUnchanged($doctor);
    }

    public function test_same_value_update_is_valid(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, ['phone' => $doctor->phone])
            ->assertOk()
            ->assertJsonPath('data.phone', $doctor->phone);
    }

    public function test_inactive_doctor_can_be_updated_without_reactivation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory, ['status' => Doctor::STATUS_INACTIVE]);

        $this->doctorRequest($user, $laboratory, $doctor->id, ['phone' => '+502 5555-0000'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+502 5555-0000')
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);

        $this->assertSame(Doctor::STATUS_INACTIVE, $doctor->refresh()->status);
    }

    public function test_cross_tenant_and_missing_doctors_have_same_neutral_404_without_mutation(): void
    {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorB = $this->doctor($labB);
        $beforeCount = Doctor::query()->count();

        $crossTenant = $this->doctorRequest($user, $labA, $doctorB->id, ['first_names' => 'HACKED'])
            ->assertNotFound();
        $missing = $this->doctorRequest($user, $labA, 999999, ['first_names' => 'HACKED'])
            ->assertNotFound();

        $this->assertSame($crossTenant->status(), $missing->status());
        $this->assertSame(array_keys($crossTenant->json()), array_keys($missing->json()));

        foreach ([$crossTenant, $missing] as $response) {
            $response->assertJsonMissingPath('code');
            $body = $response->getContent();
            $this->assertStringNotContainsString('exception', strtolower($body));
            $this->assertStringNotContainsString('trace', strtolower($body));
            $this->assertStringNotContainsString((string) $labB->id, $body);
        }

        $this->assertDoctorUnchanged($doctorB);
        $this->assertSame($beforeCount, Doctor::query()->count());
    }

    public function test_switching_laboratories_updates_only_the_correct_doctor_and_is_symmetric(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorA = $this->doctor($labA);
        $doctorB = $this->doctor($labB);

        $this->doctorRequest($user, $labA, $doctorA->id, ['phone' => '1111-1111'])
            ->assertOk()->assertJsonPath('data.phone', '1111-1111');
        $this->doctorRequest($user, $labB, $doctorB->id, ['phone' => '2222-2222'])
            ->assertOk()->assertJsonPath('data.phone', '2222-2222');
        $this->doctorRequest($user, $labB, $doctorA->id, ['phone' => '3333-3333'])
            ->assertNotFound();
        $this->doctorRequest($user, $labA, $doctorB->id, ['phone' => '4444-4444'])
            ->assertNotFound();

        $this->assertSame('1111-1111', $doctorA->refresh()->phone);
        $this->assertSame('2222-2222', $doctorB->refresh()->phone);
    }

    public function test_nonexistent_update_does_not_create_a_doctor(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $beforeCount = Doctor::query()->count();

        $this->doctorRequest($user, $laboratory, 999999, ['phone' => '5555-5555'])
            ->assertNotFound();

        $this->assertSame($beforeCount, Doctor::query()->count());
    }

    #[DataProvider('invalidIdProvider')]
    public function test_invalid_route_values_return_not_found(string $doctor): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, $doctor, ['phone' => '5555-5555'])
            ->assertNotFound();
    }

    /** @return array<string, array{string}> */
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
        $this->patchJson("/api/v1/doctors/{$doctor->id}", ['phone' => '9999-9999'])
            ->assertUnauthorized();
        $this->assertDoctorUnchanged($doctor);
    }

    public function test_missing_laboratory_context_is_rejected_before_mutation(): void
    {
        $doctor = Doctor::factory()->create();

        $this->actingAs(User::factory()->create(), 'web')
            ->patchJson("/api/v1/doctors/{$doctor->id}", ['phone' => '9999-9999'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertDoctorUnchanged($doctor);
    }

    public function test_invalid_membership_is_rejected_before_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, ['phone' => '9999-9999'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $this->assertDoctorUnchanged($doctor);
    }

    public function test_missing_subscription_is_rejected_before_mutation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $doctor = $this->doctor($laboratory);

        $this->doctorRequest($user, $laboratory, $doctor->id, ['phone' => '9999-9999'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertDoctorUnchanged($doctor);
    }

    /** @return array{User, Laboratory} */
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

    /** @param array<string, mixed> $attributes */
    private function doctor(Laboratory $laboratory, array $attributes = []): Doctor
    {
        return Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'specialty' => 'Cardiología',
            'phone' => '5555-5555',
            'email' => 'juan@example.com',
            'license_number' => 'COL-123',
            'status' => Doctor::STATUS_ACTIVE,
            'notes' => 'Médico referente',
            ...$attributes,
        ]);
    }

    private function assertDoctorUnchanged(Doctor $doctor): void
    {
        $original = $doctor->getRawOriginal();
        $current = Doctor::query()->findOrFail($doctor->getKey())->getRawOriginal();

        $this->assertEquals($original, $current);
    }

    /** @param array<string, mixed> $payload */
    private function doctorRequest(
        User $user,
        Laboratory $laboratory,
        int|string $doctor,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/doctors/{$doctor}", $payload);
    }
}
