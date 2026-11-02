<?php

namespace Tests\Feature\ClinicalReports;

use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ClinicalReportService;
use App\ClinicalReports\ReportPresentationStyles;
use App\ClinicalReports\ReportSettingsService;
use App\Models\Laboratory;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportPresentationSettingsTest extends TestCase
{
    use ClinicalReportScenario, DatabaseMigrations {
        ClinicalReportScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_logo_binary_is_private_authorized_and_scoped_to_active_lab(): void
    {
        [$lab, $actor] = $this->resolved();
        $url = '/api/v1/laboratory/report-settings/logo';
        $this->get($url)->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post($url, ['revision' => '0', 'logo' => UploadedFile::fake()->image('logo.jpg', 60, 30)])
            ->assertOk()->assertJsonMissingPath('data.presentation.logo');
        [, $presentation] = app(ReportSettingsService::class)->snapshot($lab);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(base64_decode($presentation['logo']), $response->getContent());
        $foreign = Laboratory::factory()->create();
        Subscription::factory()->for($foreign)->create();
        $foreign->users()->attach($actor->id, ['is_active' => true, 'is_default' => false]);
        $this->assignDirectLaboratoryPermissions($actor, $foreign, ['reports.settings.view']);
        $this->withHeader('X-Laboratory-ID', (string) $foreign->id)->get($url)->assertNotFound();
        $reader = $this->createLaboratoryMember($lab);
        $this->actingAs($reader)->withHeader('X-Laboratory-ID', (string) $lab->id)->get($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get($url, ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_legacy_settings_receive_defaults_without_rewriting_storage(): void
    {
        [$lab] = $this->resolved();
        $service = app(ReportSettingsService::class);
        [, $presentation] = $service->snapshot($lab);
        unset($presentation['styles']);
        DB::table('laboratory_report_settings')->insert(['laboratory_id' => $lab->id, 'revision' => 7, 'presentation' => json_encode($presentation)]);
        $before = DB::table('laboratory_report_settings')->where('laboratory_id', $lab->id)->value('presentation');
        $this->getJson('/api/v1/laboratory/report-settings')->assertOk()->assertJsonPath('data.revision', 7)
            ->assertJsonPath('data.presentation.styles', ReportPresentationStyles::defaults());
        $this->assertSame($before, DB::table('laboratory_report_settings')->where('laboratory_id', $lab->id)->value('presentation'));
    }

    public function test_unknown_keys_enums_sizes_and_types_are_rejected_without_writes(): void
    {
        $this->resolved();
        $invalid = [];
        foreach (['justify', '', null] as $align) {
            $style = ReportPresentationStyles::defaults();
            $style['header']['align'] = $align;
            $invalid[] = $style;
        }
        foreach ([7, 13, 19, '12', 12.5] as $size) {
            $style = ReportPresentationStyles::defaults();
            $style['title']['font_size_pt'] = $size;
            $invalid[] = $style;
        }
        foreach ([0, 36, '18'] as $size) {
            $style = ReportPresentationStyles::defaults();
            $style['logo']['width_mm'] = $size;
            $invalid[] = $style;
        }
        $style = ReportPresentationStyles::defaults();
        $style['footer']['hidden'] = true;
        $invalid[] = $style;
        $style = ReportPresentationStyles::defaults();
        $style['clinical_body'] = ['hidden' => true];
        $invalid[] = $style;
        $invalid[] = [];
        $invalid[] = null;
        foreach ($invalid as $styles) {
            $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'styles' => $styles])->assertUnprocessable();
        }
        $this->assertDatabaseCount('laboratory_report_settings', 0);
    }

    public function test_logo_text_and_styles_share_one_atomic_revision_and_conflicts_do_not_partially_save(): void
    {
        [$lab] = $this->resolved();
        $styles = ReportPresentationStyles::defaults();
        $styles['title'] = ['align' => 'center', 'font_size_pt' => 18];
        $url = '/api/v1/laboratory/report-settings/logo';
        $this->post($url, ['revision' => '0', 'logo' => UploadedFile::fake()->image('logo.png'),
            'changes' => json_encode(['trade_name' => 'Atomic laboratory', 'styles' => $styles])])
            ->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.presentation.trade_name', 'Atomic laboratory')
            ->assertJsonPath('data.presentation.styles', $styles);
        $before = app(ReportSettingsService::class)->snapshot($lab);
        $this->post($url, ['revision' => '0', 'logo' => UploadedFile::fake()->image('other.png'), 'changes' => json_encode(['header' => 'Stale'])])->assertConflict();
        $invalid = $styles;
        $invalid['title']['align'] = 'invalid';
        $this->post($url, ['revision' => '1', 'logo' => UploadedFile::fake()->image('other.png'), 'changes' => json_encode(['header' => 'Invalid', 'styles' => $invalid])])->assertUnprocessable();
        $this->assertSame($before, app(ReportSettingsService::class)->snapshot($lab));
    }

    public function test_oversized_presentation_is_rejected_before_settings_are_saved(): void
    {
        $this->resolved();
        $styles = ReportPresentationStyles::defaults();
        $styles['footer']['font_size_pt'] = 18;
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'styles' => $styles, 'footer' => str_repeat('W', 500)])
            ->assertConflict()->assertJsonPath('code', 'report_presentation_invalid');
        $this->assertDatabaseCount('laboratory_report_settings', 0);
    }

    public function test_renderer_uses_snapshot_fonts_alignment_and_proportional_logo_without_overlap(): void
    {
        [$lab, $actor, $order, $line] = $this->resolved();
        $settings = app(ReportSettingsService::class);
        $renderer = app(ClinicalReportRenderer::class);
        $service = app(ClinicalReportService::class);
        $old = $service->issue($lab, $actor, $order->id, $this->body($order, $line), 'styles-old-report-0001');
        $oldBytes = Storage::disk('clinical_reports')->get($old->private_path);
        $oldSnapshot = $old->fresh()->presentation_snapshot;
        $layoutMethod = new \ReflectionMethod($renderer, 'layout');
        $pdfMethod = new \ReflectionMethod($renderer, 'pdf');
        foreach (['left', 'center', 'right'] as $index => $align) {
            $styles = ReportPresentationStyles::defaults();
            foreach (ReportPresentationStyles::TEXT_BLOCKS as $block) {
                $styles[$block] = ['align' => $align, 'font_size_pt' => 18];
            }
            $styles['logo'] = ['align' => $align, 'width_mm' => 35];
            $settings->update($lab, $actor, ['revision' => $index, 'trade_name' => 'LAB', 'styles' => $styles, 'address' => 'Address', 'phone' => 'Contact', 'header' => 'Header', 'footer' => 'Footer'], UploadedFile::fake()->image('wide.png', 200, 100));
            [, $presentation] = $settings->snapshot($lab);
            $layout = $layoutMethod->invoke($renderer, $pdfMethod->invoke($renderer), $presentation, 'INFORME COMPLETO');
            $this->assertSame(35.0, $layout['logo']['width']);
            $this->assertSame(17.5, $layout['logo']['height']);
            $this->assertEqualsWithDelta(15 + [0, 72.5, 145][$index], $layout['logo']['x'], 0.001);
            foreach ($layout['header'] as $block) {
                $this->assertSame(['align' => $align, 'font_size_pt' => 18], $block['style']);
                $this->assertEqualsWithDelta(count($block['rows']) * 18 * 25.4 / 72 * 1.5, $block['height'], 0.001);
            }
            $this->assertGreaterThan($layout['body_y'] + 100, $layout['footer_y']);
            $this->assertLessThanOrEqual(287, $layout['footer_y'] + 5 + $layout['footer']['height']);
            $report = $service->issue($lab, $actor, $order->id, $this->body($order, $line), 'styles-new-report-000'.$index);
            $this->assertEquals($styles, $report->presentation_snapshot['styles']);
            $bytes = Storage::disk('clinical_reports')->get($report->private_path);
            $this->assertStringStartsWith('%PDF-', $bytes);
            if (is_dir('/tmp/report-style-artifacts')) {
                file_put_contents('/tmp/report-style-artifacts/'.$align.'.pdf', $bytes);
            }
        }
        $this->assertSame($oldBytes, Storage::disk('clinical_reports')->get($old->private_path));
        $this->assertSame($oldSnapshot, $old->fresh()->presentation_snapshot);
    }
}
