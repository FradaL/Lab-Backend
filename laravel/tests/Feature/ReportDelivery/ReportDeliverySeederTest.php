<?php

namespace Tests\Feature\ReportDelivery;

use App\Models\Laboratory;
use App\Models\LaboratoryReportIssuance;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaboratoryReportDeliverySeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

final class ReportDeliverySeederTest extends TestCase
{
    use DatabaseMigrations, ReportDeliveryScenario {
        ReportDeliveryScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_demo_evidence_and_explicit_deliveries_are_complete_and_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $free = Laboratory::where('nit', 'DEMO-BE091-1')->sole();
        $restricted = Laboratory::where('nit', 'DEMO-BE092-1')->sole();
        $this->assertDatabaseHas('report_delivery_policies', ['laboratory_id' => $free->id, 'mode' => 'unrestricted', 'revision' => 1]);
        $this->assertDatabaseHas('report_delivery_policies', ['laboratory_id' => $restricted->id, 'mode' => 'payment_or_credit', 'revision' => 1]);
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->seed(LaboratoryReportDeliverySeeder::class);

        $this->assertDatabaseCount('laboratory_report_issuances', 4);
        $this->assertDatabaseCount('report_delivery_preparations', 3);
        $this->assertDatabaseCount('report_deliveries', 3);
        $this->assertDatabaseCount('report_delivery_exceptions', 2);
        $this->assertDatabaseCount('report_delivery_exception_authorizations', 2);
        $this->assertDatabaseHas('report_delivery_exceptions', ['laboratory_id' => $restricted->id, 'status' => 'consumed']);
        $this->assertDatabaseHas('report_delivery_exceptions', ['laboratory_id' => $restricted->id, 'status' => 'authorized']);
        $this->assertSame(['manual_digital_file', 'printed_document', 'printed_document'], DB::table('report_deliveries')->orderBy('channel')->pluck('channel')->all());
        $this->assertSame(2, DB::table('laboratory_report_issuances as r')->leftJoin('report_deliveries as d', 'd.report_id', '=', 'r.id')->whereNull('d.id')->count());
        $this->assertCount(4, Storage::disk('clinical_reports')->allFiles());
        foreach (LaboratoryReportIssuance::all() as $report) {
            $this->assertSame($report->pdf_hash, hash('sha256', Storage::disk('clinical_reports')->get($report->private_path)));
        }

        $before = $this->snapshot();
        $files = Storage::disk('clinical_reports')->allFiles();
        $this->seed(DatabaseSeeder::class);
        $this->seed(LaboratoryReportDeliverySeeder::class);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($files, Storage::disk('clinical_reports')->allFiles());
    }

    public function test_explicit_delivery_dataset_is_blocked_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->artisan('db:seed', ['--class' => LaboratoryReportDeliverySeeder::class, '--force' => true, '--no-interaction' => true]);
            $this->fail('Production must be blocked.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local/testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->assertDatabaseCount('report_deliveries', 0);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $entry) {
            $rows = DB::table($entry->tablename)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $snapshot[$entry->tablename] = $rows;
        }

        return $snapshot;
    }
}
