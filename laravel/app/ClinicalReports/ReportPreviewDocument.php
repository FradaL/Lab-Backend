<?php

namespace App\ClinicalReports;

/** A PDF preview uses the same renderer and exclusively synthetic clinical data. */
final class ReportPreviewDocument
{
    public static function make(array $presentation): array
    {
        return ['preview' => true, 'number' => 'VISTA-PREVIA-FICTICIA', 'sequence' => 1, 'type' => 'complete',
            'issued_at' => '2026-01-01T12:00:00Z', 'order_code' => 'ORD-FICTICIA', 'presentation' => $presentation,
            'identity' => ['source' => 'order_creation', 'patient' => ['first_names' => 'Paciente', 'last_names' => 'Ficticio', 'affiliation_number' => 'EJEMPLO'],
                'branch' => ['name' => 'Sucursal ficticia', 'code' => 'DEMO']],
            'format_version' => 'donqer-clinical-2', 'pending_lines' => [], 'lines' => [[
                'line_id' => 1, 'area' => ['id' => 1, 'name' => 'Área de ejemplo'], 'exam_code' => 'DEMO', 'exam_name' => 'Examen ficticio',
                'validation' => ['id' => 1, 'approved_revision' => 1, 'validated_at' => '2026-01-01T12:00:00Z', 'validated_by' => 1,
                    'results_snapshot' => [['parameter_code' => 'DEMO', 'parameter_snapshot' => ['name' => 'Parámetro ficticio'], 'value' => '85', 'unit' => 'mg/dL', 'option_label' => null]],
                    'reference_snapshot' => [['parameter_code' => 'DEMO', 'status' => 'resolved', 'reference_key' => 'DEMO', 'reference' => ['min' => '70', 'max' => '100']]],
                    'context_snapshot' => ['reference_date' => '2026-01-01', 'age_days' => null, 'sex' => null, 'conditions' => [], 'classifications' => [['parameter_code' => 'DEMO', 'status' => 'normal']]],
                    'comment' => 'Datos ficticios para comprobar la presentación.',
                    'professional_snapshot' => ['professional_name' => 'Profesional ficticio', 'profession' => 'Profesión de ejemplo', 'registration_number' => 'DEMO']],
            ]]];
    }
}
