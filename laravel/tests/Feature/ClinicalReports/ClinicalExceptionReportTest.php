<?php

namespace Tests\Feature\ClinicalReports;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalExams\LocalClinicalReasonManager;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ClinicalReportService;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\ClinicalExceptionDecision;
use App\Models\ClinicalExceptionRequest;
use App\Models\ClinicalLineResolution;
use App\Models\LaboratoryReportIssuance;
use App\Models\User;
use App\ReportDelivery\ReportDeliveryService;
use Com\Tecnick\Pdf\Parser\Parser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ClinicalFinalization\ClinicalExceptionScenario;
use Tests\TestCase;

class ClinicalExceptionReportTest extends TestCase
{
    use ClinicalExceptionScenario, ClinicalReportScenario, DatabaseMigrations {
        ClinicalReportScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    private function resolvedWithPending(): array
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        $pending = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $actor);
        app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '12345678901234567890.123456789'], ['parameter_code' => 'T', 'value' => 'FICTICIO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        app(ClinicalResolutionService::class)->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);

        return [$lab, $actor, $order, $line, $pending];
    }

    private function approved(string $source = 'global'): array
    {
        $s = $this->prepared();
        if ($source === 'local') {
            setPermissionsTeamId($s['lab']->id);
            $this->assignLaboratoryRole($s['actor'], $s['lab'], 'owner');
            $data = app(LocalClinicalReasonManager::class)->create($s['lab'], $s['actor'], ['code' => 'report_refusal', 'definition' => ['name' => 'FICTICIO motivo original local', 'description' => 'PRIVATE-DEFINITION', 'classification' => 'terminal_candidate', 'evidence_requirements' => ['schema_version' => 1, 'requirements' => ['patient_refusal']], 'is_active' => true, 'change_reason' => 'FICTICIO']])['data'];
            $s['version'] = $data['version'];
            $s['reference'] = ['source' => 'local', 'reason_id' => $data['reason']->id, 'version_id' => $data['version']->id];
        }
        $this->evidence($s, 'patient_refusal', ['facts' => ['express_refusal' => true, 'observations' => 'PRIVATE-REFUSAL']]);
        setPermissionsTeamId($s['lab']->id);
        $service = app(ClinicalResolutionService::class);
        $created = $service->requestException($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, array_replace($this->requestBody($s), ['justification' => 'PRIVATE-REQUEST']));
        $service->decideException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $created['exception']['id'], true, ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1, 'reason' => 'PRIVATE-APPROVAL']);
        $service->finalizeOrder($s['lab'], $s['actor'], $s['order']->id, ['clinical_revision' => $s['order']->fresh()->clinical_revision]);

        return $s;
    }

    private function issue(array $s, string $key): LaboratoryReportIssuance
    {
        setPermissionsTeamId($s['lab']->id);

        return app(ClinicalReportService::class)->issue($s['lab'], $s['actor'], $s['order']->id, $this->body($s['order'], $s['line'], true), $key);
    }

    private function export(LaboratoryReportIssuance $report, string $name, array $contains, array $absent): void
    {
        $pdf = Storage::disk('clinical_reports')->get($report->private_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame($report->pdf_hash, hash('sha256', $pdf));
        $this->assertNotEmpty((new Parser)->parse($pdf));
        $path = getenv('BE_EXC05_ARTIFACT_DIR');
        if ($path !== false && is_dir($path)) {
            file_put_contents($path.'/'.$name.'.pdf', $pdf);
            file_put_contents($path.'/'.$name.'.expected.json', json_encode(compact('contains', 'absent'), JSON_THROW_ON_ERROR));
        }
    }

    public static function origins(): array
    {
        return [['global'], ['local']];
    }

    #[DataProvider('origins')]
    public function test_not_performed_preserves_original_reference_and_private_evidence_is_not_patient_projection(string $source): void
    {
        $s = $this->approved($source);
        $report = $this->issue($s, 'exc05-original-'.$source);
        $snapshot = $report->clinical_snapshot[0];
        $this->assertSame('donqer-clinical-2', $report->format_version);
        $this->assertSame('not_performed', $snapshot['clinical_display']['status']);
        $this->assertSame($s['version']->name, $snapshot['clinical_display']['reason']);
        $this->assertSame($source, $snapshot['exception']['reason_reference']['source']);
        $this->assertSame($s['reference']['version_id'], $snapshot['exception']['reason_reference']['version_id']);
        $this->assertSame(1, $snapshot['exception']['reason_reference']['version_number']);
        $this->assertNotNull($snapshot['exception']['evidence_review_id']);
        $this->assertNull($snapshot['validation']);
        $this->assertSame([], $report->pending_snapshot);
        $this->assertDatabaseCount('laboratory_order_results', 0);
        $this->export($report, 'not-performed-'.$source, ['No realizado', $s['version']->name], ['PRIVATE-APPROVAL', 'PRIVATE-REQUEST', 'PRIVATE-REFUSAL', 'PRIVATE-DEFINITION', 'Validación 1']);
    }

    #[DataProvider('origins')]
    public function test_new_catalog_version_or_deactivation_cannot_rewrite_archived_or_reissued_reason(string $source): void
    {
        $s = $this->approved($source);
        $a = $this->issue($s, 'exc05-before-'.$source);
        $oldBytes = Storage::disk('clinical_reports')->get($a->private_path);
        $definition = $s['version']->only(['name', 'description', 'classification', 'evidence_requirements', 'is_active', 'change_reason']);
        $definition['name'] = 'PRIVATE-NEW-VERSION';
        $definition['is_active'] = false;
        $store = app(ClinicalReasonVersionStore::class);
        if ($source === 'global') {
            $store->appendGlobal($s['reference']['reason_id'], User::findOrFail($s['version']->created_by), 1, $definition);
        } else {
            $store->appendLocal($s['lab'], $s['reference']['reason_id'], $s['actor'], 1, $definition);
        }
        $b = $this->issue($s, 'exc05-after-'.$source);
        $this->assertSame($a->clinical_snapshot, $b->clinical_snapshot);
        $this->assertSame($oldBytes, Storage::disk('clinical_reports')->get($a->private_path));
        $this->assertSame($a->clinical_snapshot, $a->fresh()->clinical_snapshot);
        $this->export($b, 'reissued-'.$source, ['No realizado', $s['version']->name], ['PRIVATE-NEW-VERSION', 'PRIVATE-APPROVAL']);
    }

    public function test_legacy_issuance_bytes_snapshots_and_idempotency_survive_the_prospective_format_migration(): void
    {
        $s = $this->approved();
        $migration = require database_path('migrations/2026_10_29_164459_version_clinical_report_exception_snapshots.php');
        $migration->down();
        $old = $this->issue($s, 'exc05-legacy-issuance');
        $before = $old->fresh()->getRawOriginal();
        $lineBefore = DB::table('laboratory_report_issuance_lines')->where('report_id', $old->id)->first();
        $bytes = Storage::disk('clinical_reports')->get($old->private_path);
        $migration->up();
        $this->assertSame($before, array_intersect_key($old->fresh()->getRawOriginal(), $before));
        $this->assertSame((array) $lineBefore, (array) DB::table('laboratory_report_issuance_lines')->where('report_id', $old->id)->first());
        $retry = $this->issue($s, 'exc05-legacy-issuance');
        $this->assertSame($old->id, $retry->id);
        $this->assertSame('donqer-clinical-1', $retry->format_version);
        $this->assertNull($retry->pending_snapshot);
        $this->assertSame($bytes, Storage::disk('clinical_reports')->get($retry->private_path));
        $this->assertArrayNotHasKey('clinical_display', $old->clinical_snapshot[0]);
        $new = $this->issue($s, 'exc05-prospective-issuance');
        $this->assertSame('not_performed', $new->clinical_snapshot[0]['clinical_display']['status']);
        $this->export($old, 'archived-v1', ['EXCEPCIÓN CLÍNICA AUTORIZADA', 'PRIVATE-APPROVAL'], ['No realizado']);
        $this->export($new, 'prospective-v2', ['No realizado'], ['PRIVATE-APPROVAL']);
    }

    public function test_mixed_report_keeps_validated_values_and_exception_without_fabricated_results(): void
    {
        [$lab, $actor, $order, $line, $pending] = $this->resolvedWithPending();
        $ref = $this->exceptionReference($lab, $actor, 'FICTICIO motivo mixto');
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve']);
        $resolution = app(ClinicalResolutionService::class);
        $request = $resolution->requestException($lab, $actor, $order->id, $pending->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $ref, 'justification' => 'PRIVATE-REQUEST']);
        $resolution->decideException($lab, $approver, $order->id, $pending->id, $request['exception']['id'], true, ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1, 'reason' => 'PRIVATE-APPROVAL']);
        $resolution->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line, true), 'exc05-mixed-report');
        $this->assertSame(['validated_result', 'not_performed'], array_column(array_column($report->clinical_snapshot, 'clinical_display'), 'status'));
        $this->assertDatabaseCount('laboratory_order_results', 4);
        $this->export($report, 'mixed', ['No realizado', '12345678901234567890.123456789', 'Validación'], ['PRIVATE-APPROVAL', 'PRIVATE-REQUEST']);
    }

    public static function processing(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('processing')]
    public function test_incomplete_is_only_informational_and_unvalidated_values_cannot_be_issued(bool $capture): void
    {
        [$lab, $actor, $order, $line, $pending] = $this->resolvedWithPending();
        app(ClinicalReplacementService::class)->recordEvidence($lab, $actor, $order->id, $pending->id, ['expected_revision' => 0, 'occurred_at' => now()->toISOString(), 'kind' => 'follow_up', 'facts' => ['action' => 'PRIVATE-CONTACT', 'observations' => 'PRIVATE-FOLLOW-UP']]);
        if ($capture) {
            app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $pending->id);
            app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $pending->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '9988776655'], ['parameter_code' => 'T', 'value' => 'HIDDEN77']]]);
        }
        $before = $pending->fresh()->processing_started_at;
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'exc05-incomplete-'.($capture ? 'started' : 'not-started'));
        $this->assertCount(1, $report->clinical_snapshot);
        $this->assertSame('incomplete', $report->pending_snapshot[0]['clinical_status']);
        $this->assertSame($capture, $report->pending_snapshot[0]['processing_started']);
        $this->assertSame($before?->toISOString(), $pending->fresh()->processing_started_at?->toISOString());
        $this->assertDatabaseCount('clinical_line_resolutions', 1);
        $this->assertDatabaseCount('clinical_order_finalizations', 0);
        $response = $this->getJson("/api/v1/laboratory-orders/$order->id/clinical-finalization")->assertOk();
        $preview = collect($response->json('data.lines'))->firstWhere('id', $pending->id)['reporting'];
        $this->assertFalse($preview['eligible_line']);
        $this->assertSame('incomplete', $preview['status']);
        $this->export($report, 'incomplete-'.($capture ? 'started' : 'not-started'), ['Incompleto', $capture ? 'Procesamiento iniciado' : 'El procesamiento no ha comenzado'], ['9988776655', 'HIDDEN77', 'PRIVATE-FOLLOW-UP', 'PRIVATE-CONTACT', 'No realizado']);
        setPermissionsTeamId($lab->id);
        try {
            app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $pending), 'exc05-reject-incomplete-'.(int) $capture);
            $this->fail('Pending incidence must not be a reportable clinical line.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('report_line_unresolved', $e->errorCode);
        }
        try {
            app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line, true), 'exc05-reject-complete-'.(int) $capture);
            $this->fail('A pending incidence cannot allow complete issuance.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('report_finalization_required', $e->errorCode);
        }
    }

    public function test_sql_cannot_forge_patient_projection_and_new_report_history_is_immutable(): void
    {
        $s = $this->approved();
        $report = $this->issue($s, 'exc05-integrity-original');
        $row = (array) DB::table('laboratory_report_issuances')->where('id', $report->id)->first();
        unset($row['id']);
        $row['number'] = 'REP-'.Str::ulid();
        $row['sequence']++;
        $row['idempotency_hash'] = hash('sha256', random_bytes(32));
        $row['verification_hash'] = hash('sha256', random_bytes(32));
        $row['private_path'] = 'reports/'.$s['lab']->id.'/'.bin2hex(random_bytes(32)).'.pdf';
        $snapshot = json_decode($row['clinical_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        $snapshot[0]['clinical_display']['reason'] = 'PRIVATE-APPROVAL';
        $row['clinical_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
        try {
            DB::transaction(fn () => DB::table('laboratory_report_issuances')->insert($row));
            $this->fail('Patient projection must match exact historical evidence.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $migration = require database_path('migrations/2026_10_29_164459_version_clinical_report_exception_snapshots.php');
        try {
            $migration->down();
            $this->fail('Versioned report history cannot be removed.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('backup/restoration', $e->getMessage());
        }
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
    }

    public function test_real_concurrent_incidence_change_during_render_rejects_stale_pending_snapshot_and_cleans_pdf(): void
    {
        [$lab, $actor, $order, $line, $pending] = $this->resolvedWithPending();
        $revision = $order->fresh()->clinical_revision;
        $directory = sys_get_temp_dir().'/exc05-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                setPermissionsTeamId($lab->id);
                $deadline = microtime(true) + 20;
                while (! file_exists($directory.'/ready') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($directory.'/ready')) {
                    throw new \RuntimeException('Missing render signal.');
                }
                app(ClinicalReplacementService::class)->recordEvidence($lab, $actor, $order->id, $pending->id, ['expected_revision' => 0, 'occurred_at' => now()->toISOString(), 'kind' => 'follow_up', 'facts' => ['action' => 'FICTICIO contacto', 'observations' => 'PRIVATE-FOLLOW-UP']]);
                file_put_contents($directory.'/done', 'ok');
                DB::disconnect();
                exit(0);
            } catch (\Throwable $e) {
                file_put_contents($directory.'/done', $e::class);
                exit(1);
            }
        }
        DB::reconnect();
        $renderer = new class($directory) extends ClinicalReportRenderer
        {
            public function __construct(private string $directory) {}

            public function render(array $document, string $verificationUrl): string
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Rendering must not hold a row transaction.');
                }
                touch($this->directory.'/ready');
                $deadline = microtime(true) + 20;
                while (! file_exists($this->directory.'/done') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($this->directory.'/done') || file_get_contents($this->directory.'/done') !== 'ok') {
                    throw new \RuntimeException('The independent clinical writer did not commit.');
                }

                return parent::render($document, $verificationUrl);
            }
        };
        app()->instance(ClinicalReportRenderer::class, $renderer);
        try {
            app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'exc05-pending-render-race');
            $this->fail('The PDF must match the pending state observed at commit.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('report_evidence_conflict', $e->errorCode);
        } finally {
            touch($directory.'/ready');
            pcntl_waitpid($pid, $exit);
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
        $this->assertSame(0, pcntl_wexitstatus($exit));
        $this->assertSame($revision, $order->fresh()->clinical_revision);
        $this->assertDatabaseCount('clinical_line_evidence', 1);
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->assertSame([], Storage::disk('clinical_reports')->allFiles());
    }

    public function test_clinical_exception_does_not_authorize_financial_delivery_or_download_and_tenant_access(): void
    {
        $s = $this->approved();
        $report = $this->issue($s, 'exc05-delivery-separation');
        $orderBefore = $s['order']->fresh()->getRawOriginal();
        $delivery = app(ReportDeliveryService::class);
        $eligibility = $delivery->eligibility($s['lab'], $s['actor'], $s['order']->id, $report->id);
        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('unavailable', $eligibility['evaluation']['assessment']['status']);
        try {
            $delivery->prepare($s['lab'], $s['actor'], $s['order']->id, $report->id, ['channel' => 'printed_document', 'recipient_type' => 'patient', 'authorization_basis' => 'identity_verified', 'authorization_evidence' => 'FICTICIO: identidad verificada', 'authorization_confirmed' => true], 'exc05-delivery-blocked');
            $this->fail('A clinical exception must not bypass commercial release.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('report_delivery_not_eligible', $e->errorCode);
        }
        $this->assertSame($orderBefore, $s['order']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('report_deliveries', 0);
        $this->assertDatabaseCount('report_delivery_preparations', 0);
        $this->getJson("/api/v1/laboratory-orders/{$s['order']->id}/clinical-finalization")->assertOk()->assertJsonPath('data.lines.0.reporting.status', 'not_performed')->assertJsonPath('data.lines.0.reporting.reason_reference.version_id', $s['reference']['version_id']);
        setPermissionsTeamId($s['lab']->id);
        $s['actor']->revokePermissionTo('reports.download');
        $this->get("/api/v1/laboratory-orders/{$s['order']->id}/reports/$report->id/download")->assertForbidden();
        setPermissionsTeamId($s['lab']->id);
        $s['actor']->revokePermissionTo('reports.issue');
        $this->withHeader('Idempotency-Key', 'exc05-permission-denied')->postJson("/api/v1/laboratory-orders/{$s['order']->id}/reports", $this->body($s['order'], $s['line'], true))->assertForbidden();
        $this->replacementScenario();
        $this->getJson("/api/v1/laboratory-orders/{$s['order']->id}/reports/$report->id")->assertNotFound();
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
    }

    public function test_generic_historical_exception_is_not_reclassified_from_current_catalogue_text(): void
    {
        $s = $this->prepared();
        $reportMigration = require database_path('migrations/2026_10_29_164459_version_clinical_report_exception_snapshots.php');
        $evidenceMigration = require database_path('migrations/2026_10_29_150442_add_clinical_exception_evidence_reviews.php');
        $localMigration = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $globalMigration = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $reportMigration->down();
        $evidenceMigration->down();
        $localMigration->down();
        $globalMigration->down();
        $request = ClinicalExceptionRequest::create(['laboratory_id' => $s['lab']->id, 'laboratory_order_id' => $s['order']->id, 'laboratory_order_exam_id' => $s['line']->id, 'reason' => $s['version']->name, 'requested_by' => $s['actor']->id, 'requested_at' => now(), 'capture_revision' => 0, 'processing_started_at' => null, 'clinical_configuration_source' => $s['line']->clinical_configuration_source]);
        $decision = ClinicalExceptionDecision::create(['laboratory_id' => $s['lab']->id, 'laboratory_order_id' => $s['order']->id, 'laboratory_order_exam_id' => $s['line']->id, 'exception_request_id' => $request->id, 'decision' => 'approved', 'decided_by' => $s['approver']->id, 'decided_at' => now(), 'evidence_snapshot' => ['synthetic' => true]]);
        ClinicalLineResolution::create(['laboratory_id' => $s['lab']->id, 'laboratory_order_id' => $s['order']->id, 'laboratory_order_exam_id' => $s['line']->id, 'exception_decision_id' => $decision->id, 'resolution_type' => 'authorized_exception', 'resolved_by' => $s['approver']->id, 'resolved_at' => now(), 'evidence_snapshot' => ['synthetic' => true]]);
        app(ClinicalResolutionService::class)->finalizeOrder($s['lab'], $s['actor'], $s['order']->id, ['clinical_revision' => $s['order']->fresh()->clinical_revision]);
        DB::transaction(function () use ($globalMigration, $localMigration, $evidenceMigration, $reportMigration): void {
            $globalMigration->up();
            $localMigration->up();
            $evidenceMigration->up();
            $reportMigration->up();
        });
        $report = $this->issue($s, 'exc05-generic-history');
        $this->assertSame('authorized_exception', $report->clinical_snapshot[0]['clinical_display']['status']);
        $this->assertNull($report->clinical_snapshot[0]['exception']['reason_reference']);
        $this->assertDatabaseCount('clinical_exception_reason_references', 0);
        $this->export($report, 'generic-history', ['Excepción clínica autorizada'], ['No realizado']);
    }

    public function test_sql_cannot_replace_informational_pending_exams_with_terminal_or_fabricated_content(): void
    {
        [$lab, $actor, $order, $line] = $this->resolvedWithPending();
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'exc05-pending-integrity');
        $row = (array) DB::table('laboratory_report_issuances')->where('id', $report->id)->first();
        unset($row['id']);
        $row['number'] = 'REP-'.Str::ulid();
        $row['sequence']++;
        $row['idempotency_hash'] = hash('sha256', random_bytes(32));
        $row['verification_hash'] = hash('sha256', random_bytes(32));
        $row['private_path'] = 'reports/'.$lab->id.'/'.bin2hex(random_bytes(32)).'.pdf';
        $pending = json_decode($row['pending_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        $pending[0]['clinical_status'] = 'not_performed';
        $pending[0]['result'] = 'FAKE-VALUE';
        $row['pending_snapshot'] = json_encode($pending, JSON_THROW_ON_ERROR);
        try {
            DB::transaction(fn () => DB::table('laboratory_report_issuances')->insert($row));
            $this->fail('Pending identities cannot contain fabricated clinical results.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
    }

    public function test_openapi_matches_report_format_and_clinical_line_preview_contract(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['donqer-clinical-1', 'donqer-clinical-2'], $document['components']['schemas']['ReportMetadata']['properties']['format_version']['enum']);
        $this->assertArrayHasKey('pending_line_count', $document['components']['schemas']['ReportMetadata']['properties']);
        $this->assertArrayHasKey('ReportClinicalLineState', $document['components']['schemas']);
        $this->assertArrayHasKey('ReportLineSnapshotV2', $document['components']['schemas']);
        $this->assertArrayHasKey('ReportPendingSnapshotLine', $document['components']['schemas']);
    }

    public function test_validated_but_not_finalized_exam_is_pending_and_is_not_inferred_to_be_incomplete(): void
    {
        [$lab, $actor, $order, $line, $pending] = $this->resolvedWithPending();
        app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $pending->id);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $pending->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '9988776655'], ['parameter_code' => 'T', 'value' => 'HIDDEN77'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $pending->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'exc05-validation-without-resolution');
        $this->assertSame('pending', $report->pending_snapshot[0]['clinical_status']);
        $this->assertDatabaseCount('clinical_line_resolutions', 1);
        $this->export($report, 'validated-pending-finalization', ['Pendiente de resolución clínica'], ['Incompleto', '9988776655', 'HIDDEN77', 'No realizado']);
        try {
            app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $pending), 'exc05-validation-still-not-reportable');
            $this->fail('Validation alone must not replace explicit line finalization.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('report_line_unresolved', $e->errorCode);
        }
    }
}
