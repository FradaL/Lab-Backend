<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::run(function (): void {
            $this->call([
                UserSeeder::class,
                SaasFoundationSeeder::class,
                DemoPermissionSeeder::class,
                DoctorSeeder::class,
                PatientSeeder::class,
                LaboratoryAreaSeeder::class,
                SampleTypeSeeder::class,
            ]);
        });
    }
}
