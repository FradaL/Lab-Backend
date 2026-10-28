<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DemoClinicalReasonsSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoClinicalReasonProvisioner::class)->provision();
    }
}
