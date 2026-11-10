<?php

namespace Tests\Feature\ReportDelivery;

use App\Cash\AuthorizeOrderCredit;
use App\Cash\RecordOrderRefund;
use App\Cash\VoidOrderPayment;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReports\ClinicalReportService;
use App\ClinicalReports\PrivateReportStorage;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReportDeliveryOperationTest extends TestCase
{
    use DatabaseMigrations, ReportDeliveryScenario {
        ReportDeliveryScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_missing_declaration_returns_422_and_preserves_immutable_block_audit(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $this->withHeader('Idempotency-Key', 'missing-declaration-key-01')
            ->postJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/delivery-preparations", $this->recipient(extra: ['authorization_confirmed' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('authorization_confirmed');

        $this->assertDatabaseCount('report_delivery_preparations', 0);
        $audit = DB::table('audit_logs')->where('event', 'order_report.delivery_blocked')->sole();
        $this->assertSame('invalid_declaration_or_input', json_decode($audit->metadata, true)['reason']);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $audit->id)->delete();
    }

    public function test_internal_download_records_its_purpose_without_settling_or_delivering(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $this->get("/api/v1/laboratory-orders/$order->id/reports/$report->id/download")
            ->assertOk()->assertHeader('X-Report-Purpose', 'internal_use');

        $this->assertDatabaseCount('report_delivery_preparations', 0);
        $this->assertDatabaseCount('report_deliveries', 0);
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
        setPermissionsTeamId($lab->id);
        $this->assertFalse(app(ReportDeliveryService::class)->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        $audit = DB::table('audit_logs')->where('event', 'order_report.downloaded')->sole();
        $this->assertSame('internal_use', json_decode($audit->metadata, true)['purpose']);
    }

    #[DataProvider('sensitiveSteps')]
    public function test_another_employee_requires_a_new_declaration_at_each_step(string $step): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'employee-preparation-key');
        if ($step === 'deliver') {
            $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        }
        $other = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($other, $lab, ['reports.delivery.prepare', 'reports.delivery.confirm', 'reports.download']);
        $this->actingAs($other);
        $url = "/api/v1/laboratory-orders/$order->id/reports/$report->id";
        $response = $step === 'download'
            ? $this->getJson("$url/delivery-preparations/$preparation->id/download")
            : $this->withHeader('Idempotency-Key', 'employee-confirmation-key')->postJson("$url/deliveries", ['preparation_id' => $preparation->id, 'delivery_confirmed' => true]);

        $response->assertConflict()->assertJsonPath('code', 'report_delivery_declaration_required');
        $this->assertDatabaseCount('report_deliveries', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.delivery_blocked', 'user_id' => $other->id]);
    }

    public static function sensitiveSteps(): array
    {
        return [['download'], ['deliver']];
    }

    public function test_exception_deadline_starts_at_authorization_and_cannot_be_renewed(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $this->freezeTime();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $deadline = now()->addMinutes(15);
        $this->travel(10)->minutes();
        $service = app(ReportDeliveryService::class);
        $input = $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exclusive-preparation-key');
        $this->assertTrue($deadline->equalTo($preparation->expires_at));
        $this->assertSame($preparation->id, $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exclusive-preparation-key')->id);
        try {
            $service->prepare($lab, $actor, $order->id, $report->id, $input, 'another-preparation-key');
            $this->fail('One authorization must reserve exactly one preparation.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('report_delivery_exception_unavailable', $error->errorCode);
        }
        $this->travelTo($deadline);
        $this->assertFalse($service->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        $this->expectException(ClinicalConflict::class);
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
    }

    #[DataProvider('sensitiveSteps')]
    public function test_clinical_change_blocks_old_partial_and_allows_current_complete(string $step): void
    {
        [$lab, $actor, $order, $line, $report] = $this->issuedForPolicy('unrestricted');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'clinical-preparation-key');
        if ($step === 'deliver') {
            $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        }
        app(ClinicalResolutionService::class)->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        $eligibility = $service->eligibility($lab, $actor, $order->id, $report->id);
        $this->assertTrue($eligibility['evaluation']['eligible']);
        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('report_clinical_evidence_changed', $eligibility['operational']['reason']);
        try {
            $step === 'download'
                ? $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id)
                : $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'clinical-confirmation-key');
            $this->fail('Earlier clinical evidence cannot enable handoff.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('report_clinical_evidence_changed', $error->errorCode);
        }
        $complete = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line, true), 'current-complete-report-key');
        $prepared = $service->prepare($lab, $actor, $order->id, $complete->id, $this->recipient(), 'current-complete-prep-key');
        $service->downloadPreparation($lab, $actor, $order->id, $complete->id, $prepared->id);
        $delivery = $service->deliver($lab, $actor, $order->id, $complete->id, ['preparation_id' => $prepared->id, 'delivery_confirmed' => true], 'current-complete-delivery-key');
        $this->assertSame($complete->id, $delivery->report_id);
        $this->assertDatabaseCount('report_deliveries', 1);
    }

    public function test_financial_change_during_file_read_prevents_download_and_is_audited(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $preparation = app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'read-race-preparation-key');
        $change = fn () => $this->recordPayment($lab, $actor, $order, '0.01', 'read-race-payment-key');
        $this->app->instance(PrivateReportStorage::class, new class($change) extends PrivateReportStorage
        {
            private int $reads = 0;

            public function __construct(private \Closure $change) {}

            public function read(string $path, string $hash): string
            {
                $bytes = parent::read($path, $hash);
                if (++$this->reads === 2) {
                    ($this->change)();
                }

                return $bytes;
            }
        });
        try {
            app(ReportDeliveryService::class)->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
            $this->fail('Revalidate after reading private storage.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('report_delivery_evidence_changed', $error->errorCode);
        }
        $this->assertNull($preparation->fresh()->downloaded_at);
        $this->assertDatabaseCount('report_deliveries', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.delivery_blocked']);
    }

    public function test_voided_payment_invalidates_downloaded_preparation_and_sql_rejects_stale_evidence(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $payment = $this->recordPayment($lab, $actor, $order, '40.00', 'void-race-payment-key');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'void-race-preparation-key');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        app(VoidOrderPayment::class)->execute($lab, $actor, $order->id, $payment['body']['data']['resource']['id'], ['expected_revision' => $payment['body']['data']['financial_revision'], 'reason' => 'FICTICIO: pago anulado.'], 'void-payment-operation-key');
        try {
            $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'void-race-delivery-key');
            $this->fail('A voided payment cannot allow handoff.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('report_delivery_evidence_changed', $error->errorCode);
        }
        $row = DB::table('report_delivery_preparations')->where('id', $preparation->id)->first();
        unset($row->id);
        $row->idempotency_hash = hash('sha256', 'sql-stale-evidence-key');
        $row->downloaded_at = null;
        $row->downloaded_by = null;
        $this->expectException(QueryException::class);
        DB::table('report_delivery_preparations')->insert((array) $row);
    }

    public function test_refund_changes_financial_revision_even_when_full_payment_remains(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $payment = $this->recordPayment($lab, $actor, $order, '50.00', 'refund-delivery-payment-key');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'refund-delivery-prep-key');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        app(RecordOrderRefund::class)->execute($lab, $actor, $order->id, ['expected_revision' => $payment['body']['data']['financial_revision'], 'amount' => '10.00', 'method' => 'cash', 'reason' => 'FICTICIO: devolución de excedente.'], 'refund-delivery-operation-key');

        $this->assertTrue($service->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        $this->expectException(ClinicalConflict::class);
        $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'refund-delivery-confirm-key');
    }

    public function test_revoked_credit_blocks_a_prepared_delivery(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('payment_or_credit');
        $revision = (int) DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->value('revision');
        $credit = app(AuthorizeOrderCredit::class)->authorize($lab, $actor, $order->id, ['expected_revision' => $revision, 'amount' => '40.00', 'reason' => 'FICTICIO: crédito.'], 'credit-delivery-authorize-key');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'credit-delivery-prep-key');
        app(AuthorizeOrderCredit::class)->revoke($lab, $actor, $order->id, $credit['body']['data']['resource']['id'], ['expected_revision' => $credit['body']['data']['financial_revision'], 'reason' => 'FICTICIO: retiro de crédito.'], 'credit-delivery-revoke-key');

        $this->assertFalse($service->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        $this->expectException(ClinicalConflict::class);
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
    }

    public function test_download_permission_is_required_independently_of_preparation(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'download-permission-prep-key');
        $actor->revokePermissionTo('reports.download');

        $this->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/delivery-preparations/$preparation->id/download")->assertForbidden();
        $this->assertNull($preparation->fresh()->downloaded_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.delivery_blocked', 'user_id' => $actor->id]);
    }

    public function test_prepare_permission_denial_returns_403_with_a_single_block_event(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $actor->revokePermissionTo('reports.delivery.prepare');

        $this->withHeader('Idempotency-Key', 'denied-preparation-key')
            ->postJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/delivery-preparations", $this->recipient())->assertForbidden();
        $this->assertDatabaseCount('report_delivery_preparations', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'order_report.delivery_blocked')->where('user_id', $actor->id)->count());
    }

    public function test_postgresql_rejects_a_copy_without_manual_confirmation(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $preparation = app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'sql-declaration-prep-key');
        $row = (array) DB::table('report_delivery_preparations')->where('id', $preparation->id)->first();
        unset($row['id']);
        $recipient = json_decode($row['recipient_snapshot'], true);
        unset($recipient['authorization_confirmed']);
        $row['recipient_snapshot'] = json_encode($recipient, JSON_THROW_ON_ERROR);
        $row['idempotency_hash'] = hash('sha256', 'sql-missing-confirmation');

        $this->expectException(QueryException::class);
        DB::table('report_delivery_preparations')->insert($row);
    }

    public function test_confirmed_operation_replays_history_after_clinical_evidence_changes(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'history-preparation-key');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        $input = ['preparation_id' => $preparation->id, 'delivery_confirmed' => true];
        $delivery = $service->deliver($lab, $actor, $order->id, $report->id, $input, 'history-delivery-key');
        app(ClinicalResolutionService::class)->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);

        $this->assertSame($preparation->id, $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'history-preparation-key')->id);
        $this->assertSame($delivery->id, $service->deliver($lab, $actor, $order->id, $report->id, $input, 'history-delivery-key')->id);
        $this->assertFalse($service->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        $this->assertDatabaseCount('report_deliveries', 1);
        $this->assertDatabaseCount('report_delivery_preparations', 1);
    }

    public function test_corrupt_document_after_download_blocks_confirmation(): void
    {
        [$lab, $actor, $order, , $report] = $this->issuedForPolicy('unrestricted');
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'integrity-preparation-key');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        Storage::disk('clinical_reports')->put($report->private_path, 'corrupt');

        $this->withHeader('Idempotency-Key', 'integrity-confirmation-key')
            ->postJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/deliveries", ['preparation_id' => $preparation->id, 'delivery_confirmed' => true])->assertStatus(503);
        $this->assertDatabaseCount('report_deliveries', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.delivery_blocked']);
    }
}
