<?php

namespace App\ClinicalReports;

use App\ClinicalExams\ClinicalConflict;
use Com\Tecnick\Pdf\Tcpdf;

class ClinicalReportRenderer
{
    private const PAGE_HEIGHT = 279.4;
    // 25% tighter than the former 2 mm gap, without changing line-height or wrapping.
    private const INSTITUTIONAL_BLOCK_GAP = 1.5;

    /** Render only immutable server snapshots. No HTML, remote assets or signatures. */
    public function render(array $document, string $verificationUrl): string
    {
        $pdf = $this->pdf();
        $pdf->setTitle($document['number']);
        $pdf->setAuthor($document['presentation']['trade_name']);
        $title = 'INFORME DE RESULTADOS CLÍNICOS';
        $reportMeta = ($document['type'] === 'partial' ? 'Informe parcial' : 'Informe completo').' | '.$document['number'];
        if ($document['preview'] ?? false) {
            $title = 'VISTA PREVIA — DATOS FICTICIOS';
            $reportMeta = 'Vista previa institucional';
        }
        $layout = $this->layout($pdf, $document['presentation'], $title, $reportMeta);
        $bodyFont = $this->font($pdf, 9);
        $wrap = fn (string $value, float $width) => $this->wrap($pdf, $value, $width);
        $bodyEnd = $layout['footer_y'] - 8;
        $accent = match ($document['presentation']['color_theme'] ?? 'neutral') {
            'neutral' => '#111111', 'teal' => '#006666', 'blue' => '#163A70', 'burgundy' => '#702038',
            default => throw new ClinicalConflict('report_presentation_invalid', 'Unsupported institutional color theme.'),
        };
        $pageNumber = 0;
        $y = 0.0;
        $areaContext = null;
        $examContext = null;
        $parameterContext = null;
        $tableActive = false;
        $tableText = null;
        $resumeTable = null;
        $identity = $document['identity'];
        $identityRows = [
            [
                ['Paciente', $identity['patient']['first_names'].' '.$identity['patient']['last_names'], 90.0],
                ['Edad', $this->patientAge($document), 30.0],
                ['Orden', $document['order_code'], 60.0],
            ],
            [
                ['Sucursal', $identity['branch']['name'].' | '.$identity['branch']['code'], 90.0],
                [isset($document['sequence']) ? 'Emisión / versión' : 'Emisión', $this->displayDate($document['issued_at']).(isset($document['sequence']) ? ' | '.$document['sequence'] : ''), 90.0],
            ],
        ];
        $identityHeight = function () use ($identityRows, $wrap): float {
            $height = 0.0;
            foreach ($identityRows as $cells) {
                $height += 6 + max(array_map(fn ($cell) => count($wrap($cell[1], $cell[2] - 6)), $cells)) * 5;
            }

            return $height;
        };
        $drawIdentity = function () use ($pdf, $identityRows, $wrap, $accent, &$y): void {
            foreach ($identityRows as $cells) {
                $valueRows = array_map(fn ($cell) => $wrap($cell[1], $cell[2] - 6), $cells);
                $height = 6 + max(array_map('count', $valueRows)) * 5;
                $x = 15.0;
                foreach ($cells as $cellIndex => [$label, , $width]) {
                    $pdf->page->addContent($this->font($pdf, 8)['out']);
                    $pdf->page->addContent($pdf->color->getPdfFillColor($accent));
                    $pdf->addTextCell($label, posx: $x + 2, posy: $y + 1, width: $width - 4, height: 4, drawcell: false);
                    $pdf->page->addContent($this->font($pdf, 9)['out']);
                    $pdf->page->addContent($pdf->color->getPdfFillColor('black'));
                    foreach ($valueRows[$cellIndex] as $index => $row) {
                        $pdf->addTextCell($row, posx: $x + 2, posy: $y + 6 + $index * 5, width: $width - 4, height: 5, drawcell: false);
                    }
                    $x += $width;
                }
                // Keep the divider below the tallest cell, including wrapped identities.
                $pdf->page->addContent("q\n".$pdf->graph->getRect(15, $y + $height - 0.10, 180, 0.10, 'F',
                    ['all' => ['fillColor' => '#DDDDDD']])."\nQ\n");
                $y += $height;
            }
            $y += 3;
        };
        $newPage = function () use ($pdf, $bodyFont, $layout, $wrap, $identityHeight, $drawIdentity, $bodyEnd, $accent, $verificationUrl, &$areaContext, &$examContext, &$parameterContext, &$pageNumber, &$y, &$tableActive, &$resumeTable): void {
            $pdf->addPage(['format' => 'LETTER']);
            $pageNumber++;
            if ($layout['logo'] !== null) {
                $logo = $layout['logo'];
                $iid = $pdf->image->add('@'.$logo['bytes']);
                $pdf->page->addContent($pdf->image->getSetImage($iid, $logo['x'], 10, $logo['width'], $logo['height'], self::PAGE_HEIGHT));
            }
            $pdf->page->addContent($pdf->color->getPdfFillColor($accent));
            foreach ($layout['header'] as $block) {
                $this->drawBlock($pdf, $block, 10 + $block['y'], $block['width'], $block['x']);
            }
            $pdf->page->addContent("q\n".$pdf->getBarcode('QRCODE,M', $verificationUrl, 169, 10, 26, 26, [4, 4, 4, 4], ['fillColor' => 'black', 'lineWidth' => 0])."\nQ\n");
            $pdf->page->addContent($pdf->color->getPdfFillColor('black'));
            $pdf->page->addContent($bodyFont['out']);
            $this->drawBlock($pdf, $layout['footer'], $layout['footer_y'] + 5);
            $pdf->page->addContent($this->font($pdf, 9)['out']);
            $y = $layout['body_y'];
            if ($pageNumber === 1) {
                if ($y + $identityHeight() + 24 > $bodyEnd) {
                    throw new ClinicalConflict('report_presentation_invalid', 'The identity and clinical context leave insufficient space for results.');
                }
                $drawIdentity();
            }
            foreach (array_filter($tableActive ? [] : [$areaContext, $examContext, $parameterContext]) as $value) {
                foreach ($wrap($value, 175) as $row) {
                    if ($y + 24 > $bodyEnd) {
                        throw new ClinicalConflict('report_presentation_invalid', 'The repeated clinical context leaves insufficient space for results.');
                    }
                    $pdf->addTextCell($row, posx: 15, posy: $y, width: 180, height: 6, drawcell: false);
                    $y += 6;
                }
            }
            if ($tableActive) {
                $resumeTable();
            }
        };
        $text = function (?string $value) use ($pdf, $newPage, $wrap, $bodyEnd, $layout, &$areaContext, &$examContext, &$parameterContext, &$y, &$tableActive, &$tableText): void {
            if ($tableActive) {
                $tableText((string) $value);

                return;
            }
            if (trim((string) $value) === '' && $y + 6 > $bodyEnd) {
                return;
            }
            $rows = $wrap((string) $value, 175);
            // Keep a clinical item together when it can fit on one page. Long
            // observations still flow, with their area/exam repeated on each page.
            $height = count($rows) * 6;
            $contextRows = array_sum(array_map(fn ($context) => count($wrap($context, 175)), array_filter([$areaContext, $examContext, $parameterContext])));
            $pageCapacity = $bodyEnd - $layout['body_y'] - $contextRows * 6 - 3;
            if ($height <= $pageCapacity && $y + $height > $bodyEnd) {
                $newPage();
            }
            foreach ($rows as $row) {
                if ($y + 6 > $bodyEnd) {
                    $newPage();
                }
                $pdf->addTextCell($row, posx: 15, posy: $y, width: 180, height: 6, drawcell: false);
                $y += 6;
            }
        };
        // A table is drawn as vector cells and measured text, never as an image.
        // Only a row larger than a fresh page is split, at shared text baselines.
        $tablePage = null;
        $widths = [50.0, 53.0, 22.0, 55.0];
        $lineHeight = 4.2;
        $padding = 1.0;
        $drawCells = function (array $cells, int $offset, int $count, float $height, bool $header = false, bool $abnormalResult = false) use ($pdf, $widths, $lineHeight, $padding, &$y): void {
            $x = 15.0;
            foreach ($cells as $index => $rows) {
                $pdf->page->addContent("q\n".$pdf->graph->getRect($x, $y, $widths[$index], $height, $header ? 'B' : 'F',
                    ['all' => ['lineWidth' => $header ? 0.20 : 0.12, 'lineColor' => '#9A9A9A', 'fillColor' => $header ? '#EDF2F2' : '#FFFFFF']])."\nQ\n");
                $resultColor = $index === 1 && ! $header && $abnormalResult ? '#B42318' : 'black';
                $pdf->page->addContent("q\n".$pdf->color->getPdfFillColor($resultColor).$pdf->color->getPdfStrokeColor($resultColor));
                foreach (array_slice($rows, $offset, $count) as $rowIndex => $row) {
                    $pdf->addTextCell($row, posx: $x + $padding, posy: $y + $padding + $rowIndex * $lineHeight,
                        width: $widths[$index] - 2 * $padding, height: $lineHeight, strokewidth: $index === 1 && ! $header ? 0.08 : 0, stroke: $index === 1 && ! $header, drawcell: false);
                }
                $pdf->page->addContent("\nQ\n");
                $x += $widths[$index];
            }
            $y += $height;
        };
        $cellWrap = fn (string $value, float $width) => $this->wrap($pdf, $value, $width, true);
        $tableHeader = function () use ($cellWrap, $widths, $padding, $lineHeight, $drawCells, &$tablePage, &$pageNumber): void {
            $cells = [];
            foreach (['Parámetro', 'Resultado', 'Unidad', 'Valores de referencia'] as $index => $heading) {
                $cells[] = $cellWrap($heading, $widths[$index] - 2 * $padding);
            }
            $count = max(array_map('count', $cells));
            $drawCells($cells, 0, $count, $count * $lineHeight + 2 * $padding, true);
            $tablePage = $pageNumber;
        };
        // Full-width rows share the same outer edges as parameter cells. This
        // includes observations and evidence, so they never interrupt the table.
        $drawBand = function (array $rows, string $kind = 'detail') use ($pdf, $accent, $padding, $lineHeight, &$y): void {
            $height = count($rows) * $lineHeight + 2 * $padding;
            $fill = match ($kind) {
                'area' => '#E8F1F1',
                'exam' => '#F5F5F5',
                default => '#FFFFFF',
            };
            $pdf->page->addContent("q\n".$pdf->graph->getRect(15, $y, 180, $height, 'F', ['all' => ['fillColor' => $fill]])."\nQ\n");
            $pdf->page->addContent($pdf->color->getPdfFillColor(in_array($kind, ['area', 'exam']) ? $accent : 'black'));
            foreach ($rows as $index => $row) {
                $pdf->addTextCell($row, posx: 17, posy: $y + $padding + $index * $lineHeight, width: 176, height: $lineHeight, strokewidth: 0.10, stroke: $kind === 'area', drawcell: false);
            }
            $pdf->page->addContent($this->font($pdf, 9)['out']);
            $pdf->page->addContent($pdf->color->getPdfFillColor('black'));
            $y += $height;
        };
        $bandRows = fn (string $value) => $cellWrap($value, 176);
        $resumeTable = function () use ($tableHeader, $drawBand, $bandRows, $bodyEnd, &$y, &$areaContext, &$examContext, &$parameterContext): void {
            $contexts = array_filter([$areaContext, $examContext, $parameterContext]);
            $height = 9 + array_sum(array_map(fn ($value) => count($bandRows($value)) * 5 + 4, $contexts));
            if ($y + $height + 9 > $bodyEnd) {
                throw new ClinicalConflict('report_presentation_invalid', 'The repeated table context leaves insufficient space for results.');
            }
            $tableHeader();
            foreach (['area' => $areaContext, 'exam' => $examContext, 'detail' => $parameterContext] as $kind => $value) {
                if ($value !== null) {
                    $drawBand($bandRows($value), $kind);
                }
            }
        };
        $tableText = function (string $value, string $kind = 'detail') use ($bandRows, $drawBand, $newPage, $bodyEnd, $lineHeight, $padding, &$y): void {
            if (trim($value) === '') {
                return;
            }
            $rows = $bandRows($value);
            // Keep ordinary evidence blocks together; extreme text flows by line.
            $height = count($rows) * $lineHeight + 2 * $padding;
            if ($y + $height > $bodyEnd && $height < 60) {
                $newPage();
            }
            while ($rows !== []) {
                $available = (int) floor(($bodyEnd - $y - 2 * $padding) / $lineHeight);
                if ($available < 1) {
                    $newPage();
                    $available = (int) floor(($bodyEnd - $y - 2 * $padding) / $lineHeight);
                }
                if ($available < 1) {
                    throw new ClinicalConflict('report_presentation_invalid', 'The repeated table context leaves insufficient space for evidence.');
                }
                $drawBand(array_splice($rows, 0, $available), $kind);
                if ($rows !== []) {
                    $newPage();
                }
            }
        };
        $tableRow = function (array $values, bool $abnormalResult = false) use ($cellWrap, $widths, $padding, $lineHeight, $drawCells, $tableHeader, $newPage, $bodyEnd, $layout,
            &$areaContext, &$examContext, &$parameterContext, &$tablePage, &$pageNumber, &$y): void {
            $cells = [];
            foreach ($values as $index => $value) {
                $cells[] = $cellWrap($value, $widths[$index] - 2 * $padding);
            }
            $count = max(array_map('count', $cells));
            $height = $count * $lineHeight + 2 * $padding;
            $headerHeight = $lineHeight + 2 * $padding;
            $contextHeight = array_sum(array_map(fn ($context) => count($cellWrap($context, 176)) * $lineHeight + 2 * $padding, array_filter([$areaContext, $examContext, $parameterContext])));
            $freshCapacity = $bodyEnd - $layout['body_y'] - $contextHeight - $headerHeight;
            $requiredHeader = $tablePage === $pageNumber ? 0 : $headerHeight;
            if (($height <= $freshCapacity && $y + $requiredHeader + $height > $bodyEnd)
                || $y + $requiredHeader + $lineHeight + 2 * $padding > $bodyEnd) {
                $newPage();
            }
            if ($tablePage !== $pageNumber) {
                $tableHeader();
            }
            $offset = 0;
            while ($offset < $count) {
                $available = (int) floor(($bodyEnd - $y - 2 * $padding) / $lineHeight);
                if ($available < 1) {
                    throw new ClinicalConflict('report_presentation_invalid', 'The repeated context and table header leave insufficient space for a result row.');
                }
                $take = min($available, $count - $offset);
                $drawCells($cells, $offset, $take, $take * $lineHeight + 2 * $padding, false, $abnormalResult);
                $offset += $take;
                if ($offset < $count) {
                    $newPage();
                }
            }
        };
        $newPage();
        if ($document['type'] === 'partial') {
            $text('Informe parcial: incluye únicamente las líneas resueltas seleccionadas. La orden no está finalizada.');
        }
        $text('Verificación de autenticidad mediante QR. No equivale a firma electrónica certificada.');
        $groups = [];
        $generalObservations = [];
        $responsibles = [];
        foreach ($document['lines'] as $line) {
            $key = json_encode($line['area'], JSON_THROW_ON_ERROR);
            $groups[$key]['name'] = $line['area']['name'] ?? 'Área histórica no disponible';
            $groups[$key]['id'] = $line['area']['id'] ?? null;
            $groups[$key]['lines'][] = $line;
        }
        $areaRank = array_flip($document['presentation']['area_order'] ?? []);
        $examRank = array_flip($document['presentation']['exam_order'] ?? []);
        uasort($groups, fn ($a, $b) => ($areaRank[$a['id']] ?? PHP_INT_MAX) <=> ($areaRank[$b['id']] ?? PHP_INT_MAX));
        foreach ($groups as $group) {
            $areaObservations = [];
            usort($group['lines'], function ($a, $b) use ($document, $examRank) {
                $aId = $document['exam_id_by_line'][$a['line_id']] ?? $a['validation']['laboratory_exam_id'] ?? null;
                $bId = $document['exam_id_by_line'][$b['line_id']] ?? $b['validation']['laboratory_exam_id'] ?? null;

                return ($examRank[$aId] ?? PHP_INT_MAX) <=> ($examRank[$bId] ?? PHP_INT_MAX);
            });
            $newArea = 'ÁREA: '.$group['name'];
            foreach ($group['lines'] as $line) {
                $newExam = 'Examen: '.$line['exam_name']."\n".'Código: '.$line['exam_code'];
                $parameterContext = null;
                // Reserve headings, validation evidence and the first result.
                // Clear previous context before starting a new group on a page.
                $first = $line['validation']['results_snapshot'][0] ?? null;
                $firstHeight = $lineHeight + 2 * $padding;
                if ($first !== null) {
                    $reference = collect($line['validation']['reference_snapshot'])->firstWhere('parameter_code', $first['parameter_code']);
                    $values = $this->resultCells($first, $reference, $line['validation']);
                    $firstHeight = max(array_map(fn ($value, $width) => count($cellWrap($value, $width - 4)), $values, $widths)) * 5 + 4;
                }
                $headingHeight = count($bandRows($newExam)) * 5 + 4
                    + ($areaContext !== $newArea ? count($bandRows($newArea)) * 5 + 4 : 0);
                $firstContext = $first === null ? null : 'Parámetro: '.$first['parameter_snapshot']['name'].' ['.$first['parameter_code'].']';
                $contextHeight = array_sum(array_map(fn ($value) => count($bandRows($value)) * 5 + 4, array_filter([$newArea, $newExam, $firstContext])));
                $freshHeight = $bodyEnd - $layout['body_y'] - 9 - $contextHeight;
                if ($y + ($tablePage === $pageNumber ? 0 : 9) + $headingHeight + ($firstHeight > $freshHeight ? 9 : $firstHeight) > $bodyEnd) {
                    $areaContext = null;
                    $examContext = null;
                    $newPage();
                }
                if ($areaContext !== $newArea) {
                    $tableText($newArea, 'area');
                    $areaContext = $newArea;
                }
                $tableText($newExam, 'exam');
                $examContext = $newExam;
                if ($line['validation'] !== null) {
                    if (! $tableActive) {
                        $tableActive = true;
                        $tableHeader();
                    }
                    $v = $line['validation'];
                    $parameterObservations = [];
                    foreach ($v['results_snapshot'] as $result) {
                        $parameterContext = 'Parámetro: '.$result['parameter_snapshot']['name'].' ['.$result['parameter_code'].']';
                        $reference = collect($v['reference_snapshot'])->firstWhere('parameter_code', $result['parameter_code']);
                        if ($reference === null) {
                            throw new ClinicalConflict('report_reference_missing', 'The validated parameter has no historical reference evidence.');
                        }
                        $tableRow($this->resultCells($result, $reference, $v), $this->isAbnormalResult($result, $v));
                        foreach ($v['context_snapshot']['observations'] ?? [] as $observation) {
                            if ($observation['scope'] === 'parameter' && $observation['parameter_code'] === $result['parameter_code']) {
                                $parameterObservations[] = ['parameter_code' => $result['parameter_code'], 'observation' => $observation];
                            }
                        }
                    }
                    $parameterContext = null;
                    // Results are the only tabular content. Evidence remains directly
                    // below its exam, but flows as secondary text without table bands.
                    $tableActive = false;
                    $tablePage = null;
                    $context = $v['context_snapshot'];
                    if ($context['age_days'] !== null) {
                        $text('Edad utilizada: '.$context['age_days'].' días cumplidos');
                    }
                    if ($context['sex'] !== null) {
                        $text('Sexo clínico declarado: '.$context['sex']);
                    }
                    foreach ($context['conditions'] as $condition => $value) {
                        $text('Condición utilizada: '.$condition.' = '.$value);
                    }
                    foreach ($parameterObservations as $item) {
                        $text('Observación de '.$item['parameter_code'].': '.$this->observationText($item['observation']));
                    }
                    foreach ($context['observations'] ?? [] as $observation) {
                        if ($observation['scope'] === 'exam') {
                            $text('Observación del examen: '.$this->observationText($observation));
                        } elseif ($observation['scope'] === 'area') {
                            $areaObservations[] = $observation;
                        } elseif ($observation['scope'] === 'report') {
                            $generalObservations[] = $observation;
                        }
                    }
                    if (($context['critical_confirmation'] ?? null) !== null) {
                        $text('Valores críticos verificados explícitamente por el validador responsable: '.implode(', ', $context['critical_confirmation']['parameter_codes']).'.');
                    }
                    $key = json_encode([$v['validated_by'], $v['professional_snapshot'] ?? null,
                        $context['validator_identity'] ?? null, $context['signature_snapshot'] ?? null], JSON_THROW_ON_ERROR);
                    $responsibles[$key]['validation'] = $v;
                    $responsibles[$key]['exams'][] = $line['exam_code'].' — '.$line['exam_name'].' [validación '.$v['id'].']';
                } elseif (($document['format_version'] ?? 'donqer-clinical-1') === 'donqer-clinical-2') {
                    $text($line['clinical_display']['label'].'. No constituye un resultado clínico.');
                    $text('Motivo clínico: '.$line['clinical_display']['reason']);
                } else {
                    $text('EXCEPCIÓN CLÍNICA AUTORIZADA. No constituye un resultado clínico.');
                    $text('Motivo documentado: '.$line['exception']['request']['reason']);
                    $d = $line['exception']['decision'];
                    $text('Decisión '.$d['id'].' | Actor '.$d['decided_by'].' | '.$this->displayDate($d['decided_at']));
                    if ($d['reason'] !== null) {
                        $text('Motivo de aprobación: '.$d['reason']);
                    }
                }
                $text(' ');
            }
            $examContext = null;
            foreach ($areaObservations as $observation) {
                $text('Observación de área '.$group['name'].': '.$this->observationText($observation));
            }
        }

        $areaContext = null;
        $examContext = null;
        foreach ($generalObservations as $observation) {
            $heading = $observation['scope'] === 'area' ? 'Observación de área '.$observation['area']['name'] : 'Observación general del informe';
            $areaContext = $heading;
            $text($heading.': '.$this->observationText($observation));
        }

        if (($document['pending_lines'] ?? []) !== []) {
            $areaContext = null;
            $examContext = null;
            $text('EXÁMENES PENDIENTES — sin resultados clínicos publicados');
            foreach ($document['pending_lines'] as $pending) {
                $text($pending['exam_code'].' — '.$pending['exam_name']);
                $text($pending['clinical_status'] === 'incomplete' ? 'Incompleto — situación clínica pendiente.' : 'Pendiente de resolución clínica.');
                $text($pending['processing_started'] ? 'Procesamiento iniciado; sin resolución definitiva.' : 'El procesamiento no ha comenzado.');
            }
        }

        // Close the logical table before showing its responsible validators.
        $tableActive = false;
        $areaContext = null;
        $examContext = null;
        $parameterContext = null;
        // Measure and draw signature text with the same compact baselines.
        $signatureText = function (string $value) use ($pdf, $bandRows, $lineHeight, $newPage, $bodyEnd, &$y): void {
            foreach ($bandRows($value) as $row) {
                if ($y + $lineHeight > $bodyEnd) {
                    $newPage();
                }
                $pdf->addTextCell($row, posx: 17, posy: $y, width: 176, height: $lineHeight, drawcell: false);
                $y += $lineHeight;
            }
        };
        $firstResponsible = true;
        foreach ($responsibles as $responsible) {
            $v = $responsible['validation'];
            $context = $v['context_snapshot'];
            $professional = $v['professional_snapshot'] ?? $context['signature_snapshot']['professional'] ?? null;
            $professionalText = $professional === null ? ''
                : 'Profesional: '.$professional['professional_name']."\n".'Profesión: '.$professional['profession'].' | Registro: '.($professional['registration_number'] ?? 'No registrado');
            $signatureNotice = ($context['signature_snapshot'] ?? null) !== null
                ? 'Firma digitalizada autorizada de '.$professional['professional_name'].'. No equivale a una firma electrónica certificada.'
                : "Firma y sello: ______________________________\nEspacio para firma manuscrita. La generación del PDF no acredita su firma.";
            $height = 0.0;
            if (($context['signature_snapshot'] ?? null) !== null) {
                $bytes = base64_decode($context['signature_snapshot']['image'], true);
                $dimensions = $bytes === false ? false : @getimagesizefromstring($bytes);
                if (! $dimensions || ! hash_equals($context['signature_snapshot']['image_hash'], hash('sha256', $bytes))) {
                    throw new ClinicalConflict('report_signature_invalid', 'The authorized signature failed its integrity check.');
                }
                $width = 45.0;
                $height = $width * $dimensions[1] / $dimensions[0];
                if ($height > 18) {
                    $width *= 18 / $height;
                    $height = 18.0;
                }
            }
            $professionalRows = $professionalText === '' ? 0 : count($bandRows($professionalText));
            $handwritingSpace = ($context['signature_snapshot'] ?? null) === null ? 3 * $lineHeight : 0;
            $blockHeight = ($professionalRows + count($bandRows($signatureNotice))) * $lineHeight
                + ($height > 0 ? $height + 2 : 0) + $handwritingSpace;
            $freshCapacity = $bodyEnd - $layout['body_y'];
            $sectionHeight = $firstResponsible ? count($bandRows('RESPONSABLES, FIRMAS Y SELLOS')) * $lineHeight + 2 * $padding : 0;
            if ($blockHeight + $sectionHeight <= $freshCapacity && $y + $blockHeight + $sectionHeight > $bodyEnd) {
                $newPage();
            }
            if ($firstResponsible) {
                $tableText('RESPONSABLES, FIRMAS Y SELLOS', 'area');
                $firstResponsible = false;
            }
            if (($context['signature_snapshot'] ?? null) !== null) {
                $signatureTextHeight = ($professionalRows + count($bandRows($signatureNotice))) * $lineHeight + 2;
                if ($y + $height + $signatureTextHeight > $bodyEnd) {
                    $newPage();
                }
                if ($y + $height + $signatureTextHeight > $bodyEnd) {
                    throw new ClinicalConflict('report_presentation_invalid', 'The reserved signature block does not fit in the configured layout.');
                }
                $signatureText($professionalText);
                $image = $pdf->image->add('@'.$bytes);
                $pdf->page->addContent($pdf->image->getSetImage($image, 15, $y, $width, $height, self::PAGE_HEIGHT));
                $y += $height + 2;
                $signatureText($signatureNotice);
            } else {
                if ($professionalText !== '') {
                    $signatureText($professionalText);
                }
                $noticeHeight = count($bandRows($signatureNotice)) * $lineHeight;
                if ($y + $handwritingSpace + $noticeHeight > $bodyEnd) {
                    $newPage();
                }
                // Keep three blank lines, expanding to four when this page allows it.
                $y += min(4 * $lineHeight, $bodyEnd - $y - $noticeHeight);
                $signatureText($signatureNotice);
            }
            // A trailing separator must never create an otherwise empty page.
            $y = min($y + 2, $bodyEnd);
        }

        foreach (array_keys($pdf->page->getPages()) as $index => $pageId) {
            $pdf->setCurrentPage($pageId);
            $pdf->page->addContent($bodyFont['out']);
            $pdf->addTextCell('Página '.($index + 1).' de '.$pageNumber, posx: 15, posy: $layout['footer_y'], width: 180, height: 5, drawcell: false);
        }

        return $pdf->getOutPDFString();
    }

