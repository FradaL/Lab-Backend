<?php

namespace Tests\Feature\ClinicalFinalization;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\Models\ClinicalOrderFinalization;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClinicalHistoricalResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_historical_and_legacy_lines_and_only_explicit_exception_can_resolve_them(): void
    {
        $migrations = [];
        foreach (['2026_10_10_000002_add_order_clinical_composition', '2026_10_10_000004_add_physical_sample_operations', '2026_10_11_000001_create_clinical_order_results', '2026_10_11_000002_add_clinical_result_validation', '2026_10_11_000003_add_clinical_order_finalization', '2026_10_11_000004_create_clinical_report_issuances', '2026_10_11_000005_create_report_delivery_controls'] as $file) {
            $migrations[] = require database_path('migrations/'.$file.'.php');
        }
        foreach (array_reverse($migrations) as $m) {
            $m->down();
        }
        $lab = Laboratory::factory()->create();
        $actor = $this->createLaboratoryMember($lab);
        $reviewer = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($actor, $lab, RbacCatalog::PERMISSIONS);
        $this->assignDirectLaboratoryPermissions($reviewer, $lab, ['orders.clinical_exceptions.approve']);
        setPermissionsTeamId($lab->id);
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);
        $exam = LaboratoryExam::factory()->for($lab)->create(['status' => 'active']);
        $prices = PriceList::factory()->for($lab)->create(['currency' => 'GTQ', 'status' => 'active']);
        PriceListExam::factory()->for($lab)->create(['laboratory_exam_id' => $exam->id, 'price_list_id' => $prices->id, 'status' => 'active', 'price' => '25.00']);
        $order = LaboratoryOrder::factory()->for($lab)->create(['status' => 'pending', 'currency' => 'GTQ', 'price_list_id' => $prices->id]);
        $historical = LaboratoryOrderExam::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_exam_id' => $exam->id, 'price_list_id' => $prices->id, 'unit_price' => '25.00', 'exam_code' => $exam->code, 'exam_name' => $exam->name, 'price_list_name' => $prices->name]);
        $before = $historical->fresh()->getRawOriginal();
        $oldOrder = $order->fresh()->getRawOriginal();
        foreach ($migrations as $m) {
            $m->up();
        }
        $this->assertSame($before, array_intersect_key($historical->fresh()->getRawOriginal(), $before));
        $this->assertSame($oldOrder, array_intersect_key($order->fresh()->getRawOriginal(), $oldOrder));
        $legacy = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $exam->id, $actor);
        $this->assertNull($legacy->clinical_version_id);
        $this->assertSame('legacy_compatibility', $legacy->clinical_configuration_source);
        $cohort = DB::table('laboratory_order_legacy_exams')->orderBy('laboratory_exam_id')->get()->toJson();
        $service = app(ClinicalResolutionService::class);
        foreach ([$historical->fresh(), $legacy] as $line) {
            $this->postJson("/api/v1/laboratory-orders/$order->id/exams/$line->id/finalize", ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0])->assertConflict()->assertJsonPath('code', 'clinical_line_not_validated');
            setPermissionsTeamId($lab->id);
            $request = $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason' => 'FICTICIO resolución histórica explícita']);
            $service->decideException($lab, $reviewer, $order->id, $line->id, $request['exception']['id'], true, ['clinical_revision' => $request['clinical_revision'], 'request_revision' => 1]);
            $this->assertNull($line->fresh()->clinical_version_id);
            $this->assertNull($line->fresh()->processing_started_at);
        }
        $service->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($cohort, DB::table('laboratory_order_legacy_exams')->orderBy('laboratory_exam_id')->get()->toJson());
        foreach (['laboratory_exam_versions', 'laboratory_order_results', 'laboratory_result_validations', 'laboratory_order_sample_requirements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $snapshot = ClinicalOrderFinalization::sole()->lines_snapshot;
        $this->assertSame(['historical', 'legacy_compatibility'], array_column($snapshot, 'clinical_configuration_source'));
        $this->assertSame(['authorized_exception', 'authorized_exception'], array_column($snapshot, 'resolution_type'));
    }
}
