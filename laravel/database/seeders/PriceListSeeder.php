<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class PriceListSeeder extends Seeder
{
    /**
     * Seed deterministic demo price lists and their examination prices.
     */
    public function run(): void
    {
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        $definitions = [
            'Tarifa Particular' => [
                'description' => 'Tarifa predeterminada para pacientes particulares.',
                'is_default' => true,
                'prices' => [
                    'HEM001' => '95.00',
                    'QUI001' => '35.00',
                    'QUI002' => '150.00',
                    'QUI003' => '45.00',
                    'URO001' => '65.00',
                    'COP001' => '70.00',
                    'COA001' => '80.00',
                    'INM001' => '110.00',
                    'MIC001' => '220.00',
                    'BIO001' => '350.00',
                ],
            ],
            'Tarifa Convenios' => [
                'description' => 'Tarifa para empresas y convenios institucionales.',
                'is_default' => false,
                'prices' => [
                    'HEM001' => '85.00',
                    'QUI001' => '30.00',
                    'QUI002' => '135.00',
                    'QUI003' => '40.00',
                    'URO001' => '58.00',
                    'COP001' => '62.00',
                    'COA001' => '72.00',
                    'INM001' => '98.00',
                    'MIC001' => '200.00',
                    'BIO001' => '315.00',
                ],
            ],
            'Tarifa Aseguradoras' => [
                'description' => 'Tarifa negociada para aseguradoras.',
                'is_default' => false,
                'prices' => [
                    'HEM001' => '80.00',
                    'QUI001' => '28.00',
                    'QUI002' => '128.00',
                    'QUI003' => '38.00',
                    'URO001' => '55.00',
                    'COP001' => '59.00',
                    'COA001' => '68.00',
                    'INM001' => '92.00',
                    'MIC001' => '190.00',
                    'BIO001' => '300.00',
                ],
            ],
        ];

        $exams = LaboratoryExam::query()
            ->where('laboratory_id', $laboratory->id)
            ->whereIn('code', array_keys($definitions['Tarifa Particular']['prices']))
            ->get()
            ->keyBy('code');

        DB::transaction(function () use ($definitions, $exams, $laboratory): void {
            PriceList::query()
                ->where('laboratory_id', $laboratory->id)
                ->where('is_default', true)
                ->where('name', '!=', 'Tarifa Particular')
                ->update(['is_default' => false]);

            foreach ($definitions as $name => $definition) {
                $priceList = PriceList::query()->updateOrCreate(
                    [
                        'laboratory_id' => $laboratory->id,
                        'name' => $name,
                    ],
                    [
                        'description' => $definition['description'],
                        'currency' => 'GTQ',
                        'is_default' => $definition['is_default'],
                        'status' => PriceList::STATUS_ACTIVE,
                    ],
                );

                foreach ($definition['prices'] as $examCode => $price) {
                    $exam = $exams->get($examCode)
                        ?? throw new LogicException("Missing demo laboratory exam [{$examCode}].");

                    PriceListExam::query()->updateOrCreate(
                        [
                            'laboratory_id' => $laboratory->id,
                            'price_list_id' => $priceList->id,
                            'laboratory_exam_id' => $exam->id,
                        ],
                        [
                            'price' => $price,
                            'status' => PriceListExam::STATUS_ACTIVE,
                        ],
                    );
                }
            }
        });
    }
}
