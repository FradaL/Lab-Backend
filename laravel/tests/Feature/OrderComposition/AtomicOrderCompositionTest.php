<?php

namespace Tests\Feature\OrderComposition;

use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryOrderSampleRequirement;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AtomicOrderCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected Laboratory $lab;

    protected User $actor;

    protected PriceList $prices;

    protected array $header;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lab = Laboratory::factory()->create();
        $this->actor = $this->createLaboratoryMember($this->lab);
        $this->prices = PriceList::factory()->for($this->lab)->create(['currency' => 'GTQ', 'status' => 'active']);
        Subscription::factory()->for($this->lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assignDirectLaboratoryPermissions($this->actor, $this->lab, RbacCatalog::PERMISSIONS);
        $this->actingAs($this->actor)->withHeader('X-Laboratory-ID', (string) $this->lab->id);
        $this->header = [
            'branch_id' => Branch::factory()->for($this->lab)->create(['status' => 'active'])->id,
            'patient_id' => Patient::factory()->for($this->lab)->create(['status' => 'active'])->id,
            'doctor_id' => null, 'commercial_client_id' => null, 'price_list_id' => $this->prices->id,
            'ordered_at' => '2026-10-10 09:00:00', 'notes' => null,
        ];
    }

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    protected function publishedExam(bool $physical = false): array
    {
        $area = LaboratoryArea::factory()->for($this->lab)->create(['status' => 'active']);
        $configuration = ['sample_mode' => $physical ? 'physical' : 'none', 'parameters' => [
            ['code' => 'OBS', 'name' => 'Observation', 'type' => 'text'],
        ]];
        if ($physical) {
            $sample = SampleType::factory()->for($this->lab)->create(['name' => 'Test material', 'status' => 'active']);
            $configuration['sample_requirements'] = [
                ['key' => 'main', 'sample_type_id' => $sample->id, 'volume' => 2.5, 'volume_unit' => 'mL', 'container' => 'Test container', 'instructions' => 'Test instructions'],
                ['key' => 'secondary', 'sample_type_id' => $sample->id],
            ];
        }
        $service = app(ClinicalExamService::class);
        $draft = $service->create($this->lab, $this->actor, [
            'code' => 'C-'.bin2hex(random_bytes(4)), 'name' => 'Clinical test', 'laboratory_area_id' => $area->id,
            'sample_type_id' => null, 'configuration' => $configuration,
        ]);
        $version = $service->publish($this->lab, $this->actor, $draft->laboratory_exam_id, $draft->id, 1);
        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
        $exam->update(['status' => 'active']);
        PriceListExam::factory()->for($this->lab)->create(['price_list_id' => $this->prices->id, 'laboratory_exam_id' => $exam->id, 'price' => '25.00', 'status' => 'active']);

        return [$exam, $version];
    }

    protected function selections(LaboratoryExam $exam, int $count = 1): array
    {
        return array_fill(0, $count, ['laboratory_exam_id' => $exam->id]);
    }

    public function test_omitted_and_empty_exams_return_complete_empty_creation_and_cannot_start(): void
    {
        foreach ([$this->header, $this->header + ['exams' => []]] as $payload) {
            $data = $this->postJson('/api/v1/laboratory-orders', $payload)->assertCreated()
                ->assertJsonPath('data.exams', [])->assertJsonPath('data.total', '0.00')->json('data');
            $this->patchJson('/api/v1/laboratory-orders/'.$data['id'].'/status', ['status' => 'in_process'])
                ->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 0);
        $this->assertSame(['pending'], LaboratoryOrder::pluck('status')->unique()->all());
    }

    public function test_creation_repetitions_fix_versions_and_independent_requirements(): void
    {
        [$physical, $version] = $this->publishedExam(true);
        [$none, $noneVersion] = $this->publishedExam();
        $data = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => [...$this->selections($physical, 2), ...$this->selections($none)]])
            ->assertCreated()->assertJsonPath('data.total', '75.00')->json('data');
        $this->assertCount(3, $data['exams']);
        $this->assertCount(3, array_unique(array_column($data['exams'], 'id')));
        $this->assertSame([$version->id, $version->id, $noneVersion->id], array_column($data['exams'], 'clinical_version_id'));
        $this->assertCount(2, $data['exams'][0]['sample_requirements']);
        $this->assertCount(2, $data['exams'][1]['sample_requirements']);
        $this->assertSame([], $data['exams'][2]['sample_requirements']);
        $this->assertNotSame($data['exams'][0]['sample_requirements'][0]['id'], $data['exams'][1]['sample_requirements'][0]['id']);
        $this->assertSame('Test material', $data['exams'][0]['sample_requirements'][0]['configuration']['sample_type_name']);
        $this->assertSame($version->configuration['sample_requirements'][0], LaboratoryOrderSampleRequirement::orderBy('id')->first()->configuration);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 4);
        $this->assertSame(3, AuditLog::where('event', 'order_exam.added')->count());
        $this->patchJson('/api/v1/laboratory-orders/'.$data['id'].'/status', ['status' => 'in_process'])->assertConflict();
        $this->postJson('/api/v1/laboratory-orders/'.$data['id'].'/exams/'.$data['exams'][2]['id'].'/start')->assertOk();
        $this->patchJson('/api/v1/laboratory-orders/'.$data['id'].'/status', ['status' => 'in_process'])->assertOk();
    }

    public function test_fifty_lines_are_allowed_and_fifty_one_roll_back_before_writing(): void
    {
        [$exam] = $this->publishedExam();
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam, 50)])
            ->assertCreated()->assertJsonCount(50, 'data.exams')->assertJsonPath('data.total', '1250.00');
        $auditCount = AuditLog::count();
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam, 51)])
            ->assertUnprocessable()->assertJsonValidationErrors('exams');
        $this->assertDatabaseCount('laboratory_orders', 1);
        $this->assertDatabaseCount('laboratory_order_exams', 50);
        $this->assertSame($auditCount, AuditLog::count());
    }

    #[DataProvider('invalidExams')]
    public function test_invalid_exam_structure_is_rejected_without_writes(mixed $exams): void
    {
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $exams])->assertUnprocessable();
        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidExams(): array
    {
        return ['empty object' => [(object) []], 'numeric object' => [(object) ['0' => ['laboratory_exam_id' => 1]]], 'null' => [null], 'object' => [['exam' => ['laboratory_exam_id' => 1]]], 'string id' => [[['laboratory_exam_id' => '1']]], 'boolean' => [[['laboratory_exam_id' => true]]], 'unknown field' => [[['laboratory_exam_id' => 1, 'clinical_version_id' => 4]]], 'empty element' => [[[]]], 'scalar' => ['bad']];
    }

    public function test_create_permission_without_add_allows_empty_but_not_embedded_lines(): void
    {
        [$exam] = $this->publishedExam();
        $user = $this->createLaboratoryMember($this->lab);
        $this->assignDirectLaboratoryPermission($user, $this->lab, 'orders.create');
        $this->actingAs($user);
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => []])->assertCreated();
        $auditCount = AuditLog::count();
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])->assertForbidden();
        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertSame($auditCount, AuditLog::count());
        $this->assignDirectLaboratoryPermission($user, $this->lab, 'orders.add_exam');
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])->assertCreated();
    }

    public function test_new_catalog_exam_without_publication_is_not_a_legacy_bypass(): void
    {
        $exam = LaboratoryExam::factory()->for($this->lab)->create(['status' => 'active']);
        PriceListExam::factory()->for($this->lab)->create(['price_list_id' => $this->prices->id, 'laboratory_exam_id' => $exam->id, 'status' => 'active']);
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])
            ->assertConflict()->assertJsonPath('code', 'clinical_exam_unavailable');
        $this->assertDatabaseCount('laboratory_orders', 0);
        $order = LaboratoryOrder::factory()->for($this->lab)->create(['price_list_id' => $this->prices->id, 'currency' => 'GTQ']);
        $this->postJson('/api/v1/laboratory-orders/'.$order->id.'/exams', ['laboratory_exam_id' => $exam->id])->assertConflict();
        $this->assertDatabaseCount('laboratory_order_exams', 0);
    }

    public function test_legacy_cohort_is_fixed_at_migration_and_never_enables_processing(): void
    {
        $migration = require database_path('migrations/2026_10_10_000002_add_order_clinical_composition.php');
        $physical = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $resultsMigration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $resultsMigration->down();
        $physical->down();
        $migration->down();
        $legacy = LaboratoryExam::factory()->for($this->lab)->create(['status' => 'active']);
        $enrolled = LaboratoryExam::factory()->for($this->lab)->create(['status' => 'active']);
        app(ClinicalExamService::class)->fork($this->lab, $this->actor, $enrolled->id, null);
        $migration->up();
        $physical->up();
        $resultsMigration->up();
        $this->assertDatabaseHas('laboratory_order_legacy_exams', ['laboratory_exam_id' => $legacy->id]);
        $this->assertDatabaseMissing('laboratory_order_legacy_exams', ['laboratory_exam_id' => $enrolled->id]);
        PriceListExam::factory()->for($this->lab)->create(['price_list_id' => $this->prices->id, 'laboratory_exam_id' => $legacy->id, 'status' => 'active']);
        $data = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($legacy)])
            ->assertCreated()->assertJsonPath('data.exams.0.clinical_version_id', null)
            ->assertJsonPath('data.exams.0.clinical_configuration_source', 'legacy_compatibility')->json('data');
        $this->patchJson('/api/v1/laboratory-orders/'.$data['id'].'/status', ['status' => 'in_process'])
            ->assertConflict()->assertJsonPath('code', 'clinical_exam_unavailable');
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 0);
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_legacy_exams')->delete());
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_legacy_exams')->insert(['laboratory_id' => $this->lab->id, 'laboratory_exam_id' => $enrolled->id, 'authorized_at' => now()]));
    }

    public function test_add_only_permission_cannot_create_an_embedded_order(): void
    {
        [$exam] = $this->publishedExam();
        $user = $this->createLaboratoryMember($this->lab);
        $this->assignDirectLaboratoryPermission($user, $this->lab, 'orders.add_exam');
        $this->actingAs($user)->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])->assertForbidden();
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    public function test_migration_preserves_actual_historical_lines_without_clinical_backfill(): void
    {
        $migration = require database_path('migrations/2026_10_10_000002_add_order_clinical_composition.php');
        $physical = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $resultsMigration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $resultsMigration->down();
        $physical->down();
        $migration->down();
        $exam = LaboratoryExam::factory()->for($this->lab)->create();
        $order = LaboratoryOrder::factory()->for($this->lab)->create(['price_list_id' => $this->prices->id, 'currency' => 'GTQ', 'status' => 'completed', 'total' => '42.00']);
        $line = LaboratoryOrderExam::create([
            'laboratory_id' => $this->lab->id, 'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id, 'price_list_id' => $this->prices->id,
            'unit_price' => '42.00', 'exam_code' => 'HIST', 'exam_name' => 'Historical name', 'price_list_name' => 'Historical list',
        ]);
        $beforeOrder = $order->refresh()->getRawOriginal();
        $beforeLine = $line->refresh()->getRawOriginal();
        $beforeExam = $exam->refresh()->getRawOriginal();

        $migration->up();
        $physical->up();
        $resultsMigration->up();

        $this->assertSame($beforeOrder, $order->refresh()->getRawOriginal());
        $this->assertSame($beforeExam, $exam->refresh()->getRawOriginal());
        $this->assertSame($beforeLine, array_intersect_key($line->refresh()->getRawOriginal(), $beforeLine));
        $this->assertNull($line->clinical_version_id);
        $this->assertDatabaseCount('laboratory_exam_versions', 0);
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/exams')->assertOk()
            ->assertJsonPath('data.0.clinical_configuration_source', 'historical')->assertJsonPath('data.0.sample_requirements', []);
    }

    public function test_legacy_adoption_uses_publication_for_new_lines_without_rewriting_compatibility_lines(): void
    {
        $migration = require database_path('migrations/2026_10_10_000002_add_order_clinical_composition.php');
        $physical = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $resultsMigration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $resultsMigration->down();
        $physical->down();
        $migration->down();
        $legacy = LaboratoryExam::factory()->for($this->lab)->create(['status' => 'active']);
        $migration->up();
        $physical->up();
        $resultsMigration->up();
        PriceListExam::factory()->for($this->lab)->create(['price_list_id' => $this->prices->id, 'laboratory_exam_id' => $legacy->id, 'status' => 'active']);
        $orderId = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($legacy)])->assertCreated()->json('data.id');
        $old = LaboratoryOrderExam::sole()->getRawOriginal();
        $service = app(ClinicalExamService::class);
        $draft = $service->fork($this->lab, $this->actor, $legacy->id, null);
        $configuration = $draft->configuration + ['sample_mode' => 'none', 'parameters' => [['code' => 'OBS', 'name' => 'Observation', 'type' => 'text']]];
        $draft = $service->update($this->lab, $this->actor, $legacy->id, $draft->id, 1, $configuration);
        $version = $service->publish($this->lab, $this->actor, $legacy->id, $draft->id, $draft->revision);
        $this->postJson('/api/v1/laboratory-orders/'.$orderId.'/exams', ['laboratory_exam_id' => $legacy->id])->assertCreated()
            ->assertJsonPath('data.clinical_version_id', $version->id)->assertJsonPath('data.clinical_configuration_source', 'published_version');
        $this->assertSame($old, LaboratoryOrderExam::findOrFail($old['id'])->getRawOriginal());
    }

    public function test_delete_removes_only_its_pending_requirements_and_audits_origin(): void
    {
        [$exam, $version] = $this->publishedExam(true);
        $data = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam, 2)])->assertCreated()->json('data');
        $first = $data['exams'][0];
        $this->deleteJson('/api/v1/laboratory-orders/'.$data['id'].'/exams/'.$first['id'])->assertNoContent();
        $this->assertDatabaseCount('laboratory_order_exams', 1);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 2);
        $this->assertSame('25.00', LaboratoryOrder::findOrFail($data['id'])->total);
        $event = AuditLog::where('event', 'order_exam.removed')->sole();
        $this->assertSame($version->id, $event->old_values['clinical_version_id']);
        $this->assertSame(array_column($first['sample_requirements'], 'id'), $event->old_values['sample_requirement_ids']);
    }

    public function test_activity_prevents_delete_and_cannot_be_erased_by_sql(): void
    {
        [$exam] = $this->publishedExam(true);
        $data = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])->assertCreated()->json('data');
        $requirement = LaboratoryOrderSampleRequirement::orderBy('id')->firstOrFail();
        $requirement->update(['clinical_activity_started_at' => now()]);
        $this->deleteJson('/api/v1/laboratory-orders/'.$data['id'].'/exams/'.$data['exams'][0]['id'])
            ->assertConflict()->assertJsonPath('code', 'order_exam_has_clinical_activity');
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_sample_requirements')->where('id', $requirement->id)->update(['clinical_activity_started_at' => null]));
        $this->assertDatabaseCount('laboratory_order_exams', 1);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 2);
    }

    public function test_physical_pending_requirements_do_not_satisfy_start(): void
    {
        [$exam] = $this->publishedExam(true);
        $id = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])->assertCreated()->json('data.id');
        $before = AuditLog::count();
        $this->patchJson('/api/v1/laboratory-orders/'.$id.'/status', ['status' => 'in_process'])->assertConflict();
        $this->assertSame('pending', LaboratoryOrder::findOrFail($id)->status);
        $this->assertSame($before, AuditLog::count());
    }

    public function test_later_publication_preserves_line_and_requirement_history(): void
    {
        [$exam, $version] = $this->publishedExam(true);
        $id = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])->assertCreated()->json('data.id');
        $before = $this->getJson('/api/v1/laboratory-orders/'.$id.'/exams')->assertOk()->json('data');
        $service = app(ClinicalExamService::class);
        $next = $service->fork($this->lab, $this->actor, $exam->id, $version->id);
        $configuration = $next->configuration;
        $configuration['sample_requirements'][0]['volume'] = 8;
        $next = $service->update($this->lab, $this->actor, $exam->id, $next->id, 1, $configuration);
        $service->publish($this->lab, $this->actor, $exam->id, $next->id, $next->revision);
        $exam->update(['name' => 'Renamed', 'status' => 'inactive']);
        $this->assertSame($before, $this->getJson('/api/v1/laboratory-orders/'.$id.'/exams')->assertOk()->json('data'));
    }

    public function test_commercial_failure_on_later_line_rolls_back_entire_creation(): void
    {
        [$exam] = $this->publishedExam(true);
        $auditCount = AuditLog::count();
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => [...$this->selections($exam), ['laboratory_exam_id' => PHP_INT_MAX]]])
            ->assertUnprocessable()->assertJsonValidationErrors('exams.1.laboratory_exam_id');
        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 0);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_audit_failure_rolls_back_header_lines_requirements_and_totals(): void
    {
        [$exam] = $this->publishedExam(true);
        $auditCount = AuditLog::count();
        AuditLog::creating(static function (AuditLog $event): void {
            if ($event->event === 'order_exam.added') {
                throw new RuntimeException('audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)]);
            $this->fail('Audit failure must abort creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failure', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 0);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_foreign_exam_is_neutral_and_does_not_leave_header(): void
    {
        $foreign = LaboratoryExam::factory()->orderable()->create();
        $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($foreign)])
            ->assertUnprocessable()->assertJsonValidationErrors('exams.0.laboratory_exam_id');
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    public function test_sql_rejects_draft_foreign_version_null_bypass_and_snapshot_rewrite(): void
    {
        [$exam] = $this->publishedExam(true);
        $data = $this->postJson('/api/v1/laboratory-orders', $this->header + ['exams' => $this->selections($exam)])->assertCreated()->json('data');
        $line = LaboratoryOrderExam::sole();
        $draft = app(ClinicalExamService::class)->fork($this->lab, $this->actor, $exam->id, $line->clinical_version_id);
        $attributes = $line->getRawOriginal();
        unset($attributes['id']);
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_exams')->insert(array_replace($attributes, ['clinical_version_id' => $draft->id])));
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_exams')->insert(array_replace($attributes, ['clinical_version_id' => null, 'clinical_configuration_source' => null])));
        $foreign = LaboratoryExam::factory()->orderable()->create();
        $foreignVersion = DB::table('laboratory_exam_clinical_states')->where('laboratory_exam_id', $foreign->id)->value('current_version_id');
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_exams')->insert(array_replace($attributes, ['clinical_version_id' => $foreignVersion])));
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_exams')->where('id', $line->id)->update(['clinical_version_id' => $draft->id]));
        $this->assertSqlRejected(fn () => DB::table('laboratory_order_sample_requirements')->update(['configuration' => json_encode(['key' => 'tampered'])]));
        $this->assertSame($line->clinical_version_id, LaboratoryOrderExam::sole()->clinical_version_id);
    }

    protected function assertSqlRejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Database must reject invalid clinical state.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->getCode(), ['23503', '23514']);
        }
    }
}
