<?php

namespace Database\Seeders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\MasterDataAuditEvents;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalValidation\ValidationAuthority;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Database\Seeder;

final class HematologyExamSeeder extends Seeder
{
    public function run(): void
    {
        $previousTeam = getPermissionsTeamId();
        try {
            DemoDataset::run(function (): void {
                $lab = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);
                $area = DemoDataset::owned(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'HEM']);
                $sample = DemoDataset::owned(SampleType::class, ['laboratory_id' => $lab->id, 'name' => 'Sangre']);
                $actor = DemoDataset::owned(User::class, ['email' => 'admin@donqerlab.test']);
                setPermissionsTeamId($lab->id);

                DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'HEM001'], factory: function () use ($lab, $area, $sample, $actor): LaboratoryExam {
                    foreach (['laboratory_exams.create_draft', 'laboratory_exams.publish', 'laboratory_exams.change_status'] as $permission) {
                        app(ValidationAuthority::class)->access($lab, $actor, $permission);
                    }
                    $description = DemoDataset::DISCLAIMER.' Configuración basada en la imagen proporcionada por Daniel Lara, 2026-11-20. Rangos de ejemplo pendientes de revisión del laboratorio; la imagen no especifica edad, sexo ni método. No se inventan referencias para los parámetros que no muestran intervalo.';
                    $clinical = app(ClinicalExamService::class);
                    $draft = $clinical->create($lab, $actor, [
                        'code' => 'HEM001', 'name' => 'Hematología completa',
                        'laboratory_area_id' => $area->id, 'sample_type_id' => $sample->id,
                        'configuration' => [
                            'description' => $description, 'sample_mode' => 'physical',
                            'sample_requirements' => [['key' => 'sangre', 'sample_type_id' => $sample->id]],
                            'parameters' => $this->parameters(),
                        ],
                    ]);
                    $clinical->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, $draft->revision);
                    $exam = $clinical->exam($lab, $draft->laboratory_exam_id, true);
                    $exam->update(['status' => LaboratoryExam::STATUS_ACTIVE, 'description' => $description]);
                    app(AuditWriter::class)->record($lab, $actor, new AuditEvent(
                        MasterDataAuditEvents::LABORATORY_EXAM_STATUS_CHANGED,
                        MasterDataAuditEvents::SUBJECT_LABORATORY_EXAM, $exam->id,
                        oldValues: ['status' => LaboratoryExam::STATUS_INACTIVE],
                        newValues: ['status' => LaboratoryExam::STATUS_ACTIVE],
                        metadata: ['source' => self::class, 'reason' => DemoDataset::DISCLAIMER],
                    ));

                    return $exam;
                });
            });
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    private function parameters(): array
    {
        // Transcribed from the supplied image; no patient results or inferred intervals.
        $definitions = [
            ['HGB', 'Hemoglobina', 'g/dL', '11', '18'],
            ['HCT', 'Hematocrito', '%', '35', '60'],
            ['WBC', 'Glóbulos blancos', '/mm³', '5000', '10000'],
            ['NEUT', 'Neutrófilos segmentados', '%', null, null],
            ['LYMPH', 'Linfocitos', '%', null, null],
            ['MONO', 'Monocitos', '%', null, null],
            ['RBC', 'Recuento de glóbulos rojos', 'millones/mm³', null, null],
            ['PLT', 'Recuento de plaquetas', '/mm³', '150000', '450000'],
            ['MCV', 'MCV', 'fL', '80', '100'],
            ['MCH', 'MCH', 'pg', '27', '32'],
            ['MCHC', 'MCHC', 'g/dL', '32', '36'],
            ['RDW', 'RDW', '%', '11.5', '14.5'],
            ['MPV', 'MPV', 'fL', '6.0', '10.0'],
            ['PDW', 'PDW', 'fL', '10', '15'],
            ['PCT', 'PCT', '%', '0.10', '1.0'],
        ];

        return array_map(static function (array $definition): array {
            [$code, $name, $unit, $min, $max] = $definition;

            return [
                'code' => $code, 'name' => $name, 'type' => 'numeric',
                'unit_required' => true, 'unit' => $unit,
                'references' => $min === null ? [] : [[
                    'key' => 'imagen_ejemplo_pendiente_revision', 'priority' => 0,
                    'min' => $min, 'max' => $max,
                ]],
            ];
        }, $definitions);
    }
}
