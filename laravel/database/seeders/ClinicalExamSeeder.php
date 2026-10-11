<?php

namespace Database\Seeders;

use App\ClinicalExams\ClinicalExamService;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryExamVersion;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Database\Seeder;

final class ClinicalExamSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::run(function (): void {
            $lab = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);
            $actor = DemoDataset::owned(User::class, ['email' => 'admin@donqerlab.test']);
            $service = app(ClinicalExamService::class);
            foreach (['HEM001', 'QUI001', 'QUI002', 'QUI003', 'URO001', 'COP001', 'COA001', 'INM001', 'MIC001', 'BIO001'] as $code) {
                $exam = DemoDataset::owned(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => $code]);
                DemoDataset::remember('clinical_publication', $code, fn ($id) => LaboratoryExamVersion::where('laboratory_id', $lab->id)->where('laboratory_exam_id', $exam->id)->where('status', 'published')->whereKey($id)->first(), function () use ($lab, $actor, $service, $exam) {
                    $sample = SampleType::findOrFail($exam->sample_type_id);
                    DemoDataset::assertOwned($sample);
                    $draft = $service->fork($lab, $actor, $exam->id, null);
                    $draft = $service->update($lab, $actor, $exam->id, $draft->id, $draft->revision, [
                        'schema_version' => 1, 'code' => $exam->code, 'name' => $exam->name,
                        'description' => DemoDataset::DISCLAIMER,
                        'sample_mode' => 'physical',
                        'sample_requirements' => [['key' => 'main', 'sample_type_id' => $sample->id, 'instructions' => DemoDataset::DISCLAIMER]],
                        'parameters' => [['code' => 'DEMO_OBS', 'name' => 'Observación ficticia de interfaz', 'type' => 'text']],
                    ]);

                    return $service->publish($lab, $actor, $exam->id, $draft->id, $draft->revision);
                });
            }
            DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'DEMO-NONE'], factory: function () use ($lab, $actor, $service) {
                $area = DemoDataset::owned(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'HEM']);
                $draft = $service->create($lab, $actor, [
                    'code' => 'DEMO-NONE', 'name' => 'DEMO — Observación sin muestra física', 'laboratory_area_id' => $area->id,
                    'sample_type_id' => null, 'configuration' => [
                        'description' => DemoDataset::DISCLAIMER, 'sample_mode' => 'none',
                        'parameters' => [['code' => 'DEMO_OBS', 'name' => 'Observación ficticia de interfaz', 'type' => 'text']],
                    ],
                ]);
                $service->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, $draft->revision);
                $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
                $exam->update(['status' => 'active']);

                return $exam;
            });
        });
    }
}
