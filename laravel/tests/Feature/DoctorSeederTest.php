<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Laboratory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_doctors_are_seeded_idempotently_for_the_demo_laboratory(): void
    {
        $this->seed();
        $this->seed();

        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        $this->assertDatabaseCount('doctors', 20);
        $this->assertSame(20, Doctor::query()->whereBelongsTo($laboratory)->count());
        $this->assertSame(
            20,
            Doctor::query()
                ->whereBelongsTo($laboratory)
                ->distinct('license_number')
                ->count('license_number'),
        );
        $this->assertDatabaseHas('doctors', [
            'laboratory_id' => $laboratory->id,
            'license_number' => 'DEMO-MED-0001',
        ]);
        $this->assertDatabaseHas('doctors', [
            'laboratory_id' => $laboratory->id,
            'license_number' => 'DEMO-MED-0020',
        ]);
    }
}
