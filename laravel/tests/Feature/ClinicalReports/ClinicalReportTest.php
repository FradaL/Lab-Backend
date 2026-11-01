<?php

namespace Tests\Feature\ClinicalReports;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ClinicalReportService;
use App\ClinicalReports\PrivateReportStorage;
use App\ClinicalReports\ReportSettingsService;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryReportIssuance;
use App\Models\Subscription;
use Com\Tecnick\Pdf\Parser\Parser;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaboratoryReportIssuanceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalReportTest extends TestCase
{
    use ClinicalReportScenario, DatabaseMigrations {
        ClinicalReportScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_real_pdf_preserves_precise_history_and_has_private_hash_and_no_public_medical_data(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'report-test-key-0001');
        $pdf = Storage::disk('clinical_reports')->get($report->private_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(hash('sha256', $pdf), $report->pdf_hash);
        $this->assertNotEmpty((new Parser)->parse($pdf));
        $this->assertSame('12345678901234567890.123456789', $report->clinical_snapshot[0]['validation']['results_snapshot'][0]['value']);
        $this->assertSame($line->report_area_snapshot, $report->clinical_snapshot[0]['area']);
        $url = "/api/v1/laboratory-orders/$order->id/reports/$report->id";
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.private_path')->assertJsonMissingPath('data.clinical_snapshot');
        $this->get($url.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.downloaded']);
        $this->getJson('/api/v1/report-verifications/invalid')->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $this->assertDatabaseCount('laboratory_report_issuance_lines', 1);
        if (is_dir('/tmp/be091-artifacts')) {
            file_put_contents('/tmp/be091-artifacts/real.pdf', $pdf);
        }
    }

    public function test_complete_uses_closure_and_retry_keeps_bytes_and_only_one_issuance(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved(true);
        $service = app(ClinicalReportService::class);
        $body = $this->body($order, $line, true);
        $a = $service->issue($lab, $actor, $order->id, $body, 'report-test-key-0002');
        $b = $service->issue($lab, $actor, $order->id, $body, 'report-test-key-0002');
        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->pdf_hash, $b->pdf_hash);
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
        $this->assertNotNull($a->finalization_id);
        $this->assertCount(1, Storage::disk('clinical_reports')->allFiles());
        $this->withHeader('Idempotency-Key', 'report-test-key-0002')->postJson("/api/v1/laboratory-orders/$order->id/reports", $body + ['line_ids' => [$line->id]])->assertUnprocessable();
    }

    public function test_public_verification_exposes_exact_allowlist_and_rate_limit_without_tenant(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $renderer = new class extends ClinicalReportRenderer
        {
            public string $url;

            public function render(array $document, string $verificationUrl): string
            {
                $this->url = $verificationUrl;

                return parent::render($document, $verificationUrl);
            }
        };
        $this->app->instance(ClinicalReportRenderer::class, $renderer);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'public-verification-key-01');
        $secret = basename($renderer->url);
        $this->assertSame(hash('sha256', $secret), $report->verification_hash);
        $this->assertStringNotContainsString($secret, DB::table('laboratory_report_issuances')->get()->toJson());
        $this->assertStringNotContainsString($secret, DB::table('audit_logs')->get()->toJson());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/report-verifications/'.$secret)->assertOk()->assertExactJson(['data' => ['authentic' => true, 'number' => $report->number, 'issued_at' => $report->issued_at->toISOString(), 'laboratory' => $report->presentation_snapshot['trade_name']]]);
        for ($i = 0; $i < 29; $i++) {
            $this->getJson('/api/v1/report-verifications/invalid')->assertNotFound();
        }
        $this->getJson('/api/v1/report-verifications/invalid')->assertStatus(429);
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_or_unresolved_input_atomically(array $overrides, int $status): void
    {
        [$lab,$actor,$order,$line] = $this->scenario();
        $this->withHeader('Idempotency-Key', 'invalid-input-report-key')->postJson("/api/v1/laboratory-orders/$order->id/reports", array_replace($this->body($order, $line), $overrides))->assertStatus($status);
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->assertCount(0, Storage::disk('clinical_reports')->allFiles());
    }

    public static function invalidInputs(): array
    {
        return [
            'unresolved' => [[], 409], 'complete without closure' => [['type' => 'complete', 'line_ids' => null], 422],
            'empty' => [['line_ids' => []], 422], 'null' => [['line_ids' => null], 422], 'object' => [['line_ids' => ['a' => 1]], 422],
            'duplicate' => [['line_ids' => [1, 1]], 422], 'string ID' => [['line_ids' => ['1']], 422], 'zero ID' => [['line_ids' => [0]], 422],
            'boolean ID' => [['line_ids' => [true]], 422], 'unknown line' => [['line_ids' => [999999]], 422],
            'unknown field' => [['pdf_path' => 'evil'], 422], 'string revision' => [['clinical_revision' => '1'], 422],
            'stale revision' => [['clinical_revision' => 999], 409], 'invalid type' => [['type' => 'draft'], 422], 'confirmation string' => [['identity_confirmation' => 'true'], 422],
        ];
    }

    public function test_identity_confirmation_is_required_for_older_orders_and_no_history_is_rewritten(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $body = $this->body($order, $line);
        unset($body['identity_confirmation']);
        $before = $order->fresh()->getRawOriginal();
        $this->withHeader('Idempotency-Key', 'legacy-identity-report-01')->postJson("/api/v1/laboratory-orders/$order->id/reports", $body)->assertConflict()->assertJsonPath('code', 'report_identity_confirmation_required');
        $this->withHeader('Idempotency-Key', 'legacy-identity-report-01')->postJson("/api/v1/laboratory-orders/$order->id/reports", $body + ['identity_confirmation' => true])->assertCreated()->assertJsonPath('data.identity_source', 'confirmed_at_issuance');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_successive_emissions_keep_previous_files_visual_and_catalog_history(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $service = app(ClinicalReportService::class);
        $a = $service->issue($lab, $actor, $order->id, $this->body($order, $line), 'successive-report-key-001');
        $bytes = Storage::disk('clinical_reports')->get($a->private_path);
        $line->exam->laboratoryArea->update(['name' => 'Changed current area']);
        $order->patient->update(['first_names' => 'New current name']);
        app(ReportSettingsService::class)->update($lab, $actor, ['revision' => 0, 'trade_name' => 'New presentation', 'footer' => 'Changed footer']);
        $b = $service->issue($lab, $actor, $order->id, $this->body($order, $line), 'successive-report-key-002');
        $this->assertSame(2, $b->sequence);
        $this->assertNotSame($a->number, $b->number);
        $this->assertSame($a->clinical_snapshot, $b->clinical_snapshot);
        $this->assertSame($bytes, Storage::disk('clinical_reports')->get($a->private_path));
        $this->assertNotSame($a->presentation_snapshot, $b->presentation_snapshot);
        $this->assertNotSame($a->identity_snapshot['patient'], $b->identity_snapshot['patient']);
    }

    public function test_changed_payload_with_same_key_conflicts_without_another_pdf(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $service = app(ClinicalReportService::class);
        $body = $this->body($order, $line);
        $service->issue($lab, $actor, $order->id, $body, 'changed-payload-key-001');
        $this->withHeader('Idempotency-Key', 'changed-payload-key-001')->postJson("/api/v1/laboratory-orders/$order->id/reports", array_replace($body, ['identity_confirmation' => false]))->assertConflict()->assertJsonPath('code', 'report_idempotency_conflict');
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
        $this->assertCount(1, Storage::disk('clinical_reports')->allFiles());
    }

    #[DataProvider('failures')]
    public function test_generation_storage_or_audit_failure_creates_no_success_or_orphan(string $failure): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        if ($failure === 'render') {
            $this->app->instance(ClinicalReportRenderer::class, new class extends ClinicalReportRenderer
            {
                public function render(array $document, string $verificationUrl): string
                {
                    throw new \RuntimeException('Injected renderer failure');
                }
            });
        } elseif ($failure === 'storage') {
            $this->app->instance(PrivateReportStorage::class, new class extends PrivateReportStorage
            {
                public function write(int $lab, string $pdf): string
                {
                    throw new \RuntimeException('Injected storage failure');
                }
            });
        } else {
            AuditLog::creating(function ($event) {
                if ($event->event === 'order_report.issued') {
                    throw new \RuntimeException('Injected audit failure');
                }
            });
        }
        try {
            app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'failure-report-test-001');
            $this->fail('Injected failure must abort issuance.');
        } catch (\RuntimeException $e) {
            $this->assertDatabaseCount('laboratory_report_issuances', 0);
            $this->assertDatabaseCount('laboratory_report_issuance_lines', 0);
            $this->assertCount(0, Storage::disk('clinical_reports')->allFiles());
        }
    }

    public static function failures(): array
    {
        return [['render'], ['storage'], ['audit']];
    }

    public function test_render_does_not_hold_header_transaction_and_changed_evidence_discards_pdf(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $renderer = new class($order) extends ClinicalReportRenderer
        {
            public function __construct(private $order) {}

            public function render(array $document, string $verificationUrl): string
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Renderer must not hold a transaction.');
                }
                app(ClinicalResolutionService::class)->finalizeOrder($this->order->laboratory, auth()->user(), $this->order->id, ['clinical_revision' => $this->order->fresh()->clinical_revision]);

                return parent::render($document, $verificationUrl);
            }
        };
        $this->app->instance(ClinicalReportRenderer::class, $renderer);
        $this->withHeader('Idempotency-Key', 'changed-evidence-key-001')->postJson("/api/v1/laboratory-orders/$order->id/reports", $this->body($order, $line))->assertConflict()->assertJsonPath('code', 'clinical_revision_conflict');
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->assertCount(0, Storage::disk('clinical_reports')->allFiles());
    }

    public function test_internal_permissions_are_separate_and_foreign_order_is_not_visible(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'security-report-key-001');
        $reader = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reader, $lab, ['reports.view_history']);
        $this->actingAs($reader)->getJson("/api/v1/laboratory-orders/$order->id/reports")->assertOk();
        $this->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/download")->assertForbidden();
        $this->withHeader('Idempotency-Key', 'security-report-key-002')->postJson("/api/v1/laboratory-orders/$order->id/reports", $this->body($order, $line))->assertForbidden();
        $foreign = Laboratory::factory()->create();
        Subscription::factory()->for($foreign)->create();
        $foreign->users()->attach($reader->id, ['is_active' => true, 'is_default' => false]);
        $this->assignDirectLaboratoryPermissions($reader, $foreign, ['reports.view_history', 'reports.download']);
        $this->withHeader('X-Laboratory-ID', (string) $foreign->id)->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/laboratory-orders/$order->id/reports")->assertUnauthorized();
    }

    public function test_logo_is_reencoded_and_active_content_or_html_are_rejected(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'header' => '<script>alert(1)</script>'])->assertUnprocessable();
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'trade_name' => "Unsafe\nname"])->assertUnprocessable();
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'footer' => "one\ntwo\nthree\nfour\nfive"])->assertUnprocessable();
        $file = UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>');
        $this->post('/api/v1/laboratory/report-settings/logo', ['revision' => '0', 'logo' => $file])->assertUnprocessable();
        $image = UploadedFile::fake()->image('safe.png', 30, 20);
        $this->post('/api/v1/laboratory/report-settings/logo', ['revision' => '0', 'logo' => $image])->assertOk()->assertJsonPath('data.revision', 1)->assertJsonMissingPath('data.presentation.logo');
        [$rev,$config] = app(ReportSettingsService::class)->snapshot($lab);
        $this->assertSame(hash('sha256', base64_decode($config['logo'], true)), $config['logo_hash']);
        setPermissionsTeamId($lab->id);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'report-logo-render-key-1');
        $this->assertSame($config['logo_hash'], $report->presentation_snapshot['logo_hash']);
        $this->assertStringContainsString('/Subtype /Image', Storage::disk('clinical_reports')->get($report->private_path));
        $this->putJson('/api/v1/laboratory/report-settings', ['revision' => 0, 'footer' => 'stale'])->assertConflict();
    }

    public function test_renderer_rejects_saved_presentation_that_could_hide_mandatory_content(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        [$revision,$presentation] = app(ReportSettingsService::class)->snapshot($lab);
        $this->assertSame(0, $revision);
        $presentation['footer'] = implode("\n", array_fill(0, 21, 'oversized footer'));
        DB::table('laboratory_report_settings')->insert([
            'laboratory_id' => $lab->id,
            'revision' => 1,
            'presentation' => json_encode($presentation, JSON_THROW_ON_ERROR),
        ]);

        try {
            app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'invalid-layout-report-key-1');
            $this->fail('A presentation that displaces mandatory content must not be issued.');
        } catch (ClinicalConflict $exception) {
            $this->assertSame('report_presentation_invalid', $exception->errorCode);
        }
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->assertCount(0, Storage::disk('clinical_reports')->allFiles());
    }

    #[DataProvider('immutableTables')]
    public function test_sql_cannot_update_or_delete_report_evidence(string $table, string $verb): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'immutable-report-key-001');
        try {
            DB::transaction(fn () => DB::statement($verb === 'DELETE' ? "DELETE FROM $table" : "UPDATE $table SET id=id"));
            $this->fail('Report evidence must be immutable.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount($table, 1);
    }

    public static function immutableTables(): array
    {
        return [['laboratory_report_issuances', 'UPDATE'], ['laboratory_report_issuances', 'DELETE'], ['laboratory_report_issuance_lines', 'UPDATE'], ['laboratory_report_issuance_lines', 'DELETE']];
    }

    public function test_complete_without_closure_and_partial_after_closure_are_rejected(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $url = "/api/v1/laboratory-orders/$order->id/reports";
        $this->withHeader('Idempotency-Key', 'wrong-report-state-key-1')->postJson($url, $this->body($order, $line, true))->assertConflict()->assertJsonPath('code', 'report_finalization_required');
        setPermissionsTeamId($lab->id);
        app(ClinicalResolutionService::class)->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        $this->withHeader('Idempotency-Key', 'wrong-report-state-key-2')->postJson($url, $this->body($order, $line))->assertConflict()->assertJsonPath('code', 'report_partial_unavailable');
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
    }

    public function test_exception_issuance_contains_only_approved_exception_and_no_invented_values(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario(false);
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve']);
        $resolution = app(ClinicalResolutionService::class);
        $r = $resolution->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $this->exceptionReference($lab, $actor, 'FICTICIO no se puede procesar'), 'justification' => 'FICTICIO no se puede procesar']);
        $this->withHeader('Idempotency-Key', 'exception-report-key-1')->postJson("/api/v1/laboratory-orders/$order->id/reports", $this->body($order, $line))->assertConflict()->assertJsonPath('code', 'report_line_unresolved');
        setPermissionsTeamId($lab->id);
        $resolution->decideException($lab, $approver, $order->id, $line->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]);
        $resolution->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line, true), 'exception-report-key-2');
        $this->assertNull($report->clinical_snapshot[0]['validation']);
        $this->assertSame('approved', $report->clinical_snapshot[0]['exception']['decision']['decision']);
        $this->assertSame('FICTICIO no se puede procesar', $report->clinical_snapshot[0]['exception']['request']['reason']);
        $this->assertDatabaseCount('laboratory_order_results', 0);
    }

    public function test_prospective_identity_and_area_are_immutable_and_never_reconstructed_from_current_catalogs(): void
    {
        [$lab,$actor,$unused,$line] = $this->scenario(false);
        $order = app(CreateLaboratoryOrder::class)->execute($lab, $actor, ['branch_id' => $unused->branch_id, 'patient_id' => $unused->patient_id, 'doctor_id' => null, 'commercial_client_id' => null, 'price_list_id' => $unused->price_list_id, 'ordered_at' => now()->format('Y-m-d H:i:s'), 'notes' => null, 'exams' => [['laboratory_exam_id' => $line->laboratory_exam_id]]]);
        $newLine = $order->orderExams()->sole();
        $snapshot = $order->report_identity_snapshot;
        $area = $newLine->report_area_snapshot;
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve']);
        $resolution = app(ClinicalResolutionService::class);
        $r = $resolution->requestException($lab, $actor, $order->id, $newLine->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $this->exceptionReference($lab, $actor, 'FICTICIO'), 'justification' => 'FICTICIO']);
        $resolution->decideException($lab, $approver, $order->id, $newLine->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]);
        $order->patient->update(['first_names' => 'Changed live identity']);
        $order->branch->update(['name' => 'Changed live branch']);
        $newLine->exam->laboratoryArea->update(['name' => 'Changed live area']);
        $body = $this->body($order, $newLine);
        unset($body['identity_confirmation']);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $body, 'prospective-history-report-1');
        $this->assertSame($snapshot['patient'], $report->identity_snapshot['patient']);
        $this->assertSame($snapshot['branch'], $report->identity_snapshot['branch']);
        $this->assertSame($area, $report->clinical_snapshot[0]['area']);
        $this->assertSame('order_creation', $report->identity_snapshot['source']);
        try {
            DB::transaction(fn () => DB::table('laboratory_orders')->where('id', $order->id)->update(['report_identity_snapshot' => null]));
            $this->fail('Identity snapshot is immutable.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_existing_pdf_integrity_failure_prevents_internal_download(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'corrupt-report-integrity-key');
        Storage::disk('clinical_reports')->put($report->private_path, 'corrupt');
        try {
            app(ClinicalReportService::class)->download($lab, $actor, $order->id, $report->id);
            $this->fail('Corrupt PDF cannot be downloaded.');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(ClinicalConflict::class, $e);
            $this->assertSame('report_storage_unavailable', $e->errorCode);
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'order_report.downloaded']);
    }

    public function test_default_dataset_prepares_evidence_and_explicit_pdf_seeder_is_idempotent(): void
    {
        config(['saas.demo_clinical_reason_id' => '']);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->seed(LaboratoryReportIssuanceSeeder::class);
        $this->assertDatabaseCount('laboratory_report_issuances', 2);
        $reports = LaboratoryReportIssuance::orderBy('id')->get();
        $before = DB::table('laboratory_report_issuances')->orderBy('id')->get()->toJson();
        $files = Storage::disk('clinical_reports')->allFiles();
        $this->seed(DatabaseSeeder::class);
        $this->seed(LaboratoryReportIssuanceSeeder::class);
        $this->assertSame($before, DB::table('laboratory_report_issuances')->orderBy('id')->get()->toJson());
        $this->assertSame($files, Storage::disk('clinical_reports')->allFiles());
        foreach ($reports as $report) {
            $this->assertSame($report->pdf_hash, hash('sha256', Storage::disk('clinical_reports')->get($report->private_path)));
            $this->assertCount(2, array_unique(array_column(array_column($report->clinical_snapshot, 'area'), 'name')));
            $professionals = array_filter(array_column(array_column($report->clinical_snapshot, 'validation'), 'professional_snapshot'));
            $this->assertCount(2, array_unique(array_column($professionals, 'professional_name')));
            if (is_dir('/tmp/be091-artifacts')) {
                file_put_contents('/tmp/be091-artifacts/demo-'.$report->type.'.pdf', Storage::disk('clinical_reports')->get($report->private_path));
            }
        }
    }

    public function test_later_publication_never_changes_applied_historical_references(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved(false, ['N' => ['references' => [['key' => 'FICTICIO', 'priority' => 1, 'min' => 1, 'max' => 2]]]]);
        $clinical = app(ClinicalExamService::class);
        $draft = $clinical->fork($lab, $actor, $line->laboratory_exam_id, $line->clinical_version_id);
        $configuration = $draft->configuration;
        $configuration['parameters'][0]['references'][0]['min'] = 100;
        $configuration['parameters'][0]['references'][0]['max'] = 200;
        $draft = $clinical->update($lab, $actor, $line->laboratory_exam_id, $draft->id, $draft->revision, $configuration);
        $clinical->publish($lab, $actor, $line->laboratory_exam_id, $draft->id, $draft->revision);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'historic-reference-report-key');
        $this->assertSame($line->clinical_version_id, $report->clinical_snapshot[0]['clinical_version_id']);
        $reference = $report->clinical_snapshot[0]['validation']['reference_snapshot'][0];
        $this->assertSame('resolved', $reference['status']);
        $this->assertSame(1, $reference['reference']['min']);
        $this->assertSame(2, $reference['reference']['max']);
        $lookup = app(ClinicalReportService::class)->lookup($lab, $actor, $order->id, $report->id);
        foreach (['clinical_snapshot', 'presentation_snapshot', 'identity_snapshot', 'verification_hash', 'private_path'] as $field) {
            $this->assertArrayNotHasKey($field, $lookup->getAttributes());
        }
    }

    public function test_unsupported_glyph_blocks_instead_of_misrepresenting_report_text(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        app(ReportSettingsService::class)->update($lab, $actor, ['revision' => 0, 'header' => '🧪']);
        $this->withHeader('Idempotency-Key', 'unsupported-glyph-report-key')->postJson("/api/v1/laboratory-orders/$order->id/reports", $this->body($order, $line))->assertConflict()->assertJsonPath('code', 'report_unsupported_character');
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->assertCount(0, Storage::disk('clinical_reports')->allFiles());
    }
}
