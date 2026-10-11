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
                LaboratoryAreaSeeder::class,
                SampleTypeSeeder::class,
                LaboratoryExamSeeder::class,
                ClinicalExamSeeder::class,
                PatientSeeder::class,
                DoctorSeeder::class,
                PriceListSeeder::class,
                CommercialClientSeeder::class,
                LaboratoryOrderSeeder::class,
                LaboratorySampleSeeder::class,
                LaboratoryResultSeeder::class,
            ]);
        });
    }
}
