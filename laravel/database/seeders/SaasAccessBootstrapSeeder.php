<?php

namespace Database\Seeders;

use App\UserAdministration\SaasAccessManager;
use Illuminate\Database\Seeder;

final class SaasAccessBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        app(SaasAccessManager::class)->bootstrap();
    }
}
