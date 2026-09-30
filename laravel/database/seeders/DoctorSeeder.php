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
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        foreach (range(1, 20) as $doctorNumber) {
            $licenseNumber = sprintf('DEMO-MED-%04d', $doctorNumber);
            $attributes = Doctor::factory()->make([
                'laboratory_id' => $laboratory->id,
                'license_number' => $licenseNumber,
            ])->getAttributes();

            Doctor::query()->firstOrCreate(
                [
                    'laboratory_id' => $laboratory->id,
                    'license_number' => $licenseNumber,
                ],
                $attributes,
            );
        }
    }
}
