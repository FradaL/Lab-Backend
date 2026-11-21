<?php

namespace Tests\Unit;

use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ReportPreviewDocument;
use Com\Tecnick\Pdf\Parser\Parser;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalReportTableTest extends TestCase
{
    public static function signaturePageCases(): array
    {
        return ['hematology-sized exam' => [15, 1], 'signatures fit near the bottom' => [17, 1], 'handwriting space needs another page' => [20, 2], 'signatures need another page' => [22, 2]];
    }

    #[DataProvider('signaturePageCases')]
    public function test_signatures_use_the_available_space_without_forcing_an_extra_page(int $count, int $expectedPages): void
    {
        $document = ReportPreviewDocument::make(['trade_name' => 'LABORATORIO SINTÉTICO', 'address' => 'Dirección ficticia', 'phone' => '0000', 'header' => '', 'footer' => 'DATOS FICTICIOS', 'logo' => null]);
        $document['preview'] = false;
        $validation = &$document['lines'][0]['validation'];
        unset($validation['professional_snapshot']);
        $validation['context_snapshot']['validator_identity'] = ['name' => 'Administrador sintético'];
        $result = $validation['results_snapshot'][0];
        $reference = $validation['reference_snapshot'][0];
        $validation['results_snapshot'] = [];
        $validation['reference_snapshot'] = [];
        for ($i = 1; $i <= $count; $i++) {
            $result['parameter_code'] = 'P'.$i;
            $result['parameter_snapshot']['name'] = 'Parámetro sintético '.$i;
            $reference['parameter_code'] = 'P'.$i;
            $validation['results_snapshot'][] = $result;
            $validation['reference_snapshot'][] = $reference;
        }
        $before = serialize($document);
        $pdf = app(ClinicalReportRenderer::class)->render($document, 'https://example.invalid/signature-fit');

        $this->assertSame($before, serialize($document));
        $this->assertSame($expectedPages, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        if (is_dir('/tmp/df011-artifacts')) {
            file_put_contents('/tmp/df011-artifacts/signature-fit-'.$count.'.pdf', $pdf);
        }
    }

    public static function examples(): array
    {
        return array_map(fn ($name) => [$name], ['short', 'mixed', 'result-style', 'long-text', 'long-identity', 'long-table', 'areas', 'same-area', 'observations', 'partial', 'styled', 'blue', 'burgundy', 'responsibles', 'no-professional', 'legacy-exception']);
    }

    #[DataProvider('examples')]
    public function test_four_column_pdf_uses_exact_snapshots_without_mutating_the_document(string $example): void
    {
        $document = $this->example($example);
        $before = serialize($document);
        $pdf = app(ClinicalReportRenderer::class)->render($document, 'https://example.invalid/synthetic-table');
        $this->assertSame($before, serialize($document));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertNotEmpty((new Parser)->parse($pdf));
        if (is_dir('/tmp/df011-artifacts')) {
            file_put_contents('/tmp/df011-artifacts/table-'.$example.'.pdf', $pdf);
            file_put_contents('/tmp/df011-artifacts/table-'.$example.'.json', json_encode($document, JSON_THROW_ON_ERROR));
        }
    }

    public function test_parameter_and_reference_cells_hide_technical_keys_without_mutating_the_snapshots(): void
    {
        $result = [
            'parameter_snapshot' => ['name' => 'Hemoglobina'],
            'parameter_code' => 'P_e8472ff34dfa4fa18974d944b0442058',
            'value' => '14.2',
            'option_label' => null,
            'unit' => 'g/dL',
        ];
        $reference = ['status' => 'resolved', 'reference' => ['min' => '11', 'max' => '18'], 'reference_key' => 'imagen_ejemplo_pendiente_revision'];
        $validation = ['context_snapshot' => ['classifications' => []]];
        $before = serialize([$result, $reference]);
        $method = new \ReflectionMethod(ClinicalReportRenderer::class, 'resultCells');

        $cells = $method->invoke(app(ClinicalReportRenderer::class), $result, $reference, $validation);

        $this->assertSame('Hemoglobina', $cells[0]);
        $this->assertSame('Mínimo 11 | Máximo 18', $cells[3]);
        $this->assertStringNotContainsString('imagen_ejemplo_pendiente_revision', implode(' ', $cells));
        $this->assertSame($before, serialize([$result, $reference]));
    }

    public static function resultClassifications(): array
    {
        return [
            'normal' => ['normal', '15', false],
            'low' => ['low', '15 *', true],
            'high' => ['high', '15 *', true],
            'critical' => ['critical', '15 *', true],
            'outside reference' => ['outside_reference', '15 *', true],
            'unclassified' => ['not_classified', '15', false],
            'missing classification' => [null, '15', false],
        ];
    }

    #[DataProvider('resultClassifications')]
    public function test_result_marker_uses_the_saved_classification_without_reinterpreting_the_value(?string $status, string $expected, bool $abnormal): void
    {
        $result = ['parameter_snapshot' => ['name' => 'Hemoglobina'], 'parameter_code' => 'HGB', 'value' => '15', 'option_label' => null, 'unit' => 'g/dL'];
        $reference = ['status' => 'resolved', 'reference' => ['min' => '11', 'max' => '18'], 'reference_key' => 'snapshot'];
        $validation = ['context_snapshot' => ['classifications' => [
            ['parameter_code' => 'OTHER', 'status' => 'high'],
            ['parameter_code' => 'HGB', 'status' => $status],
        ]]];
        $before = serialize([$result, $reference, $validation]);
        $renderer = app(ClinicalReportRenderer::class);
        $cells = (new \ReflectionMethod($renderer, 'resultCells'))->invoke($renderer, $result, $reference, $validation);

        $this->assertSame($expected, $cells[1]);
        $this->assertSame($abnormal, (new \ReflectionMethod($renderer, 'isAbnormalResult'))->invoke($renderer, $result, $validation));
        $this->assertSame($before, serialize([$result, $reference, $validation]));
    }

    private function example(string $example): array
    {
        $document = ReportPreviewDocument::make(['trade_name' => 'LABORATORIO SINTÉTICO', 'address' => 'Dirección ficticia', 'phone' => '0000', 'header' => '', 'footer' => 'DATOS FICTICIOS', 'logo' => null]);
        $document['preview'] = false;
        $document['number'] = 'REP-TABLA-SINTETICA';
        $line = &$document['lines'][0];
        $line['validation']['validated_at'] = '2026-11-02T04:49:51.944525+00:00';
        $line['validation']['context_snapshot']['reference_date'] = '2026-11-02';
        $line['area'] = ['id' => 1, 'name' => 'Área sintética A'];
        $line['validation']['results_snapshot'] = [];
        $line['validation']['reference_snapshot'] = [];
        $line['validation']['context_snapshot']['classifications'] = [];
        $count = match ($example) {
            'short', 'long-text' => 1,
            'long-table' => 65,
            default => 6,
        };
        for ($index = 1; $index <= $count; $index++) {
            $code = 'P'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $name = 'Determinación sintética '.$index;
            $value = '12345678901234567890.123456789';
            $unit = 'µmol/L';
            $option = null;
            $reference = ['min' => '0.001', 'max' => '999.999'];
            $status = 'resolved';
            $classification = ['high', 'low', 'critical', 'outside_reference', 'not_classified', null][($index - 1) % 6];
            if ($example !== 'short' && $index === 2) {
                $value = 'YES';
                $option = 'Reactivo sintético';
                $unit = null;
                $reference = ['allowed_options' => ['NO']];
            } elseif ($example !== 'short' && $index === 3) {
                $value = "Texto clínico ficticio: áéíóú ñ ≤ ≥ ↑ ↓ β.\nSegunda línea conservada.";
                $unit = null;
                $reference = null;
                $status = 'not_configured';
            } elseif ($example !== 'short' && $index === 4) {
                $value = 'Opción A';
                $unit = null;
                $reference = null;
                $status = 'not_applicable';
            } elseif ($example !== 'short' && $index === 5) {
                $value = 'Indeterminado sintético';
                $reference = ['text' => 'Referencia textual exclusivamente ficticia'];
            }
            if ($example === 'long-text') {
                $value = 'INICIO_EXTENSO '.str_repeat('Texto sintético extenso sin interpretación clínica. ', 420).' FIN_EXTENSO';
                $name = str_repeat('Determinación extensa ', 6);
                $unit = 'µmol/L · 10⁹/L';
                $reference = ['text' => str_repeat('Referencia sintética extensa. ', 45).' FIN_REFERENCIA'];
            }
            if ($example === 'result-style') {
                [$name, $value, $unit, $classification, $bounds] = [
                    ['Hemoglobina', '15', 'g/dL', 'normal', ['11', '18']],
                    ['Hematocrito', '25', '%', 'low', ['35', '60']],
                    ['Glóbulos blancos', '12000', '/mm³', 'high', ['5000', '10000']],
                    ['Plaquetas', '20000', '/mm³', 'critical', ['150000', '450000']],
                    ['Neutrófilos segmentados', '20', '%', 'not_classified', null],
                    ['Linfocitos', '15', '%', null, null],
                ][$index - 1];
                $option = null;
                $reference = $bounds === null ? null : ['min' => $bounds[0], 'max' => $bounds[1]];
                $status = $reference === null ? 'not_configured' : 'resolved';
            }
            $line['validation']['results_snapshot'][] = ['parameter_code' => $code, 'parameter_snapshot' => ['name' => $name], 'value' => $value, 'unit' => $unit, 'option_label' => $option];
            $line['validation']['reference_snapshot'][] = ['parameter_code' => $code, 'status' => $status, 'reference_key' => $reference === null ? null : $code, 'reference' => $reference];
            if ($classification !== null) {
                $line['validation']['context_snapshot']['classifications'][] = ['parameter_code' => $code, 'status' => $classification];
            }
        }
        if ($example === 'long-identity') {
            $document['identity']['patient']['first_names'] = str_repeat('Paciente compuesto ', 8);
            $document['identity']['patient']['last_names'] = str_repeat('Apellido clínico ', 8);
            $document['identity']['branch']['name'] = str_repeat('Sucursal institucional ', 7);
            $document['identity']['branch']['code'] = 'SUCURSAL-CODIGO-INSTITUCIONAL-EXTENSO-001';
            $document['order_code'] = 'ORDEN-IDENTIFICADOR-CLINICO-MUY-EXTENSO-000000000000000001';
            $document['sequence'] = 123456789;
        }
        unset($line);
        if (in_array($example, ['areas', 'same-area', 'responsibles'])) {
            for ($index = 2; $index <= 4; $index++) {
                $line = $document['lines'][0];
                $line['line_id'] = $index;
                $line['exam_code'] = 'EXAM-'.$index;
                $line['exam_name'] = 'Examen sintético '.$index;
                $line['area'] = ['id' => ($index < 3 || $example === 'same-area') ? 1 : 2, 'name' => ($index < 3 || $example === 'same-area') ? 'Área sintética A' : 'Área sintética B'];
                $document['lines'][] = $line;
            }
            $document['presentation']['area_order'] = [2, 1];
            $document['presentation']['exam_order'] = [4, 3, 2, 1];
            $document['exam_id_by_line'] = [1 => 1, 2 => 2, 3 => 3, 4 => 4];
        }
        if ($example === 'responsibles') {
            foreach ([2, 3] as $index) {
                $document['lines'][$index]['validation']['validated_by'] = 2;
                $document['lines'][$index]['validation']['professional_snapshot']['professional_name'] = 'Segundo profesional ficticio';
            }
        }
        if ($example === 'no-professional') {
            unset($document['lines'][0]['validation']['professional_snapshot']);
        }
        if ($example === 'observations') {
            foreach (['parameter', 'exam', 'area', 'report'] as $scope) {
                $document['lines'][0]['validation']['context_snapshot']['observations'][] = [
                    'scope' => $scope, 'parameter_code' => 'P001', 'area' => ['id' => 1, 'name' => 'Área sintética A'],
                    'text' => 'OBSERVACION_SINTETICA_'.$scope, 'author_name' => 'Autor ficticio', 'line_id' => 1,
                    'authorized_at' => '2026-11-02T04:49:51.944525+00:00',
                ];
            }
            $exception = $document['lines'][0];
            $exception['line_id'] = 2;
            $exception['exam_code'] = 'NO-REALIZADO';
            $exception['exam_name'] = 'Examen no realizado ficticio';
            $exception['validation'] = null;
            $exception['clinical_display'] = ['label' => 'No realizado', 'reason' => 'Motivo ficticio conservado'];
            $document['lines'][] = $exception;
        }
        if (in_array($example, ['blue', 'burgundy'])) {
            $document['presentation']['color_theme'] = $example;
        }
        if ($example === 'legacy-exception') {
            $document['format_version'] = 'donqer-clinical-1';
            $line = $document['lines'][0];
            $line['line_id'] = 2;
            $line['exam_code'] = 'LEGACY-EXCEPTION';
            $line['validation'] = null;
            $line['exception'] = ['request' => ['reason' => 'Motivo histórico ficticio'],
                'decision' => ['id' => 2, 'decided_by' => 3, 'decided_at' => '2026-11-02T04:49:51.944525+00:00', 'reason' => null]];
            $document['lines'][] = $line;
        }
        if ($example === 'partial') {
            $document['type'] = 'partial';
            $document['pending_lines'] = [['line_id' => 2, 'exam_code' => 'PENDIENTE', 'exam_name' => 'Examen sin resultados', 'clinical_status' => 'incomplete', 'processing_started' => true]];
        }
        if ($example === 'styled') {
            $document['presentation']['color_theme'] = 'teal';
            $document['presentation']['header'] = "Encabezado institucional ficticio\nSegunda línea";
            $document['presentation']['footer'] = "DATOS FICTICIOS\nPie institucional de ejemplo";
            $logo = UploadedFile::fake()->image('synthetic-logo.png', 100, 40);
            $document['presentation']['logo'] = base64_encode($logo->get());
        }

        return $document;
    }
}
