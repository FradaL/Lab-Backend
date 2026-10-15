<?php

namespace Tests\Feature\ClinicalReports;

use App\Authorization\RbacCatalog;
use App\ClinicalReports\ClinicalReportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalReportIntegrityTest extends TestCase
{
    use ClinicalReportScenario, DatabaseMigrations {
        ClinicalReportScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_sql_cannot_insert_a_changed_validated_value(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $r = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'sql-snapshot-test-key-01');
        $row = $this->copy($r->id);
        $clinical = json_decode($row['clinical_snapshot'], true);
        $clinical[0]['validation']['results_snapshot'][0]['value'] = '0';
        $row['clinical_snapshot'] = json_encode($clinical);
        try {
            DB::transaction(fn () => DB::table('laboratory_report_issuances')->insert($row));
            $this->fail('SQL must reject fabricated results.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
    }

    public function test_deferred_trigger_requires_all_report_lines_before_commit(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $r = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'sql-lines-test-key-01');
        try {
            DB::transaction(fn () => DB::table('laboratory_report_issuances')->insert($this->copy($r->id)));
            $this->fail('Commit without exact report lines must fail.');
        } catch (\PDOException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
        $this->assertCount(1, Storage::disk('clinical_reports')->allFiles());
    }

    public function test_down_refuses_existing_report_history(): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'sql-down-test-key-01');
        $deliveryMigration = require database_path('migrations/2026_10_11_000005_create_report_delivery_controls.php');
        $deliveryMigration->down();
        $migration = require database_path('migrations/2026_10_11_000004_create_clinical_report_issuances.php');
        try {
            $migration->down();
            $this->fail('Existing report history cannot be dropped ordinarily.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('backup/restoration', $e->getMessage());
        }
        $this->assertDatabaseCount('laboratory_report_issuances', 1);
    }

    public function test_no_settings_or_issuance_permission_is_automatically_assigned_to_roles(): void
    {
        foreach (RbacCatalog::rolePermissions() as $permissions) {
            $this->assertEmpty(array_intersect(['reports.issue', 'reports.download', 'reports.view_history', 'reports.settings.view', 'reports.settings.edit'], $permissions));
        }
    }

    private function copy(int $id): array
    {
        $row = (array) DB::table('laboratory_report_issuances')->where('id', $id)->first();
        unset($row['id']);
        $row['number'] = 'REP-'.Str::ulid();
        $row['sequence']++;
        $row['idempotency_hash'] = hash('sha256', random_bytes(32));
        $row['verification_hash'] = hash('sha256', random_bytes(32));
        $row['private_path'] = 'reports/'.$row['laboratory_id'].'/'.bin2hex(random_bytes(32)).'.pdf';

        return $row;
    }
}
