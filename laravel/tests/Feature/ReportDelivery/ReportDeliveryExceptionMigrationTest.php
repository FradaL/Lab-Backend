<?php

namespace Tests\Feature\ReportDelivery;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalReports\ReportValidityService;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReportDeliveryExceptionMigrationTest extends TestCase
{
    use DatabaseMigrations, ReportDeliveryScenario {
        ReportDeliveryScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    protected function releaseReport($lab, $actor, $order, $report): void
    {
        // This fixture deliberately starts before report validity controls exist.
    }

    public function runDatabaseMigrations(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => ! in_array(basename($path), ['2026_11_10_000001_bind_delivery_exceptions_to_preparations.php', '2026_11_10_000002_add_report_validity_controls.php'], true)));
        $this->artisan('migrate:fresh', ['--path' => $paths, '--realpath' => true])->assertSuccessful();
        Storage::fake('clinical_reports');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    public function test_upgrade_preserves_legacy_authorization_and_preparation_without_inventing_validity(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $service = app(ReportDeliveryService::class);
        $exception = $service->requestException($lab, $actor, $order->id, $report->id, ['reason' => 'FICTICIO: excepción anterior a BE09.']);
        // Reconstruct a pre-control row under the current schema without rewriting history.
        DB::statement('ALTER TABLE report_delivery_preparations DROP CONSTRAINT delivery_preparation_fifteen_minutes');
        DB::statement('ALTER TABLE report_delivery_preparations DISABLE TRIGGER USER');
        [$authorizationId, $preparationId] = DB::transaction(function () use ($lab, $actor, $order, $report, $exception) {
            $authorizationId = DB::table('report_delivery_exception_authorizations')->insertGetId([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'report_id' => $report->id,
                'exception_id' => $exception['id'], 'policy_revision' => $exception['policy_revision'],
                'policy_mode' => $exception['policy_mode'], 'evaluation_snapshot' => json_encode($exception['evaluation']),
                'authorized_by' => $actor->id, 'authorized_at' => now(),
            ]);
            DB::table('report_delivery_exceptions')->where('id', $exception['id'])->update(['revision' => 2, 'status' => 'authorized']);
            $preparationId = DB::table('report_delivery_preparations')->insertGetId([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'report_id' => $report->id,
                'idempotency_hash' => hash('sha256', 'FICTICIO: preparación histórica'),
                'request_hash' => hash('sha256', 'FICTICIO: solicitud histórica'),
                'channel' => 'printed_document', 'recipient_snapshot' => json_encode(['type' => 'patient', 'name' => 'FICTICIO']),
                'policy_revision' => $exception['policy_revision'], 'policy_mode' => $exception['policy_mode'],
                'evaluation_snapshot' => json_encode($exception['evaluation']), 'exception_authorization_id' => $authorizationId,
                'prepared_by' => $actor->id, 'prepared_at' => now(), 'expires_at' => now()->addMinutes(30),
            ]);

            return [$authorizationId, $preparationId];
        });
        DB::statement('ALTER TABLE report_delivery_preparations ENABLE TRIGGER USER');
        $authorizationBefore = (array) DB::table('report_delivery_exception_authorizations')->where('id', $authorizationId)->first();
        $preparationBefore = (array) DB::table('report_delivery_preparations')->where('id', $preparationId)->first();
        $exceptionBefore = (array) DB::table('report_delivery_exceptions')->where('id', $exception['id'])->first();

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(ReportValidityService::class)->change($lab, $actor, $order->id, $report->id,
            ['state' => 'released', 'expected_revision' => 0, 'reason' => 'FICTICIO: liberación posterior a migración.'], 'release-legacy-report-001');

        $this->assertSame($authorizationBefore + ['expires_at' => null], (array) DB::table('report_delivery_exception_authorizations')->where('id', $authorizationId)->first());
        $this->assertSame($preparationBefore + ['validity_revision' => null], (array) DB::table('report_delivery_preparations')->where('id', $preparationId)->first());
        $this->assertSame($exceptionBefore, (array) DB::table('report_delivery_exceptions')->where('id', $exception['id'])->first());
        $this->assertDatabaseCount('report_delivery_exception_bindings', 0);
        $this->assertFalse($service->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        foreach ([
            'report_delivery_exception_expired' => fn () => $service->prepare($lab, $actor, $order->id, $report->id,
                $this->recipient(extra: ['exception_authorization_id' => $authorizationId]), 'exception-legacy-new-prep'),
            'report_delivery_declaration_required' => fn () => $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparationId),
        ] as $expected => $operation) {
            try {
                $operation();
                $this->fail('La historia sin plazo verificable no debe habilitar entrega.');
            } catch (ClinicalConflict $conflict) {
                $this->assertSame($expected, $conflict->errorCode);
            }
        }
        $this->assertDatabaseCount('report_deliveries', 0);
    }
}
