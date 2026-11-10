<?php

namespace Tests\Feature\ReportDelivery;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalReports\ClinicalReportService;
use App\ClinicalReports\ReportValidityService;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReportValidityTest extends TestCase
{
    use DatabaseMigrations, ReportDeliveryScenario {
        ReportDeliveryScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_report_requires_release_and_suspension_invalidates_an_existing_preparation_even_after_re_release(): void
    {
        [$lab, $actor, $order, $line] = $this->resolved();
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'validity-unreleased-report');
        $delivery = app(ReportDeliveryService::class);
        $this->assertSame('report_release_required', $delivery->eligibility($lab, $actor, $order->id, $report->id)['operational']['reason']);
        $this->releaseReport($lab, $actor, $order, $report);
        $this->recordPayment($lab, $actor, $order, $this->settlementAmount($order), 'validity-payment-001');
        $preparation = $delivery->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'validity-preparation-001');
        $delivery->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        $validity = app(ReportValidityService::class);
        $validity->change($lab, $actor, $order->id, $report->id, ['state' => 'suspended', 'expected_revision' => 1, 'reason' => 'FICTICIO: revisión clínica.'], 'validity-suspend-001');
        $this->assertSame('report_suspended', $delivery->eligibility($lab, $actor, $order->id, $report->id)['operational']['reason']);
        try {
            $delivery->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'validity-deliver-001');
            $this->fail('A suspended report cannot be delivered.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('report_suspended', $error->errorCode);
        }
        $validity->change($lab, $actor, $order->id, $report->id, ['state' => 'released', 'expected_revision' => 2, 'reason' => 'FICTICIO: revisión completada.'], 'validity-release-002');
        try {
            $delivery->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'validity-deliver-002');
            $this->fail('An old preparation cannot recover validity after re-release.');
        } catch (ClinicalConflict $error) {
            $this->assertSame('report_validity_changed', $error->errorCode);
        }
        $this->assertDatabaseCount('report_deliveries', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_report.delivery_blocked']);
    }

    public function test_replacement_blocks_old_report_and_preserves_immutable_validity_history(): void
    {
        [$lab, $actor, $order, $line, $old] = $this->issuedForPolicy('unrestricted');
        $new = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'validity-new-report-001');
        $this->releaseReport($lab, $actor, $order, $new);
        $service = app(ReportValidityService::class);
        $payload = ['state' => 'superseded', 'expected_revision' => 1, 'reason' => 'FICTICIO: reemplazo autorizado.', 'replacement_report_id' => $new->id];
        $first = $service->change($lab, $actor, $order->id, $old->id, $payload, 'validity-replace-001');
        $this->assertSame($first['id'], $service->change($lab, $actor, $order->id, $old->id, $payload, 'validity-replace-001')['id']);
        $this->assertSame('report_superseded', app(ReportDeliveryService::class)->eligibility($lab, $actor, $order->id, $old->id)['operational']['reason']);
        $this->assertTrue(app(ReportDeliveryService::class)->eligibility($lab, $actor, $order->id, $new->id)['eligible']);
        $this->assertCount(2, $service->history($lab, $actor, $order->id, $old->id)['data']);
        $this->expectException(QueryException::class);
        DB::table('report_validity_events')->where('id', $first['id'])->delete();
    }

    public function test_validity_changes_require_permission_and_hide_foreign_reports(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $url = "/api/v1/laboratory-orders/$order->id/reports/$report->id/validity-events";
        $input = ['state' => 'suspended', 'expected_revision' => 1, 'reason' => 'FICTICIO: revisión adicional.'];
        $reader = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reader, $lab, ['reports.view_history']);
        $this->actingAs($reader)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->withHeader('Idempotency-Key', 'validity-denied-001')->postJson($url, $input)->assertForbidden();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $foreign = Laboratory::factory()->create();
        Subscription::factory()->for($foreign)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $foreignUser = $this->createLaboratoryMember($foreign);
        $this->assignDirectLaboratoryPermissions($foreignUser, $foreign, ['reports.issue', 'reports.view_history']);
        setPermissionsTeamId($foreign->id);
        $this->actingAs($foreignUser)->withHeader('X-Laboratory-ID', (string) $foreign->id)
            ->withHeader('Idempotency-Key', 'validity-foreign-001')->postJson($url, $input)->assertNotFound();
        $this->getJson($url)->assertNotFound();
        $this->assertDatabaseCount('report_validity_events', 1);
    }
}
