<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            SaasFoundationSeeder::class,
            RolePermissionSeeder::class,
            LaboratoryAreaSeeder::class,
            SampleTypeSeeder::class,
            LaboratoryExamSeeder::class,
            PatientSeeder::class,
            DoctorSeeder::class,
            PriceListSeeder::class,
            CommercialClientSeeder::class,
            LaboratoryOrderSeeder::class,
        ]);
    }
}
