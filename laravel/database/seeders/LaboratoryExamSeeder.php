<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\SampleType;
use Illuminate\Database\Seeder;
use LogicException;

class LaboratoryExamSeeder extends Seeder
{
    /**
     * Seed a deterministic clinical catalog for the demo laboratory.
     */
    public function run(): void
    {
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        $areas = LaboratoryArea::query()
            ->where('laboratory_id', $laboratory->id)
            ->pluck('id', 'code');
        $sampleTypes = SampleType::query()
            ->where('laboratory_id', $laboratory->id)
            ->pluck('id', 'name');

        $exams = [
            ['code' => 'HEM001', 'name' => 'Hemograma completo', 'area' => 'HEM', 'sample_type' => 'Sangre', 'minutes' => 60],
            ['code' => 'QUI001', 'name' => 'Glucosa', 'area' => 'QUI', 'sample_type' => 'Sangre', 'minutes' => 45],
            ['code' => 'QUI002', 'name' => 'Perfil lipídico', 'area' => 'QUI', 'sample_type' => 'Sangre', 'minutes' => 120],
            ['code' => 'QUI003', 'name' => 'Creatinina', 'area' => 'QUI', 'sample_type' => 'Sangre', 'minutes' => 60],
            ['code' => 'URO001', 'name' => 'Examen general de orina', 'area' => 'URO', 'sample_type' => 'Orina', 'minutes' => 60],
            ['code' => 'COP001', 'name' => 'Examen general de heces', 'area' => 'COP', 'sample_type' => 'Heces', 'minutes' => 90],
            ['code' => 'COA001', 'name' => 'Tiempo de protrombina', 'area' => 'COA', 'sample_type' => 'Sangre', 'minutes' => 90],
            ['code' => 'INM001', 'name' => 'Proteína C reactiva', 'area' => 'INM', 'sample_type' => 'Sangre', 'minutes' => 120],
            ['code' => 'MIC001', 'name' => 'Urocultivo', 'area' => 'MIC', 'sample_type' => 'Orina', 'minutes' => 2880],
            ['code' => 'BIO001', 'name' => 'PCR SARS-CoV-2', 'area' => 'BIO', 'sample_type' => 'Hisopado nasofaríngeo', 'minutes' => 1440],
        ];

        foreach ($exams as $exam) {
            LaboratoryExam::query()->updateOrCreate(
                [
                    'laboratory_id' => $laboratory->id,
                    'code' => $exam['code'],
                ],
                [
                    'laboratory_area_id' => $areas->get($exam['area'])
                        ?? throw new LogicException("Missing demo laboratory area [{$exam['area']}]."),
                    'sample_type_id' => $sampleTypes->get($exam['sample_type'])
                        ?? throw new LogicException("Missing demo sample type [{$exam['sample_type']}]."),
                    'name' => $exam['name'],
                    'description' => null,
                    'turnaround_time_minutes' => $exam['minutes'],
                    'status' => LaboratoryExam::STATUS_ACTIVE,
                ],
            );
        }
    }
}
