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
        DemoDataset::run(function (): void {
            $laboratory = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);

            foreach (['Sangre', 'Orina', 'Heces', 'Hisopado nasofaríngeo'] as $name) {
                DemoDataset::create(SampleType::class,
                    [
                        'laboratory_id' => $laboratory->id,
                        'name' => $name,
                    ],
                    ['status' => SampleType::STATUS_ACTIVE],
                );
            }
        });
    }
}
