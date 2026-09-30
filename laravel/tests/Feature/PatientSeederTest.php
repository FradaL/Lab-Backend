<?php

namespace Tests\Feature;

use App\Models\Laboratory;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_patients_are_seeded_idempotently_for_the_demo_laboratory(): void
    {
        $this->seed();
        $this->seed();

        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        $this->assertDatabaseCount('patients', 20);
        $this->assertSame(20, Patient::query()->whereBelongsTo($laboratory)->count());
        $this->assertSame(
            20,
            Patient::query()
                ->whereBelongsTo($laboratory)
                ->distinct('affiliation_number')
                ->count('affiliation_number'),
        );
        $this->assertDatabaseHas('patients', [
            'laboratory_id' => $laboratory->id,
            'affiliation_number' => 'DEMO-0001',
        ]);
        $this->assertDatabaseHas('patients', [
            'laboratory_id' => $laboratory->id,
            'affiliation_number' => 'DEMO-0020',
        ]);
    }
}
