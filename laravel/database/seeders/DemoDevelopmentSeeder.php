<?php

namespace Database\Seeders;

use App\Authorization\SaasAccessAuthority;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Explicit opt-in, deliberately excluded from DatabaseSeeder. */
final class DemoDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoClinicalReasonProvisioner::class)->database();
        DB::transaction(function (): void {
            app(SaasAccessAuthority::class)->lock();
            $this->call([DemoClinicalReasonsSeeder::class, DemoSaasUsersSeeder::class, DemoLaboratorySeeder::class]);
        });
    }
}
