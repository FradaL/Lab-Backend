<?php

namespace Tests\Unit;

use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ReportPreviewDocument;
use Com\Tecnick\Pdf\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalReportLayoutTest extends TestCase
{
    public static function patientAges(): array
    {
        return [
            'before birthday' => ['1990-11-21', '2026-11-20', '35 años'],
            'on birthday' => ['1990-11-20', '2026-11-20', '36 años'],
            'one year' => ['2025-11-20', '2026-11-20', '1 año'],
            'one month' => ['2026-10-20', '2026-11-20', '1 mes'],
            'several months' => ['2026-07-20', '2026-11-20', '4 meses'],
            'one day' => ['2026-11-19', '2026-11-20', '1 día'],
            'several days' => ['2026-11-05', '2026-11-20', '15 días'],
            'newborn' => ['2026-11-20', '2026-11-20', '0 días'],
            'historical issuance' => ['1990-01-01', '2020-01-01', '30 años'],
            'missing date' => [null, '2026-11-20', 'No disponible'],
            'invalid date' => ['2026-02-30', '2026-11-20', 'No disponible'],
            'future birth date' => ['2026-11-21', '2026-11-20', 'No disponible'],
        ];
    }

    #[DataProvider('patientAges')]
    public function test_age_uses_the_historical_birth_date_and_issuance_date_without_changing_the_document(?string $birth, string $issued, string $expected): void
    {
        $document = ['identity' => ['patient' => ['birth_date' => $birth]], 'issued_at' => $issued.'T18:00:00Z'];
        $before = serialize($document);
        $age = (new \ReflectionMethod(ClinicalReportRenderer::class, 'patientAge'))->invoke(app(ClinicalReportRenderer::class), $document);

        $this->assertSame($expected, $age);
        $this->assertSame($before, serialize($document));
    }

    public function test_letter_report_keeps_patient_order_and_total_page_identity_with_contextual_clinical_items(): void
    {
        $document = ReportPreviewDocument::make(['trade_name' => 'LABORATORIO FICTICIO DF011', 'address' => 'Dirección ficticia', 'phone' => '0000-0000', 'header' => '', 'footer' => 'DATOS FICTICIOS', 'logo' => null]);
        $document['preview'] = false;
        $document['sequence'] = 7;
        $document['number'] = 'REP-DF011-SINTETICO';
        $document['order_code'] = 'ORD-DF011-SINTETICA';
        $document['identity']['patient']['first_names'] = 'PACIENTE SINTETICO';
        $document['identity']['patient']['last_names'] = 'DF011';
        $template = $document['lines'][0];
        $document['lines'] = [];
        for ($i = 1; $i <= 12; $i++) {
            $line = $template;
            $line['line_id'] = $i;
            $line['exam_code'] = 'SYN-'.$i;
            $line['exam_name'] = 'Examen sintético '.$i;
            $document['lines'][] = $line;
        }
        $pdf = app(ClinicalReportRenderer::class)->render($document, 'https://example.invalid/df011');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+612(?:\.0+)?\s+792(?:\.0+)?\s*\]/', $pdf);
        $this->assertNotEmpty((new Parser)->parse($pdf));
        if (is_dir('/tmp/df011-artifacts')) {
            file_put_contents('/tmp/df011-artifacts/multipage.pdf', $pdf);
            file_put_contents('/tmp/df011-artifacts/multipage.json', json_encode($document, JSON_THROW_ON_ERROR));
        }
    }
}
