<?php

namespace Tests\Feature\ClinicalReports;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReports\ClinicalReportService;
use App\ClinicalReports\ReportSettingsService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\ClinicalValidation\ValidationSignatureService;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryResultValidation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportDf011Test extends TestCase
{
    use ClinicalReportScenario, DatabaseMigrations {
        ClinicalReportScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_saved_draft_does_not_change_issuance_until_explicit_publication_and_publications_are_immutable(): void
    {
        [$lab, $actor, $order, $line] = $this->resolved();
        $settings = app(ReportSettingsService::class);
        $original = $settings->snapshot($lab);
        $foreign = LaboratoryArea::factory()->create();
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'area_order' => [$foreign->id]])->assertUnprocessable();
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'color_theme' => 'arbitrary-color'])->assertUnprocessable();
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'trade_name' => 'DISEÑO FICTICIO', 'color_theme' => 'teal', 'area_order' => [$line->report_area_snapshot['id']], 'exam_order' => [$line->laboratory_exam_id]])
            ->assertOk()->assertJsonPath('data.state', 'draft')->assertJsonPath('data.published_revision', 0);
        $this->assertSame($original, $settings->snapshot($lab));
        $service = app(ClinicalReportService::class);
        setPermissionsTeamId($lab->id);
        $old = $service->issue($lab, $actor, $order->id, $this->body($order, $line), 'draft-isolation-ficticio-001');
        $bytes = Storage::disk('clinical_reports')->get($old->private_path);
        $this->postJson('/api/v1/laboratory/report-settings/publish', ['revision' => 1])->assertOk()->assertJsonPath('data.state', 'published');
        $this->postJson('/api/v1/laboratory/report-settings/publish', ['revision' => 1])->assertOk();
        $this->assertSame(1, DB::table('laboratory_report_settings_publications')->count());
        $this->assertSame('DISEÑO FICTICIO', $settings->snapshot($lab)[1]['trade_name']);
        setPermissionsTeamId($lab->id);
        $new = $service->issue($lab, $actor, $order->id, $this->body($order, $line), 'draft-publication-ficticio-002');
        $this->assertSame('DISEÑO FICTICIO', $new->presentation_snapshot['trade_name']);
        $this->assertSame('teal', $new->presentation_snapshot['color_theme']);
        $this->assertSame([$line->laboratory_exam_id], $new->presentation_snapshot['exam_order']);
        $this->get('/api/v1/laboratory/report-settings/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseCount('laboratory_report_issuances', 2);
        $this->assertSame($bytes, Storage::disk('clinical_reports')->get($old->private_path));
        $this->getJson('/api/v1/laboratory/report-settings/publications')->assertOk()->assertJsonPath('data.0.publisher_name', $actor->name);
        try {
            DB::transaction(fn () => DB::table('laboratory_report_settings_publications')->update(['publisher_name' => 'ALTERADO']));
            $this->fail('Published presentation history must be immutable.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        }
    }

    public function test_critical_value_needs_explicit_validator_confirmation_and_authorized_observations_keep_their_scope(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->scenario(true, ['N' => ['references' => [['key' => 'SYN', 'priority' => 1, 'min' => '0', 'max' => '10', 'critical_max' => '20']]]]);
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        $this->putJson($url, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '25'], ['parameter_code' => 'T', 'value' => 'FICTICIO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]])->assertOk();
        $body = ['revision' => 1, 'reference_context' => ['reference_date' => '2026-11-02'], 'observations' => [
            ['scope' => 'parameter', 'parameter_code' => 'N', 'text' => 'Observación ficticia de parámetro'],
            ['scope' => 'exam', 'text' => 'Observación ficticia de examen'],
            ['scope' => 'area', 'text' => 'Observación ficticia de área'],
            ['scope' => 'report', 'text' => 'Observación ficticia general'],
        ]];
        $this->postJson($url.'/validate', $body)->assertConflict()->assertJsonPath('code', 'result_critical_confirmation_required');
        $this->assertDatabaseCount('laboratory_result_validations', 0);
        $this->postJson($url.'/validate', array_replace($body, ['critical_confirmation' => true, 'observations' => [['scope' => 'parameter', 'parameter_code' => 'FOREIGN', 'text' => 'Not applicable']]]))->assertUnprocessable();
        $this->postJson($url.'/validate', $body + ['critical_confirmation' => true])->assertOk();
        $validation = LaboratoryResultValidation::firstOrFail();
        $context = $validation->context_snapshot;
        $this->assertSame('critical', $context['classifications'][0]['status']);
        $this->assertSame($actor->id, $context['critical_confirmation']['confirmed_by']);
        $this->assertSame($actor->name, $context['validator_identity']['name']);
        $this->assertSame(['parameter', 'exam', 'area', 'report'], array_column($context['observations'], 'scope'));
        $this->assertSame([$actor->id], array_values(array_unique(array_column($context['observations'], 'author_id'))));
        setPermissionsTeamId($lab->id);
        app(ClinicalResolutionService::class)->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'df011-critical-observations-001');
        $this->assertSame($context, $report->clinical_snapshot[0]['validation']['context_snapshot']);
        $pdf = Storage::disk('clinical_reports')->get($report->private_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if (is_dir('/tmp/df011-artifacts')) {
            file_put_contents('/tmp/df011-artifacts/critical-observations.pdf', $pdf);
        }
    }

    public function test_digital_signature_requires_its_owners_authorization_and_explicit_validation_consent_and_revocation_preserves_history(): void
    {
        [$lab, $actor, $order, $line, $url] = $this->scenario();
        $administration = app(ValidationAdministrationService::class);
        $professional = $administration->register($lab, $actor, ['user_id' => $actor->id, 'professional_name' => 'Profesional sintético', 'profession' => 'Profesión ficticia']);
        $manager = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($manager, $lab, ['results.professionals.manage']);
        setPermissionsTeamId($lab->id);
        $administration->professionalState($lab, $manager, $professional->id, true);
        $administration->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        $signatureUrl = '/api/v1/laboratory/validation-signature';
        $this->getJson($signatureUrl)->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.has_signature', false);
        $this->post($signatureUrl, ['revision' => '0', 'signature' => UploadedFile::fake()->image('firma.png')])->assertUnprocessable();
        $this->post($signatureUrl, ['revision' => '0', 'consent' => '1', 'signature' => UploadedFile::fake()->image('firma.png', 160, 40)])
            ->assertOk()->assertJsonPath('data.has_signature', true)->assertJsonMissingPath('data.image');
        $revision = $this->getJson($signatureUrl)->assertOk()->json('data.revision');
        $this->putJson($url, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '5'], ['parameter_code' => 'T', 'value' => 'FICTICIO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]])->assertOk();
        $this->postJson($url.'/validate', ['revision' => 1, 'reference_context' => ['reference_date' => '2026-11-02'], 'signature_confirmation' => true])->assertOk();
        $validation = LaboratoryResultValidation::firstOrFail();
        $signature = $validation->context_snapshot['signature_snapshot'];
        $this->assertSame($actor->id, $signature['confirmed_by']);
        $this->assertSame($professional->id, $signature['professional']['id']);
        $this->assertSame(hash('sha256', base64_decode($signature['image'])), $signature['image_hash']);
        setPermissionsTeamId($lab->id);
        app(ClinicalResolutionService::class)->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'df011-digital-signature-001');
        $bytes = Storage::disk('clinical_reports')->get($report->private_path);
        $this->assertStringContainsString('/Subtype /Image', $bytes);
        if (is_dir('/tmp/df011-artifacts')) {
            file_put_contents('/tmp/df011-artifacts/digital-signature.pdf', $bytes);
        }
        $this->getJson($url.'/validations')->assertOk()->assertJsonMissingPath('data.0.context_snapshot.signature_snapshot.image');
        $this->deleteJson($signatureUrl, ['revision' => $revision])->assertOk()->assertJsonPath('data.has_signature', false);
        $this->assertSame($signature, $validation->fresh()->context_snapshot['signature_snapshot']);
        $this->assertSame($bytes, Storage::disk('clinical_reports')->get($report->private_path));
        $this->assertDatabaseCount('validation_signature_events', 2);
        setPermissionsTeamId($lab->id);
        try {
            app(ValidationSignatureService::class)->snapshot($lab, $actor);
            $this->fail('A revoked signature must not be applicable to another validation.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('validation_signature_unavailable', $error->errorCode);
        }
        $other = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($other, $lab, ['results.validate']);
        $this->actingAs($other)->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson($signatureUrl)->assertOk()->assertJsonPath('data.has_signature', false);
    }
}
