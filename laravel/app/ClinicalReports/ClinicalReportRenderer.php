<?php

namespace App\ClinicalReports;

use App\ClinicalExams\ClinicalConflict;
use Com\Tecnick\Pdf\Tcpdf;

class ClinicalReportRenderer
{
    /** Render only immutable server snapshots. No HTML, remote assets or signatures. */
    public function render(array $document, string $verificationUrl): string
    {
        $pdf = new Tcpdf(fileOptions: ['allowedHosts' => [], 'allowedPaths' => [resource_path('report-fonts')]]);
        $pdf->setCreator('Donqer Lab');
        $pdf->setTitle($document['number']);
        $pdf->setAuthor($document['presentation']['trade_name']);
        $font = $pdf->font->insert($pdf->pon, 'dejavusans', '', 9, ifile: resource_path('report-fonts/dejavusans.json'));
        $presentation = $document['presentation'];
        $wrap = function (string $value, float $width) use ($pdf): array {
            $rows = [];
            foreach (explode("\n", $value) as $paragraph) {
                $row = '';
                $used = 0.0;
                foreach (mb_str_split($paragraph) as $char) {
                    $code = mb_ord($char);
                    if (! $pdf->font->isCharDefined($code)) {
                        throw new ClinicalConflict('report_unsupported_character', 'The standard report font cannot represent all document characters.');
                    }
                    $charWidth = $pdf->toUnit($pdf->font->getCharWidth($code));
                    if ($row !== '' && $used + $charWidth > $width - 2) {
                        $rows[] = $row;
                        $row = '';
                        $used = 0;
                    }
                    $row .= $char;
                    $used += $charWidth;
                }
                $rows[] = $row === '' ? ' ' : $row;
            }

            return $rows;
        };
        $tradeNameRows = $wrap($presentation['trade_name'], 155);
        $footerRows = $wrap($presentation['footer'] ?? '', 175);
        if (count($tradeNameRows) > 8 || count($footerRows) > 20) {
            throw new ClinicalConflict('report_presentation_invalid', 'The saved presentation cannot fit the mandatory report layout.');
        }
        $footerY = 285 - (count($footerRows) + 1) * 4;
        $bodyEnd = $footerY - 8;
        $pageNumber = 0;
        $y = 0.0;
        $newPage = function () use ($pdf, $font, $document, $tradeNameRows, $footerRows, $footerY, &$pageNumber, &$y): void {
            $pdf->addPage();
            $pdf->page->addContent($font['out']);
            $pageNumber++;
            $headerY = 10.0;
            foreach ($tradeNameRows as $row) {
                $pdf->addTextCell($row, posx: 15, posy: $headerY, width: 160, height: 6, drawcell: false);
                $headerY += 6;
            }
            $titleY = max(20, $headerY + 2);
            $pdf->addTextCell(($document['type'] === 'partial' ? 'INFORME PARCIAL' : 'INFORME COMPLETO').' | '.$document['number'], posx: 15, posy: $titleY, width: 180, height: 8, drawcell: false);
            $pdf->addTextCell('Página '.$pageNumber, posx: 15, posy: $footerY, width: 180, height: 4, drawcell: false);
            $position = $footerY + 4;
            foreach ($footerRows as $row) {
                $pdf->addTextCell($row, posx: 15, posy: $position, width: 180, height: 4, drawcell: false);
                $position += 4;
            }
            $y = $titleY + 12;
        };
        $text = function (?string $value) use ($pdf, $newPage, $wrap, $bodyEnd, &$y): void {
            foreach ($wrap((string) $value, 175) as $row) {
                if ($y + 6 > $bodyEnd) {
                    $newPage();
                }
                $pdf->addTextCell($row, posx: 15, posy: $y, width: 180, height: 6, drawcell: false);
                $y += 6;
            }
        };
        $newPage();
        if ($presentation['logo'] !== null) {
            $iid = $pdf->image->add('@'.base64_decode($presentation['logo'], true));
            $pdf->page->addContent($pdf->image->getSetImage($iid, 175, 5, 18, 12, 297));
        }
        $text(($presentation['address'] ?? '').' | '.($presentation['phone'] ?? ''));
        $text($presentation['header'] ?? '');
        $text('Emisión: '.$document['issued_at'].' | Orden: '.$document['order_code']);
        $identity = $document['identity'];
        $text('Paciente: '.$identity['patient']['first_names'].' '.$identity['patient']['last_names']);
        $text('Afiliación: '.($identity['patient']['affiliation_number'] ?? 'No registrada'));
        $text('Sucursal: '.$identity['branch']['name'].' | '.$identity['branch']['code']);
        $text($identity['source'] === 'order_creation' ? 'Identidad conservada al crear la orden.' : 'Identidad actual confirmada en la emisión; no representa identidad histórica de la orden.');
        if ($document['type'] === 'partial') {
            $text('Informe parcial: incluye únicamente las líneas resueltas seleccionadas. La orden no está finalizada.');
        }
        $text('Verificación de autenticidad mediante QR. No equivale a firma electrónica certificada.');
        if ($y + 38 > $bodyEnd) {
            $newPage();
        }
        $pdf->page->addContent($pdf->getBarcode('QRCODE,M', $verificationUrl, 15, $y, 32, 32, [4, 4, 4, 4], ['fillColor' => 'black', 'lineWidth' => 0]));
        $y += 38;
        $groups = [];
        foreach ($document['lines'] as $line) {
            $key = json_encode($line['area'], JSON_THROW_ON_ERROR);
            $groups[$key]['name'] = $line['area']['name'] ?? 'Área histórica no disponible';
            $groups[$key]['lines'][] = $line;
        }
        foreach ($groups as $group) {
            $text('ÁREA: '.$group['name']);
            foreach ($group['lines'] as $line) {
                $text('Examen: '.$line['exam_code'].' — '.$line['exam_name'].' | Línea '.$line['line_id']);
                if ($line['validation'] !== null) {
                    $v = $line['validation'];
                    $text('Validación '.$v['id'].' | Revisión aprobada '.$v['approved_revision'].' | '.$v['validated_at']);
                    foreach ($v['results_snapshot'] as $result) {
                        $text($result['parameter_snapshot']['name'].' ['.$result['parameter_code'].']: '.$result['value'].($result['option_label'] === null ? '' : ' — '.$result['option_label']).' '.($result['unit'] ?? ''));
                    }
                    foreach ($v['reference_snapshot'] as $reference) {
                        $rule = $reference['reference'];
                        $description = $reference['status'] === 'not_configured' ? 'No configurada' : ($rule === null ? 'Sin referencia aplicable en la evidencia aprobada' : $this->referenceText($rule));
                        $text('Referencia '.$reference['parameter_code'].': '.$description.($reference['reference_key'] === null ? '' : ' [clave '.$reference['reference_key'].']'));
                    }
                    $context = $v['context_snapshot'];
                    $text('Fecha de referencia: '.$context['reference_date']);
                    if ($context['age_days'] !== null) {
                        $text('Edad utilizada: '.$context['age_days'].' días cumplidos');
                    }
                    if ($context['sex'] !== null) {
                        $text('Sexo clínico declarado: '.$context['sex']);
                    }
                    foreach ($context['conditions'] as $condition => $value) {
                        $text('Condición utilizada: '.$condition.' = '.$value);
                    }
                    if ($v['comment'] !== null) {
                        $text('Observación autorizada: '.$v['comment']);
                    }
                    $professional = $v['professional_snapshot'];
                    $text($professional === null ? 'Validación autorizada. Identificador del validador: '.$v['validated_by'].'. Sin identidad profesional registrada en esta evidencia.'
                        : 'Profesional: '.$professional['professional_name'].' | '.$professional['profession'].' | Registro: '.($professional['registration_number'] ?? 'No registrado'));
                } elseif (($document['format_version'] ?? 'donqer-clinical-1') === 'donqer-clinical-2') {
                    $text($line['clinical_display']['label'].'. No constituye un resultado clínico.');
                    $text('Motivo clínico: '.$line['clinical_display']['reason']);
                } else {
                    $text('EXCEPCIÓN CLÍNICA AUTORIZADA. No constituye un resultado clínico.');
                    $text('Motivo documentado: '.$line['exception']['request']['reason']);
                    $d = $line['exception']['decision'];
                    $text('Decisión '.$d['id'].' | Actor '.$d['decided_by'].' | '.$d['decided_at']);
                    if ($d['reason'] !== null) {
                        $text('Motivo de aprobación: '.$d['reason']);
                    }
                }
                $text(' ');
            }
        }

        if (($document['pending_lines'] ?? []) !== []) {
            $text('EXÁMENES PENDIENTES — sin resultados clínicos publicados');
            foreach ($document['pending_lines'] as $pending) {
                $text($pending['exam_code'].' — '.$pending['exam_name'].' | Línea '.$pending['line_id']);
                $text($pending['clinical_status'] === 'incomplete' ? 'Incompleto — situación clínica pendiente.' : 'Pendiente de resolución clínica.');
                $text($pending['processing_started'] ? 'Procesamiento iniciado; sin resolución definitiva.' : 'El procesamiento no ha comenzado.');
            }
        }

        return $pdf->getOutPDFString();
    }

    private function referenceText(array $rule): string
    {
        if (array_key_exists('text', $rule)) {
            return $rule['text'];
        }
        if (isset($rule['allowed_options'])) {
            return 'Opciones de referencia: '.implode(', ', $rule['allowed_options']);
        }
        $limits = [];
        if (isset($rule['min'])) {
            $limits[] = 'Mínimo '.$rule['min'];
        }
        if (isset($rule['max'])) {
            $limits[] = 'Máximo '.$rule['max'];
        }

        return implode(' | ', $limits);
    }
}
