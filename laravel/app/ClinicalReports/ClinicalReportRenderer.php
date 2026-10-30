<?php

namespace App\ClinicalReports;

use App\ClinicalExams\ClinicalConflict;
use Com\Tecnick\Pdf\Tcpdf;

class ClinicalReportRenderer
{
    /** Render only immutable server snapshots. No HTML, remote assets or signatures. */
    public function render(array $document, string $verificationUrl): string
    {
        $pdf = $this->pdf();
        $pdf->setTitle($document['number']);
        $pdf->setAuthor($document['presentation']['trade_name']);
        $title = ($document['type'] === 'partial' ? 'INFORME PARCIAL' : 'INFORME COMPLETO').' | '.$document['number'];
        $layout = $this->layout($pdf, $document['presentation'], $title);
        $bodyFont = $this->font($pdf, 9);
        $wrap = fn (string $value, float $width) => $this->wrap($pdf, $value, $width);
        $bodyEnd = $layout['footer_y'] - 8;
        $pageNumber = 0;
        $y = 0.0;
        $newPage = function () use ($pdf, $bodyFont, $layout, &$pageNumber, &$y): void {
            $pdf->addPage();
            $pageNumber++;
            $position = 10.0;
            if ($layout['logo'] !== null) {
                $logo = $layout['logo'];
                $iid = $pdf->image->add('@'.$logo['bytes']);
                $pdf->page->addContent($pdf->image->getSetImage($iid, $logo['x'], $position, $logo['width'], $logo['height'], 297));
                $position += $logo['height'] + 3;
            }
            foreach ($layout['header'] as $block) {
                $this->drawBlock($pdf, $block, $position);
                $position += $block['height'] + 2;
            }
            $pdf->page->addContent($bodyFont['out']);
            $pdf->addTextCell('Página '.$pageNumber, posx: 15, posy: $layout['footer_y'], width: 180, height: 5, drawcell: false);
            $this->drawBlock($pdf, $layout['footer'], $layout['footer_y'] + 5);
            $pdf->page->addContent($this->font($pdf, 9)['out']);
            $y = $layout['body_y'];
        };
        $text = function (?string $value) use ($pdf, $newPage, $wrap, $bodyEnd, &$y): void {
            if (trim((string) $value) === '' && $y + 6 > $bodyEnd) {
                return;
            }
            foreach ($wrap((string) $value, 175) as $row) {
                if ($y + 6 > $bodyEnd) {
                    $newPage();
                }
                $pdf->addTextCell($row, posx: 15, posy: $y, width: 180, height: 6, drawcell: false);
                $y += 6;
            }
        };
        $newPage();
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

        return $pdf->getOutPDFString();
    }

    public function validatePresentation(array $presentation): void
    {
        $this->layout($this->pdf(), $presentation, 'INFORME COMPLETO | REP-00000000000000000000000000');
    }

    private function pdf(): Tcpdf
    {
        $pdf = new Tcpdf(fileOptions: ['allowedHosts' => [], 'allowedPaths' => [resource_path('report-fonts')]]);
        $pdf->setCreator('Donqer Lab');

        return $pdf;
    }

    private function font(Tcpdf $pdf, int $size): array
    {
        return $pdf->font->insert($pdf->pon, 'dejavusans', '', $size, ifile: resource_path('report-fonts/dejavusans.json'));
    }

    private function wrap(Tcpdf $pdf, string $value, float $width): array
    {
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
    }

    private function block(Tcpdf $pdf, string $value, array $style): array
    {
        $this->font($pdf, $style['font_size_pt']);
        $rows = $value === '' ? [] : $this->wrap($pdf, $value, 180);
        $lineHeight = $style['font_size_pt'] * 25.4 / 72 * 1.5;

        return ['rows' => $rows, 'style' => $style, 'line_height' => $lineHeight, 'height' => count($rows) * $lineHeight];
    }

    /** All configurable content has bounded space, independent of the clinical body. */
    private function layout(Tcpdf $pdf, array $presentation, string $title): array
    {
        $styles = $presentation['styles'] ?? ReportPresentationStyles::defaults();
        ReportPresentationStyles::validate($styles);
        $header = [];
        $height = 10.0;
        $logo = null;
        if (($presentation['logo'] ?? null) !== null) {
            $bytes = base64_decode($presentation['logo'], true);
            $dimensions = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (! $dimensions) {
                throw new ClinicalConflict('report_presentation_invalid', 'The stored logo is invalid.');
            }
            $width = (float) $styles['logo']['width_mm'];
            $logoHeight = $width * $dimensions[1] / $dimensions[0];
            if ($logoHeight > 20) {
                $width *= 20 / $logoHeight;
                $logoHeight = 20.0;
            }
            $logo = ['bytes' => $bytes, 'width' => $width, 'height' => $logoHeight,
                'x' => 15 + match ($styles['logo']['align']) {
                    'center' => (180 - $width) / 2, 'right' => 180 - $width, default => 0
                }];
            $height += $logoHeight + 3;
        }
        foreach (['trade_name', 'address', 'phone', 'header', 'title'] as $name) {
            $block = $this->block($pdf, $name === 'title' ? $title : ($presentation[$name] ?? ''), $styles[$name]);
            if ($name === 'trade_name' && count($block['rows']) > 8) {
                throw new ClinicalConflict('report_presentation_invalid', 'The laboratory name exceeds the reserved area.');
            }
            $header[] = $block;
            $height += $block['height'] + 2;
        }
        $footer = $this->block($pdf, $presentation['footer'] ?? '', $styles['footer']);
        if ($height > 105 || $footer['height'] > 45 || count($footer['rows']) > 20) {
            throw new ClinicalConflict('report_presentation_invalid', 'The presentation exceeds the reserved header or footer area. Shorten the text or reduce its size.');
        }

        return ['header' => $header, 'logo' => $logo, 'footer' => $footer, 'body_y' => $height + 4, 'footer_y' => 287 - $footer['height'] - 5];
    }

    private function drawBlock(Tcpdf $pdf, array $block, float $y): void
    {
        $pdf->page->addContent($this->font($pdf, $block['style']['font_size_pt'])['out']);
        foreach ($block['rows'] as $row) {
            $align = match ($block['style']['align']) {
                'center' => 'C', 'right' => 'R', default => 'L'
            };
            // Let TCPDF align inside a full-width cell. Shrinking the cell to
            // the measured glyph width would trigger a second wrap for padding.
            $pdf->addTextCell($row, posx: 15, posy: $y, width: 180, height: $block['line_height'], halign: $align, drawcell: false);
            $y += $block['line_height'];
        }
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
