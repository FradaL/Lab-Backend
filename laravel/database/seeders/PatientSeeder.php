<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\Patient;
use Illuminate\Database\Seeder;

class PatientSeeder extends Seeder
{
    /**
     * Seed the demo laboratory patients.
     */
    public function run(): void
    {
        DemoDataset::run(function (): void {
            $laboratory = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);

            foreach (range(1, 20) as $patientNumber) {
                $affiliationNumber = sprintf('DEMO-%04d', $patientNumber);
                $attributes = Patient::factory()->make([
                    'laboratory_id' => $laboratory->id,
                    'affiliation_number' => $affiliationNumber,
                ])->getAttributes();

                DemoDataset::create(Patient::class,
                    [
                        'laboratory_id' => $laboratory->id,
                        'affiliation_number' => $affiliationNumber,
                    ],
                    $attributes,
                );
            }
        });
    }
}
