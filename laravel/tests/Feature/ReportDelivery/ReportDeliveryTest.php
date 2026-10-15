<?php

namespace Tests\Feature\ReportDelivery;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalReports\ClinicalReportService;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\ReportDelivery;
use App\Models\Subscription;
use App\ReportDelivery\CommercialAssessment;
use App\ReportDelivery\CommercialComplianceProvider;
use App\ReportDelivery\ReportCommercialEvaluator;
use App\ReportDelivery\ReportDeliveryPolicyService;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportDeliveryTest extends TestCase
{
    use DatabaseMigrations, ReportDeliveryScenario {
        ReportDeliveryScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_default_is_restrictive_without_persisting_or_inventing_financial_evidence(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $policy = app(ReportDeliveryPolicyService::class)->read($lab, $actor);
        $eligibility = app(ReportDeliveryService::class)->eligibility($lab, $actor, $order->id, $report->id);

        $this->assertSame(['revision' => 0, 'mode' => 'full_payment_required', 'source' => 'conservative_default', 'updated_by' => null, 'updated_at' => null], $policy);
        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('unavailable', $eligibility['evaluation']['assessment']['status']);
        $this->assertSame('not_configured', $eligibility['evaluation']['assessment']['provider']);
        $this->assertDatabaseCount('report_delivery_policies', 0);
        $this->assertDatabaseMissing('laboratory_orders', ['id' => $order->id, 'total' => null]);
    }

    public function test_policy_change_is_revisioned_audited_and_tenant_scoped(): void
    {
        [$lab,$actor] = $this->issued();
        $other = Laboratory::factory()->create();
        $changed = app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO: política de demostración.']);

        $this->assertSame(1, $changed['revision']);
        $this->assertSame('unrestricted', $changed['mode']);
        $this->assertDatabaseHas('report_delivery_policy_changes', ['laboratory_id' => $lab->id, 'previous_revision' => 0, 'previous_mode' => 'full_payment_required', 'new_mode' => 'unrestricted']);
        $this->assertDatabaseMissing('report_delivery_policies', ['laboratory_id' => $other->id]);
        $this->assertDatabaseHas('audit_logs', ['laboratory_id' => $lab->id, 'event' => 'order_report.delivery_policy_changed']);
        $this->expectException(ClinicalConflict::class);
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'payment_or_credit', 'reason' => 'Obsolete']);
    }

    #[DataProvider('commercialCases')]
    public function test_typed_commercial_evaluation_applies_each_policy(string $mode, string $status, string $evidence, bool $eligible): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        if ($mode !== 'full_payment_required') {
            app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => $mode, 'reason' => 'FICTICIO']);
        }
        $this->provider(new CommercialAssessment($status, $evidence, 'test_finance', 'TEST-1', 'v1'));
        $result = app(ReportDeliveryService::class)->eligibility($lab, $actor, $order->id, $report->id);

        $this->assertSame($eligible, $result['eligible']);
        $this->assertSame($status, $result['evaluation']['assessment']['status']);
        $this->assertSame($evidence, $result['evaluation']['assessment']['evidence_type']);
    }

    public static function commercialCases(): array
    {
        return [
            'full paid' => ['full_payment_required', 'satisfied', 'full_payment', true],
            'full rejects credit' => ['full_payment_required', 'satisfied', 'authorized_credit', false],
            'credit policy paid' => ['payment_or_credit', 'satisfied', 'full_payment', true],
            'credit policy credit' => ['payment_or_credit', 'satisfied', 'authorized_credit', true],
            'known unpaid' => ['full_payment_required', 'not_satisfied', 'none', false],
            'provider unavailable' => ['payment_or_credit', 'unavailable', 'none', false],
        ];
    }

    public function test_exception_preparation_download_and_confirmed_delivery_are_separate_and_financially_inert(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $before = $order->fresh()->only(['subtotal', 'discount', 'taxes', 'total', 'status']);
        $pdfHash = $report->pdf_hash;
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'prepare-exception-0001');
        $this->assertDatabaseCount('report_deliveries', 0);
        [$downloadedReport,$downloaded,$bytes] = $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($report->id, $downloadedReport->id);
        $this->assertNotNull($downloaded->downloaded_at);
        $this->assertDatabaseCount('report_deliveries', 0);
        $delivery = $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true, 'notes' => 'FICTICIO: entrega presencial confirmada.'], 'confirm-exception-0001');

        $this->assertSame('printed_document', $delivery->channel);
        $this->assertSame('patient', $delivery->recipient_snapshot['type']);
        $this->assertSame($authorized['authorization']['id'], $delivery->exception_authorization_id);
        $this->assertDatabaseHas('report_delivery_exceptions', ['id' => $authorized['exception']['id'], 'status' => 'consumed', 'revision' => 3]);
        $this->assertSame($before, $order->fresh()->only(array_keys($before)));
        $this->assertSame($pdfHash, $report->fresh()->pdf_hash);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.delivered', 'auditable_id' => $delivery->id]);
    }

    public function test_internal_download_and_prepared_copy_do_not_claim_delivery(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO']);
        app(ClinicalReportService::class)->download($lab, $actor, $order->id, $report->id);
        $preparation = app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'prepare-no-delivery-01');
        app(ReportDeliveryService::class)->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);

        $this->assertDatabaseCount('report_deliveries', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.downloaded']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.delivery_copy_downloaded']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'order_report.delivered']);
    }

    public function test_manual_digital_and_multiple_legitimate_deliveries_are_independent_and_idempotent(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO']);
        $service = app(ReportDeliveryService::class);
        foreach (['printed_document', 'manual_digital_file'] as $index => $channel) {
            $key = 'multi-preparation-000'.$index;
            $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient($channel), $key);
            $this->assertSame($preparation->id, $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient($channel), $key)->id);
            $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
            $deliveryKey = 'multi-confirmation-000'.$index;
            $delivery = $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], $deliveryKey);
            $this->assertSame($delivery->id, $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], $deliveryKey)->id);
        }
        $this->assertDatabaseCount('report_deliveries', 2);
        $this->assertSame(['manual_digital_file', 'printed_document'], ReportDelivery::pluck('channel')->sort()->values()->all());
    }

    #[DataProvider('invalidRecipients')]
    public function test_recipient_authorization_must_be_explicit_and_compatible(array $change): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO']);
        $this->expectException(ValidationException::class);
        app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id, array_replace($this->recipient(), $change), 'invalid-recipient-key-1');
    }

    public static function invalidRecipients(): array
    {
        return [
            'unconfirmed' => [['authorization_confirmed' => false]],
            'wrong patient basis' => [['authorization_basis' => 'legal_authority_verified']],
            'client patient name' => [['recipient_name' => 'Injected name']],
            'representative without name' => [['recipient_type' => 'legal_representative', 'authorization_basis' => 'legal_authority_verified']],
            'empty evidence' => [['authorization_evidence' => '   ']],
        ];
    }

    public function test_unused_authorization_can_be_revoked_but_never_used_afterwards(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $revoked = app(ReportDeliveryService::class)->revokeException($lab, $actor, $order->id, $report->id, $authorized['exception']['id'], ['revision' => 2, 'reason' => 'FICTICIO: autorización retirada antes de uso.']);
        $this->assertSame('revoked', $revoked['status']);
        $this->assertDatabaseHas('report_delivery_exception_revocations', ['exception_id' => $revoked['id']]);
        $this->expectException(ClinicalConflict::class);
        app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id, $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'revoked-preparation-1');
    }

    public function test_exception_authorization_requires_its_sensitive_permission(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $exception = app(ReportDeliveryService::class)->requestException($lab, $actor, $order->id, $report->id, ['reason' => 'FICTICIO']);
        $unauthorized = $this->createLaboratoryMember($lab);
        $this->expectException(AuthorizationException::class);
        app(ReportDeliveryService::class)->authorizeException($lab, $unauthorized, $order->id, $report->id, $exception['id'], ['revision' => 1]);
    }

    public function test_expired_preparation_and_client_financial_flags_are_rejected(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO']);
        $service = app(ReportDeliveryService::class);
        try {
            $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(extra: ['payment_satisfied' => true]), 'client-payment-flag');
            $this->fail('Client financial flags must never be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('input', $exception->errors());
        }
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'expiring-preparation-key');
        $this->travel(16)->minutes();
        try {
            $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
            $this->fail('Expired preparations cannot provide a delivery copy.');
        } catch (ClinicalConflict $exception) {
            $this->assertSame('report_delivery_preparation_expired', $exception->errorCode);
        }
        $this->assertDatabaseCount('report_deliveries', 0);
    }

    public function test_postgresql_rejects_policy_state_without_matching_history(): void
    {
        [$lab,$actor] = $this->issued();
        try {
            DB::transaction(fn () => DB::table('report_delivery_policies')->insert([
                'laboratory_id' => $lab->id, 'revision' => 1, 'mode' => 'unrestricted',
                'updated_by' => $actor->id, 'updated_at' => now(),
            ]));
            $this->fail('A policy without immutable history must be rejected.');
        } catch (QueryException|\PDOException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('report_delivery_policies', 0);
    }

    public function test_down_refuses_existing_commercial_history(): void
    {
        [$lab,$actor] = $this->issued();
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO']);
        $migration = require database_path('migrations/2026_10_11_000005_create_report_delivery_controls.php');
        try {
            $migration->down();
            $this->fail('Commercial history cannot be dropped ordinarily.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('backup/restoration', $exception->getMessage());
        }
        $this->assertDatabaseCount('report_delivery_policy_changes', 1);
    }

    public function test_cross_tenant_resources_are_hidden_and_permissions_are_independent(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $outsider = $this->createLaboratoryMember($lab);
        $this->actingAs($outsider)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/delivery-eligibility")->assertForbidden();
        $foreign = Laboratory::factory()->create();
        $foreignUser = $this->createLaboratoryMember($foreign);
        Subscription::factory()->for($foreign)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assignDirectLaboratoryPermissions($foreignUser, $foreign, ['reports.delivery.evaluate', 'reports.delivery_history.view']);
        setPermissionsTeamId($foreign->id);
        $this->actingAs($foreignUser)->withHeader('X-Laboratory-ID', (string) $foreign->id)
            ->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/delivery-eligibility")->assertNotFound();
        $this->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/deliveries")->assertNotFound();
    }

    public function test_commercial_endpoints_never_expose_clinical_values_or_public_access(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $response = $this->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/delivery-eligibility")->assertOk();
        $this->assertStringNotContainsString('12345678901234567890', $response->getContent());
        $this->assertStringNotContainsString('clinical_snapshot', $response->getContent());
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/laboratory-orders/$order->id/reports/$report->id/deliveries")->assertUnauthorized();
    }

    public function test_delivery_history_and_report_evidence_are_immutable_in_postgresql(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO']);
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'immutable-prep-key-1');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        $delivery = $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'immutable-delivery-1');
        foreach (["UPDATE report_deliveries SET notes='changed' WHERE id=$delivery->id", "DELETE FROM report_deliveries WHERE id=$delivery->id"] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Delivery history must be immutable.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
        $this->assertSame($report->pdf_hash, $report->fresh()->pdf_hash);
        $this->assertSame(hash('sha256', Storage::disk('clinical_reports')->get($report->private_path)), $report->pdf_hash);
    }

    public function test_audit_failure_rolls_back_delivery_and_exception_consumption(): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'rollback-preparation-1');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        DB::unprepared("DROP TRIGGER IF EXISTS fail_delivery_audit ON audit_logs; CREATE OR REPLACE FUNCTION fail_delivery_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.event='order_report.delivered' THEN RAISE EXCEPTION 'audit failure'; END IF; RETURN NEW; END \$\$; CREATE TRIGGER fail_delivery_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_delivery_audit();");
        try {
            $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'rollback-delivery-01');
            $this->fail('Delivery must roll back when its audit fails.');
        } catch (QueryException) {
            $this->assertDatabaseCount('report_deliveries', 0);
            $this->assertDatabaseHas('report_delivery_exceptions', ['id' => $authorized['exception']['id'], 'status' => 'authorized', 'revision' => 2]);
        }
    }

    private function provider(CommercialAssessment $assessment): void
    {
        $this->app->instance(CommercialComplianceProvider::class, new class($assessment) implements CommercialComplianceProvider
        {
            public function __construct(private CommercialAssessment $assessment) {}

            public function assess(Laboratory $laboratory, LaboratoryOrder $order): CommercialAssessment
            {
                return $this->assessment;
            }
        });
        $this->app->forgetInstance(ReportCommercialEvaluator::class);
        $this->app->forgetInstance(ReportDeliveryService::class);
    }
}
