<?php

namespace Tests\Feature\Models;

use App\Models\Laboratory;
use App\Models\Patient;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PatientPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_be_persisted_for_a_laboratory_with_expected_casts(): void
    {
        $laboratory = Laboratory::factory()->create();

        $patient = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Ana Maria',
            'last_names' => 'Lopez Perez',
            'birth_date' => '1990-05-17',
            'weight' => '70.50',
            'height' => '165.00',
        ])->fresh();

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'laboratory_id' => $laboratory->id,
            'first_names' => 'Ana Maria',
            'last_names' => 'Lopez Perez',
        ]);
        $this->assertTrue($patient->laboratory->is($laboratory));
        $this->assertInstanceOf(Carbon::class, $patient->birth_date);
        $this->assertSame('1990-05-17', $patient->birth_date->toDateString());
        $this->assertSame('70.50', $patient->weight);
        $this->assertSame('165.00', $patient->height);
    }

    public function test_laboratory_can_obtain_its_patients(): void
    {
        $laboratory = Laboratory::factory()->create();
        $patients = Patient::factory()->count(2)->for($laboratory)->create();

        $this->assertCount(2, $laboratory->patients);
        $this->assertTrue($patients->every(
            fn (Patient $patient): bool => $laboratory->patients->contains($patient)
        ));
    }

    public function test_database_defaults_status_to_active_and_all_optional_fields_accept_null(): void
    {
        $patient = Patient::query()->create([
            'laboratory_id' => Laboratory::factory()->create()->id,
            'first_names' => 'Default',
            'last_names' => 'Patient',
        ])->fresh();

        $this->assertSame(Patient::STATUS_ACTIVE, $patient->status);

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

    public function test_patient_cannot_reference_a_nonexistent_laboratory(): void
    {
        $this->expectException(QueryException::class);
        Patient::factory()->create(['laboratory_id' => 999999]);
    }

    public function test_laboratory_with_patients_cannot_be_deleted(): void
    {
        $laboratory = Laboratory::factory()->create();
        Patient::factory()->for($laboratory)->create();

        $this->expectException(QueryException::class);
        $laboratory->delete();
    }

    public function test_explicit_scope_isolates_patients_by_laboratory_without_implicit_filtering(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();

        $patientsA = Patient::factory()->count(2)->for($labA)->create();
        $patientsB = Patient::factory()->count(2)->for($labB)->create();

        $this->assertCount(4, Patient::query()->get());
        $this->assertEqualsCanonicalizing(
            $patientsA->modelKeys(),
            Patient::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            $patientsB->modelKeys(),
            Patient::forLaboratory($labB)->pluck('id')->all(),
        );
    }

    public function test_explicit_scope_does_not_require_a_current_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertTrue(
            Patient::forLaboratory($laboratory)->firstOrFail()->is($patient)
        );
        $this->assertFalse($currentLaboratory->has());
    }
}
