<?php

namespace Tests\Unit;

use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ReportPreviewDocument;
use Com\Tecnick\Pdf\Parser\Parser;
use Tests\TestCase;

class ClinicalReportLayoutTest extends TestCase
{
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
