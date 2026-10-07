<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\SampleType;
use Illuminate\Database\Seeder;

class SampleTypeSeeder extends Seeder
{
    /**
     * Seed the sample types required by the demo examination catalog.
     */
    public function run(): void
    {
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        foreach (['Sangre', 'Orina', 'Heces', 'Hisopado nasofaríngeo'] as $name) {
            SampleType::query()->updateOrCreate(
                [
                    'laboratory_id' => $laboratory->id,
                    'name' => $name,
                ],
                ['status' => SampleType::STATUS_ACTIVE],
            );
        }
    }
}
