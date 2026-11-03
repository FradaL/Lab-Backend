<?php

namespace Tests\Feature\ClinicalReports;

use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ClinicalReportService;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalReportTableHistoryTest extends TestCase
{
    use ClinicalReportScenario, DatabaseMigrations {
        ClinicalReportScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public static function reportTypes(): array
    {
        return ['partial' => [false], 'complete' => [true]];
    }

    #[DataProvider('reportTypes')]
    public function test_archived_inline_pdf_and_idempotent_replay_survive_a_renderer_upgrade(bool $complete): void
    {
        [$lab, $actor, $order, $line] = $this->resolved($complete);
        $pdf = new Tcpdf(fileOptions: ['allowedHosts' => [], 'allowedPaths' => [resource_path('report-fonts')]]);
        $pdf->addPage(['format' => 'LETTER']);
        $pdf->page->addContent($pdf->font->insert($pdf->pon, 'dejavusans', '', 9, ifile: resource_path('report-fonts/dejavusans.json'))['out']);
        $pdf->addTextCell('FORMATO HISTÓRICO SINTÉTICO SIN TABLA', posx: 15, posy: 20, width: 180, height: 6, drawcell: false);
        $legacyBytes = $pdf->getOutPDFString();
        $this->app->instance(ClinicalReportRenderer::class, new class($legacyBytes) extends ClinicalReportRenderer
        {
            public function __construct(private string $legacyBytes) {}

            public function render(array $document, string $verificationUrl): string
            {
                return $this->legacyBytes;
            }
        });
        $body = $this->body($order, $line, $complete);
        $old = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $body, 'table-history-upgrade-original');
        $before = $old->fresh()->getRawOriginal();
        $renderer = new class extends ClinicalReportRenderer
        {
            public int $calls = 0;

            public function render(array $document, string $verificationUrl): string
            {
                $this->calls++;

                return parent::render($document, $verificationUrl);
            }
        };
        $this->app->instance(ClinicalReportRenderer::class, $renderer);
        $this->app->forgetInstance(ClinicalReportService::class);
        $service = app(ClinicalReportService::class);
        $replayed = $service->issue($lab, $actor, $order->id, $body, 'table-history-upgrade-original');
        [, $download] = $service->download($lab, $actor, $order->id, $old->id);
        $this->assertSame(0, $renderer->calls, 'Historical downloads and retries must never render a PDF again.');
        $this->assertSame($old->id, $replayed->id);
        $this->assertSame($legacyBytes, $download);
        $this->assertSame(hash('sha256', $legacyBytes), $old->pdf_hash);
        $new = $service->issue($lab, $actor, $order->id, $body, 'table-history-upgrade-new');
        $this->assertSame(1, $renderer->calls);
        $this->assertSame($old->clinical_snapshot, $new->clinical_snapshot);
        $this->assertSame($old->format_version, $new->format_version);
        $this->assertSame($old->sequence + 1, $new->sequence);
        $this->assertNotSame($old->pdf_hash, $new->pdf_hash);
        $this->assertSame($legacyBytes, Storage::disk('clinical_reports')->get($old->private_path));
        $this->assertSame($before, $old->fresh()->getRawOriginal());
        $this->assertDatabaseCount('laboratory_report_issuances', 2);
        if (is_dir('/tmp/df011-artifacts')) {
            file_put_contents('/tmp/df011-artifacts/issued-'.($complete ? 'complete' : 'partial').'.pdf', Storage::disk('clinical_reports')->get($new->private_path));
        }
    }
}
