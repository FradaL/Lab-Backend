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

class DoctorStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    #[DataProvider('statusTransitionProvider')]
    public function test_status_transitions_are_persisted_and_return_full_detail(
        string $initialStatus,
        string $requestedStatus,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory, ['status' => $initialStatus]);
        $originalUpdatedAt = $doctor->updated_at;

        $this->travelTo(now()->addMinute());

        $this->statusRequest($user, $laboratory, $doctor->id, [
            'status' => $requestedStatus,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $doctor->id)
            ->assertJsonPath('data.status', $requestedStatus)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'first_names',
                    'last_names',
                    'specialty',
                    'phone',
                    'email',
                    'license_number',
                    'status',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.orders')
            ->assertJsonMissingPath('data.patients');

        $doctor->refresh();

        $this->assertSame($requestedStatus, $doctor->status);

        if ($initialStatus === $requestedStatus) {
            $this->assertTrue($doctor->updated_at->equalTo($originalUpdatedAt));
        } else {
            $this->assertTrue($doctor->updated_at->greaterThan($originalUpdatedAt));
        }
    }

    /** @return array<string, array{string, string}> */
    public static function statusTransitionProvider(): array
    {
        return [
            'active to inactive' => [Doctor::STATUS_ACTIVE, Doctor::STATUS_INACTIVE],
            'inactive to active' => [Doctor::STATUS_INACTIVE, Doctor::STATUS_ACTIVE],
            'active to active' => [Doctor::STATUS_ACTIVE, Doctor::STATUS_ACTIVE],
            'inactive to inactive' => [Doctor::STATUS_INACTIVE, Doctor::STATUS_INACTIVE],
        ];
    }

    public function test_only_status_and_natural_updated_at_change(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);
        $original = $doctor->getRawOriginal();

        $this->travelTo(now()->addMinute());

        $this->statusRequest($user, $laboratory, $doctor->id, [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertOk();

        $current = $doctor->refresh()->getRawOriginal();

        foreach (array_keys($original) as $field) {
            if (in_array($field, ['status', 'updated_at'], true)) {
                continue;
            }

            $this->assertEquals($original[$field], $current[$field], "The {$field} field changed.");
        }

        $this->assertSame(Doctor::STATUS_INACTIVE, $current['status']);
        $this->assertSame($laboratory->id, $current['laboratory_id']);
        $this->assertNotSame($original['updated_at'], $current['updated_at']);
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_payloads_are_rejected_without_changes(array $payload): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->statusRequest($user, $laboratory, $doctor->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDoctorUnchanged($doctor);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'missing' => [[]],
            'null' => [['status' => null]],
            'empty' => [['status' => '']],
            'pending' => [['status' => 'pending']],
            'deleted' => [['status' => 'deleted']],
            'disabled' => [['status' => 'disabled']],
            'enabled' => [['status' => 'enabled']],
            'uppercase' => [['status' => 'ACTIVE']],
            'mixed case' => [['status' => 'Inactive']],
            'leading whitespace' => [['status' => ' active']],
            'trailing whitespace' => [['status' => 'inactive ']],
            'integer one' => [['status' => 1]],
            'integer zero' => [['status' => 0]],
            'boolean true' => [['status' => true]],
            'boolean false' => [['status' => false]],
        ];
    }

    #[DataProvider('unexpectedFieldProvider')]
    public function test_unexpected_fields_reject_whole_payload_without_changes(string $field, mixed $value): void
    {
        $user = User::factory()->create();
        [, $laboratory] = $this->activeTenant($user);
        [, $otherLaboratory] = $this->activeTenant($user);
        $doctor = $this->doctor($laboratory);

        if ($field === 'laboratory_id') {
            $value = $otherLaboratory->id;
        }

        $this->statusRequest($user, $laboratory, $doctor->id, [
            'status' => Doctor::STATUS_INACTIVE,
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDoctorUnchanged($doctor);
    }

    /** @return array<string, array{string, mixed}> */
    public static function unexpectedFieldProvider(): array
    {
        return [
            'first names' => ['first_names', 'HACKED'],
            'last names' => ['last_names', 'HACKED'],
            'specialty' => ['specialty', 'HACKED'],
            'phone' => ['phone', '9999-9999'],
            'email' => ['email', 'hacked@example.com'],
            'license number' => ['license_number', 'HACKED'],
            'notes' => ['notes', 'HACKED'],
            'laboratory id' => ['laboratory_id', 999],
            'id' => ['id', 999],
            'updated at' => ['updated_at', '2020-01-01T00:00:00Z'],
            'arbitrary field' => ['foo', 'bar'],
        ];
    }

    public function test_cross_tenant_and_missing_doctors_have_same_neutral_404_without_mutation(): void
    {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorB = $this->doctor($labB);

        $crossTenant = $this->statusRequest($user, $labA, $doctorB->id, [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertNotFound();
        $missing = $this->statusRequest($user, $labA, 999999, [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertNotFound();

        $this->assertSame($crossTenant->status(), $missing->status());
        $this->assertSame(array_keys($crossTenant->json()), array_keys($missing->json()));

        foreach ([$crossTenant, $missing] as $response) {
            $response->assertJsonMissingPath('code');
            $body = strtolower($response->getContent());
            $this->assertStringNotContainsString('exception', $body);
            $this->assertStringNotContainsString('trace', $body);
        }

        $this->assertDoctorUnchanged($doctorB);
    }

    public function test_switching_laboratories_changes_only_correct_doctor_and_is_symmetric(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorA = $this->doctor($labA);
        $doctorB = $this->doctor($labB);

        $this->statusRequest($user, $labA, $doctorA->id, ['status' => Doctor::STATUS_INACTIVE])
            ->assertOk();
        $this->statusRequest($user, $labB, $doctorB->id, ['status' => Doctor::STATUS_INACTIVE])
            ->assertOk();
        $this->statusRequest($user, $labB, $doctorA->id, ['status' => Doctor::STATUS_ACTIVE])
            ->assertNotFound();
        $this->statusRequest($user, $labA, $doctorB->id, ['status' => Doctor::STATUS_ACTIVE])
            ->assertNotFound();

        $this->assertSame(Doctor::STATUS_INACTIVE, $doctorA->refresh()->status);
        $this->assertSame(Doctor::STATUS_INACTIVE, $doctorB->refresh()->status);
    }

    public function test_inactive_doctor_remains_visible_editable_searchable_and_can_be_reactivated(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = $this->doctor($laboratory);

        $this->statusRequest($user, $laboratory, $doctor->id, [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertOk()->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/doctors/{$doctor->id}")
            ->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/doctors/{$doctor->id}", ['phone' => '+502 5555-0000'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+502 5555-0000')
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/doctors?status=inactive&search=COL-123')
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctor->id)
            ->assertJsonPath('data.0.status', Doctor::STATUS_INACTIVE);

        $this->statusRequest($user, $laboratory, $doctor->id, [
            'status' => Doctor::STATUS_ACTIVE,
        ])->assertOk()->assertJsonPath('data.status', Doctor::STATUS_ACTIVE);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/doctors?status=active&search=COL-123')
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctor->id)
            ->assertJsonPath('data.0.status', Doctor::STATUS_ACTIVE);
    }

    #[DataProvider('invalidIdProvider')]
    public function test_invalid_route_values_return_not_found(string $doctor): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->statusRequest($user, $laboratory, $doctor, ['status' => Doctor::STATUS_INACTIVE])
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

    public function test_guest_is_rejected_before_doctor_resolution_without_mutation(): void
    {
        $doctor = Doctor::factory()->create();

        $this->patchJson("/api/v1/doctors/{$doctor->id}/status", [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertUnauthorized();

        $this->assertDoctorUnchanged($doctor);
    }

    public function test_missing_laboratory_context_is_rejected_before_mutation(): void
    {
        $doctor = Doctor::factory()->create();

        $this->actingAs(User::factory()->create(), 'web')
            ->patchJson("/api/v1/doctors/{$doctor->id}/status", [
                'status' => Doctor::STATUS_INACTIVE,
            ])
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

        $this->statusRequest($user, $laboratory, $doctor->id, [
            'status' => Doctor::STATUS_INACTIVE,
        ])
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

        $this->statusRequest($user, $laboratory, $doctor->id, [
            'status' => Doctor::STATUS_INACTIVE,
        ])
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

        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'doctors.view', 'doctors.update', 'doctors.change_status',
        ]);

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
    private function statusRequest(
        User $user,
        Laboratory $laboratory,
        int|string $doctor,
        array $payload,
    ): TestResponse {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'doctors.change_status');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/doctors/{$doctor}/status", $payload);
    }
}
