<?php

namespace Tests\Feature\Models;

use App\Models\Doctor;
use App\Models\Laboratory;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DoctorPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_be_persisted_for_a_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();

        $doctor = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan Carlos',
            'last_names' => 'Pérez López',
            'specialty' => 'Cardiología',
            'phone' => '+502 5555-5555 ext. 12',
            'email' => 'juan.perez@example.test',
            'license_number' => 'MED-123456',
            'status' => Doctor::STATUS_INACTIVE,
            'notes' => 'Atiende únicamente con cita previa.',
        ])->fresh();

        $this->assertDatabaseHas('doctors', [
            'id' => $doctor->id,
            'laboratory_id' => $laboratory->id,
            'first_names' => 'Juan Carlos',
            'last_names' => 'Pérez López',
            'specialty' => 'Cardiología',
            'phone' => '+502 5555-5555 ext. 12',
            'email' => 'juan.perez@example.test',
            'license_number' => 'MED-123456',
            'status' => Doctor::STATUS_INACTIVE,
            'notes' => 'Atiende únicamente con cita previa.',
        ]);
        $this->assertTrue($doctor->laboratory->is($laboratory));
    }

    public function test_laboratory_can_obtain_its_doctors(): void
    {
        $laboratory = Laboratory::factory()->create();
        $doctors = Doctor::factory()->count(2)->for($laboratory)->create();

        $this->assertCount(2, $laboratory->doctors);
        $this->assertTrue($doctors->every(
            fn (Doctor $doctor): bool => $laboratory->doctors->contains($doctor)
        ));
    }

    public function test_database_defaults_status_to_active_and_optional_fields_accept_null(): void
    {
        $doctor = Doctor::query()->create([
            'laboratory_id' => Laboratory::factory()->create()->id,
            'first_names' => 'Default',
            'last_names' => 'Doctor',
        ])->fresh();

        $this->assertSame(Doctor::STATUS_ACTIVE, $doctor->status);

        foreach (['specialty', 'phone', 'email', 'license_number', 'notes'] as $attribute) {
            $this->assertNull($doctor->getAttribute($attribute));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('missingRequiredAttributeProvider')]
    public function test_database_rejects_null_required_fields(array $attributes): void
    {
        $this->expectException(QueryException::class);

        Doctor::query()->create(array_merge([
            'laboratory_id' => Laboratory::factory()->create()->id,
            'first_names' => 'Required',
            'last_names' => 'Doctor',
            'status' => Doctor::STATUS_ACTIVE,
        ], $attributes));
    }

    /**
     * @return array<string, array{array<string, null>}>
     */
    public static function missingRequiredAttributeProvider(): array
    {
        return [
            'laboratory_id' => [['laboratory_id' => null]],
            'first_names' => [['first_names' => null]],
            'last_names' => [['last_names' => null]],
            'status' => [['status' => null]],
        ];
    }

    public function test_doctor_cannot_reference_a_nonexistent_laboratory(): void
    {
        $this->expectException(QueryException::class);
        Doctor::factory()->create(['laboratory_id' => 999999]);
    }

    public function test_laboratory_with_doctors_cannot_be_deleted(): void
    {
        $laboratory = Laboratory::factory()->create();
        Doctor::factory()->for($laboratory)->create();

        $this->expectException(QueryException::class);
        $laboratory->delete();
    }

    public function test_explicit_scope_isolates_doctors_by_laboratory_without_implicit_filtering(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();

        $doctorsA = Doctor::factory()->count(2)->for($labA)->create();
        $doctorB = Doctor::factory()->for($labB)->create();

        $this->assertCount(3, Doctor::query()->get());
        $this->assertEqualsCanonicalizing(
            $doctorsA->modelKeys(),
            Doctor::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertNotContains(
            $doctorB->id,
            Doctor::forLaboratory($labA)->pluck('id')->all(),
        );
    }

    public function test_explicit_scope_does_not_require_a_current_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        $doctor = Doctor::factory()->for($laboratory)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertTrue(
            Doctor::forLaboratory($laboratory)->firstOrFail()->is($doctor)
        );
        $this->assertFalse($currentLaboratory->has());
    }

    public function test_duplicate_license_number_and_email_are_allowed_within_and_across_laboratories(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();
        $duplicateAttributes = [
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'license_number' => 'MED-123',
            'email' => 'juan.perez@example.test',
        ];

        Doctor::factory()->for($labA)->count(2)->create($duplicateAttributes);
        Doctor::factory()->for($labB)->create($duplicateAttributes);

        $this->assertSame(3, Doctor::query()
            ->where('license_number', 'MED-123')
            ->where('email', 'juan.perez@example.test')
            ->count());
    }
}
