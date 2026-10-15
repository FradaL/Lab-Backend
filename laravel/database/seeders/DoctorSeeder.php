<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Laboratory;
use Illuminate\Database\Seeder;

class DoctorSeeder extends Seeder
{
    /**
     * Seed the demo laboratory doctors.
     */
    public function run(): void
    {
        DemoDataset::run(function (): void {
            $laboratory = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);

            foreach (range(1, 20) as $doctorNumber) {
                $licenseNumber = sprintf('DEMO-MED-%04d', $doctorNumber);
                $attributes = Doctor::factory()->make([
                    'laboratory_id' => $laboratory->id,
                    'license_number' => $licenseNumber,
                ])->getAttributes();

                DemoDataset::create(Doctor::class,
                    [
                        'laboratory_id' => $laboratory->id,
                        'license_number' => $licenseNumber,
                    ],
                    $attributes,
                );
            }
        });
    }
}