    /** Move immutable clinical evidence into cells without reclassification. */
    private function resultCells(array $result, ?array $reference, array $validation): array
    {
        if ($reference === null) {
            throw new ClinicalConflict('report_reference_missing', 'The validated parameter has no historical reference evidence.');
        }
        $rule = $reference['reference'];
        $description = $reference['status'] === 'not_configured' ? 'No configurada' : ($rule === null ? 'Sin referencia aplicable en la evidencia aprobada' : $this->referenceText($rule));

        return [
            $result['parameter_snapshot']['name'],
            $result['value'].($this->isAbnormalResult($result, $validation) ? ' *' : '')
                .($result['option_label'] === null ? '' : ' — '.$result['option_label']),
            $result['unit'] ?? '—',
            $description,
        ];
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

    private function wrap(Tcpdf $pdf, string $value, float $width, bool $wordBoundaries = false): array
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
                    $space = $wordBoundaries ? mb_strrpos($row, ' ') : false;
                    $break = $space === false ? mb_strlen($row) : $space + 1;
                    $rows[] = mb_substr($row, 0, $break);
                    $row = mb_substr($row, $break);
                    $used = array_sum(array_map(fn ($letter) => $pdf->toUnit($pdf->font->getCharWidth(mb_ord($letter))), mb_str_split($row)));
                }
                $row .= $char;
                $used += $charWidth;
            }
            $rows[] = $row === '' ? ' ' : $row;
        }

        return $rows;
    }

    private function block(Tcpdf $pdf, string $value, array $style, float $width = 180): array
    {
        $this->font($pdf, $style['font_size_pt']);
        $rows = $value === '' ? [] : $this->wrap($pdf, $value, $width);
        $lineHeight = $style['font_size_pt'] * 25.4 / 72 * 1.5;

        return ['rows' => $rows, 'style' => $style, 'line_height' => $lineHeight, 'height' => count($rows) * $lineHeight];
    }

    /** All configurable content has bounded space, independent of the clinical body. */
    private function layout(Tcpdf $pdf, array $presentation, string $title, string $reportMeta = ''): array
    {
        $styles = $presentation['styles'] ?? ReportPresentationStyles::defaults();
        ReportPresentationStyles::validate($styles);
        $header = [];
        $headerWidth = 148.0; // Reserve 26 mm for the QR and a 6 mm gap.
        $headerX = 15.0;
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
            $logo = ['bytes' => $bytes, 'width' => $width, 'height' => $logoHeight, 'x' => 15.0];
            $headerX += $width + 4;
            $headerWidth -= $width + 4;
        }
        $institutionalBlocks = [];
        foreach (['trade_name', 'address', 'phone', 'header'] as $name) {
            $blockStyle = $styles[$name];
            if ($name === 'trade_name') {
                $blockStyle['font_size_pt'] = max(15, $blockStyle['font_size_pt']);
            }
            $block = $this->block($pdf, $presentation[$name] ?? '', $blockStyle, $headerWidth);
            if ($name === 'trade_name' && count($block['rows']) > 8) {
                throw new ClinicalConflict('report_presentation_invalid', 'The laboratory name exceeds the reserved area.');
            }
            if ($block['rows'] !== []) {
                $institutionalBlocks[] = $block;
            }
        }
        $institutionalHeight = 0.0;
        foreach ($institutionalBlocks as $index => $block) {
            $header[] = $block + ['x' => $headerX, 'width' => $headerWidth, 'y' => $institutionalHeight];
            $institutionalHeight += $block['height'];
            if ($index < count($institutionalBlocks) - 1) {
                $institutionalHeight += self::INSTITUTIONAL_BLOCK_GAP;
            }
        }
        $titleY = max($institutionalHeight, $logo['height'] ?? 0) + 3;
        $titleStyle = $styles['title'];
        $titleStyle['font_size_pt'] = max(14, $titleStyle['font_size_pt']);
        $titleBlock = $this->block($pdf, $title, $titleStyle, 180);
        $header[] = $titleBlock + ['x' => 15.0, 'width' => 180.0, 'y' => $titleY];
        $height = 10 + $titleY + $titleBlock['height'] + 2;
        if ($reportMeta !== '') {
            $metaStyle = ['align' => $styles['title']['align'], 'font_size_pt' => 8];
            $meta = $this->block($pdf, $reportMeta, $metaStyle, 180);
            $header[] = $meta + ['x' => 15.0, 'width' => 180.0, 'y' => $titleY + $titleBlock['height'] + 2];
            $height += $meta['height'] + 2;
        }
        $footer = $this->block($pdf, $presentation['footer'] ?? '', $styles['footer']);
        if ($height > 105 || $footer['height'] > 45 || count($footer['rows']) > 20) {
            throw new ClinicalConflict('report_presentation_invalid', 'The presentation exceeds the reserved header or footer area. Shorten the text or reduce its size.');
        }

        return ['header' => $header, 'logo' => $logo, 'footer' => $footer, 'header_width' => $headerWidth, 'body_y' => max($height, 42) + 4, 'footer_y' => self::PAGE_HEIGHT - 10 - $footer['height'] - 5];
    }

    private function drawBlock(Tcpdf $pdf, array $block, float $y, float $width = 180, float $x = 15): void
    {
        $pdf->page->addContent($this->font($pdf, $block['style']['font_size_pt'])['out']);
        foreach ($block['rows'] as $row) {
            $align = match ($block['style']['align']) {
                'center' => 'C', 'right' => 'R', default => 'L'
            };
            // Let TCPDF align inside a full-width cell. Shrinking the cell to
            // the measured glyph width would trigger a second wrap for padding.
            $pdf->addTextCell($row, posx: $x, posy: $y, width: $width, height: $block['line_height'], halign: $align, drawcell: false);
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

    private function isAbnormalResult(array $result, array $validation): bool
    {
        $status = collect($validation['context_snapshot']['classifications'] ?? [])->firstWhere('parameter_code', $result['parameter_code'])['status'] ?? null;

        return in_array($status, ['high', 'low', 'critical', 'outside_reference'], true);
    }

    private function patientAge(array $document): string
    {
        $birthDate = $document['identity']['patient']['birth_date'] ?? null;
        $issuedDate = substr($document['issued_at'], 0, 10);
        if (! is_string($birthDate)) {
            return 'No disponible';
        }
        $birth = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $issued = \DateTimeImmutable::createFromFormat('!Y-m-d', $issuedDate);
        if ($birth === false || $issued === false || $birth->format('Y-m-d') !== $birthDate
            || $issued->format('Y-m-d') !== $issuedDate || $birth > $issued) {
            return 'No disponible';
        }
        $age = $birth->diff($issued);
        if ($age->y > 0) {
            return $age->y.($age->y === 1 ? ' año' : ' años');
        }
        if ($age->m > 0) {
            return $age->m.($age->m === 1 ? ' mes' : ' meses');
        }

        return $age->d.($age->d === 1 ? ' día' : ' días');
    }

    private function displayDate(string $value): string
    {
        return (new \DateTimeImmutable($value))->format('d/m/Y');
    }

    private function observationText(array $observation): string
    {
        return $observation['text']."\nAutor: ".$observation['author_name']."\nAutorizada: ".$this->displayDate($observation['authorized_at']);
    }
}
