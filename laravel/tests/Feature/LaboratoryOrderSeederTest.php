<?php

namespace Tests\Feature;

use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalResults\LaboratoryResultService;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryOrderSampleRequirement;
use App\Models\PriceList;
use App\Models\User;
use App\Services\LaboratoryOrders\LaboratoryOrderEconomicCalculator;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataset;
use Database\Seeders\LaboratoryOrderSeeder;
use Database\Seeders\SaasFoundationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\StructuredClinicalReasonFixture;
use Tests\TestCase;

final class LaboratoryOrderSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    public function test_demo_without_global_catalog_uses_local_reasons_and_is_idempotent_without_saas_access(): void
    {
        config(['saas.demo_clinical_reason_id' => null]);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('laboratory_orders', LaboratoryOrderSeeder::DEMO_ORDER_COUNT + 15);
        $this->assertDatabaseCount('laboratory_clinical_reasons', 2);
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 2);
        $requests = DB::table('clinical_exception_requests')->count();
        $this->assertGreaterThan(0, $requests);
        $this->assertDatabaseCount('clinical_exception_local_reason_references', $requests);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'clinical_reason.local_created')->count());
        $this->assertSame(0, DB::table('clinical_exception_local_reason_references as ref')
            ->join('laboratory_clinical_reasons as reason', 'reason.id', '=', 'ref.reason_id')
            ->whereColumn('reason.laboratory_id', '!=', 'ref.laboratory_id')->count());
        foreach (['global_clinical_reasons', 'global_clinical_reason_impacts', 'clinical_reason_adoptions', 'clinical_exception_reason_references', 'saas_capability_assignments', 'saas_audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $before = $this->snapshots();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshots());
    }

    public function test_invalid_explicit_global_catalog_still_fails_atomically(): void
    {
        config(['saas.demo_clinical_reason_id' => 'invalid']);
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Demo clinical exceptions require an explicitly provisioned catalog.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('SAAS_DEMO_CLINICAL_REASON_ID', $e->getMessage());
        }
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertDatabaseCount('saas_capability_assignments', 0);
    }

    private function scenario(string $key): LaboratoryOrder
    {
        $id = DB::table('demo_dataset_records')->where('entity_kind', 'order_scenario')->where('entity_key', $key)->value('entity_id');

        return LaboratoryOrder::findOrFail($id);
    }

    private function snapshots(): array
    {
        $tables = ['clinical_replacement_contexts', 'clinical_replacement_intents', 'clinical_replacement_links', 'clinical_replacement_events', 'clinical_line_evidence', 'clinical_viability_assessments', 'clinical_exception_evidence_reviews', 'laboratory_clinical_reasons', 'laboratory_clinical_reason_versions', 'clinical_exception_local_reason_references', 'local_clinical_reason_reviews', 'local_clinical_reason_resolutions', 'clinical_exception_reason_references', 'clinical_exception_legacy_requests', 'clinical_reason_adoptions', 'clinical_reason_adoption_versions', 'clinical_reason_impact_reviews', 'clinical_reason_impact_resolutions', 'global_clinical_reasons', 'global_clinical_reason_versions', 'global_clinical_reason_impacts', 'saas_capability_assignments', 'saas_audit_logs', 'users', 'plans', 'laboratories', 'branches', 'laboratory_user', 'subscriptions', 'laboratory_areas', 'sample_types', 'laboratory_exams', 'laboratory_exam_versions', 'laboratory_exam_clinical_states', 'patients', 'doctors', 'price_lists', 'price_list_exams', 'commercial_clients', 'commercial_client_price_lists', 'laboratory_orders', 'laboratory_order_exams', 'laboratory_order_sample_requirements', 'audit_logs', 'demo_datasets', 'demo_dataset_records', 'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'laboratory_order_legacy_exams', 'laboratory_samples', 'laboratory_sample_events', 'laboratory_sample_associations', 'laboratory_sample_processing_dependencies', 'laboratory_sample_idempotencies', 'laboratory_order_result_captures', 'laboratory_order_results', 'validation_professionals', 'laboratory_result_validations', 'laboratory_result_rectifications', 'model_has_permissions', 'clinical_exception_requests', 'clinical_exception_decisions', 'clinical_line_resolutions', 'clinical_order_finalizations', 'laboratory_report_settings', 'report_delivery_policies', 'report_delivery_policy_changes', 'report_delivery_exceptions', 'report_delivery_exception_authorizations', 'report_delivery_exception_revocations', 'report_delivery_preparations', 'report_deliveries'];
        $snapshots = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $snapshots[$table] = $rows;
        }

        return $snapshots;
    }

    public function test_complete_dataset_uses_published_configurations_independent_requirements_and_domain_economics(): void
    {
        StructuredClinicalReasonFixture::prepareDemoCatalog();
        $this->seed(DatabaseSeeder::class);
        $orders = LaboratoryOrder::with(['orderExams.sampleRequirements', 'orderExams.clinicalVersion'])->get();
        $this->assertCount(LaboratoryOrderSeeder::DEMO_ORDER_COUNT + 15, $orders);
        $this->assertEqualsCanonicalizing(['pending', 'in_process', 'completed'], $orders->pluck('status')->unique()->values()->all());
        $this->assertDatabaseCount('laboratory_exam_versions', 20);
        $this->assertDatabaseCount('laboratory_order_exams', 74);
        $this->assertDatabaseCount('laboratory_order_sample_requirements', 47);
        $this->assertDatabaseCount('laboratory_order_legacy_exams', 0);
        $lab = Laboratory::where('nit', '000000000001')->sole();
        $calculator = new LaboratoryOrderEconomicCalculator;
        foreach ($orders as $order) {
            $this->assertStringContainsString(DemoDataset::DISCLAIMER, $order->notes);
            $this->assertMatchesRegularExpression('/^ORD-[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $order->code);
            $totals = $calculator->calculate($order, $order->orderExams);
            $this->assertSame($totals->subtotal, $order->subtotal);
            $this->assertSame($totals->discount, $order->discount);
            $this->assertSame($totals->total, $order->total);
            foreach ($order->orderExams as $line) {
                $this->assertSame($order->laboratory_id, $line->laboratory_id);
                $this->assertSame('published_version', $line->clinical_configuration_source);
                $this->assertSame('published', $line->clinicalVersion->status);
                $this->assertStringContainsString(DemoDataset::DISCLAIMER, $line->clinicalVersion->configuration['description']);
                $this->assertSame($line->laboratory_exam_id, $line->clinicalVersion->laboratory_exam_id);
                $this->assertSame($order->laboratory_id, $line->clinicalVersion->laboratory_id);
                foreach ($line->sampleRequirements as $requirement) {
                    $this->assertSame('pending', $requirement->status);
                    $this->assertSame($line->clinical_version_id, $requirement->clinical_version_id);
                    $this->assertSame($line->clinicalVersion->configuration['sample_requirements'][$requirement->position], $requirement->configuration);
                    $this->assertSame($lab->id, DB::table('sample_types')->where('id', $requirement->sample_type_id)->value('laboratory_id'));
                }
            }
        }
        $repeated = $this->scenario('order-07')->orderExams()->where('exam_code', 'QUI001')->with('sampleRequirements')->get();
        $this->assertCount(2, $repeated);
        $this->assertNotSame($repeated[0]->id, $repeated[1]->id);
        $this->assertNotSame($repeated[0]->sampleRequirements[0]->id, $repeated[1]->sampleRequirements[0]->id);
        $this->assertSame('130.00', $this->scenario('order-01')->total);
        $this->assertSame('207.00', $this->scenario('order-02')->total);
        $this->assertSame('0.00', $this->scenario('order-05')->total);
        $this->assertSame('20.00', $this->scenario('order-18')->total);
        $this->assertSame('none', $this->scenario('order-18')->orderExams()->sole()->clinicalVersion->configuration['sample_mode']);
        $this->assertSame(4, LaboratoryOrderSampleRequirement::query()->distinct('sample_type_id')->count('sample_type_id'));
        $this->assertSame(33, AuditLog::where('event', 'order.created')->count());
        $this->assertSame(74, AuditLog::where('event', 'order_exam.added')->count());
        $physicalId = DB::table('demo_dataset_records')->where('entity_kind', 'physical_order_scenario')->where('entity_key', 'samples-v1')->value('entity_id');
        $operational = app(LaboratorySampleService::class)->operational($lab, $physicalId);
        $this->assertSame(['started', 'ready', 'pending_samples', 'ready'], array_column($operational['exams'], 'operational_status'));
        $this->assertSame(['accepted', 'collected', 'received', 'rejected'], array_column($operational['samples'], 'status'));
        $this->assertSame(['satisfied', 'pending'], array_column($operational['exams'][2]['sample_requirements'], 'status'));
        $this->assertSame('100.00', $operational['order']['total']);
        $this->assertDatabaseCount('laboratory_samples', 4);
        $this->assertDatabaseCount('laboratory_sample_associations', 3);
        $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);

    }

    public function test_finalization_dataset_has_explicit_evidence_and_no_artificial_legacy_or_payment(): void
    {
        StructuredClinicalReasonFixture::prepareDemoCatalog();
        $this->seed(DatabaseSeeder::class);
        $lab = Laboratory::where('nit', 'DEMO-BE08-1')->sole();
        $orders = LaboratoryOrder::forLaboratory($lab)->with(['clinicalFinalization', 'orderExams.clinicalResolution'])->get();
        $this->assertCount(8, $orders);
        $closed = $orders->where('status', 'completed');
        $this->assertCount(3, $closed);
        foreach ($closed as $order) {
            $this->assertSame('50.00', $order->total);
            $this->assertCount(2, $order->clinicalFinalization->lines_snapshot);
            $this->assertTrue($order->orderExams->every(fn ($line) => $line->clinicalResolution !== null));
        }
        $this->assertDatabaseCount('clinical_line_resolutions', 14);
        $this->assertDatabaseCount('clinical_exception_requests', 7);
        $this->assertDatabaseCount('clinical_exception_decisions', 6);
        $this->assertDatabaseCount('clinical_order_finalizations', 5);
        $this->assertDatabaseCount('laboratory_order_legacy_exams', 0);
        $before = $this->snapshots();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshots());
    }

    public function test_fictional_result_scenario_is_independent_preliminary_and_not_overwritten_on_reseed(): void
    {
        StructuredClinicalReasonFixture::prepareDemoCatalog();
        $this->seed(DatabaseSeeder::class);
        $lab = Laboratory::where('nit', '000000000001')->sole();
        $id = DB::table('demo_dataset_records')->where('entity_kind', 'result_order_scenario')->where('entity_key', 'results-v1')->value('entity_id');
        $operational = app(LaboratorySampleService::class)->operational($lab, $id);
        $this->assertSame(['empty', 'partial', 'complete'], array_column(array_column($operational['exams'], 'result_capture'), 'status'));
        $this->assertSame('75.00', $operational['order']['total']);
        $this->assertSame('in_process', $operational['order']['status']);
        $this->assertDatabaseCount('laboratory_order_result_captures', 16);
        $this->assertDatabaseCount('laboratory_order_results', 35);
        $actor = User::where('email', 'admin@donqerlab.test')->sole();
        setPermissionsTeamId($lab->id);
        app(LaboratoryResultService::class)->save($lab, $actor, $id, $operational['exams'][1]['id'],
            ['revision' => 1, 'results' => [['parameter_code' => 'NUM', 'value' => '999.125']], 'comment' => 'Manual demo correction']);
        $before = $this->snapshots();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshots());
    }

    public function test_second_run_preserves_every_identity_password_and_operational_edit(): void
    {
        StructuredClinicalReasonFixture::prepareDemoCatalog();
        $this->seed(DatabaseSeeder::class);
        User::where('email', 'admin@donqerlab.test')->firstOrFail()->update(['password' => Hash::make('changed-by-user')]);
        $this->scenario('order-01')->update(['status' => 'cancelled', 'notes' => 'Operational edit']);
        DB::table('price_list_exams')->where('id', DB::table('price_list_exams')->min('id'))->update(['price' => '999.00']);

        $association = DB::table('laboratory_sample_associations')->whereNotIn('id', DB::table('laboratory_sample_processing_dependencies')->select('association_id'))->first();
        $lab = Laboratory::findOrFail($association->laboratory_id);
        $actor = User::where('email', 'admin@donqerlab.test')->firstOrFail();
        setPermissionsTeamId($lab->id);
        app(LaboratorySampleService::class)->release($lab, $actor, $association->laboratory_order_id, $association->laboratory_sample_id, $association->id, ['reason' => 'Manual correction preserved on reseed']);
        $before = $this->snapshots();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshots());
    }

    public function test_foreign_orders_prices_defaults_roles_and_legacy_history_are_untouched(): void
    {
        $migration = require database_path('migrations/2026_10_10_000002_add_order_clinical_composition.php');
        $physical = require database_path('migrations/2026_10_10_000004_add_physical_sample_operations.php');
        $resultsMigration = require database_path('migrations/2026_10_11_000001_create_clinical_order_results.php');
        $validationMigration = require database_path('migrations/2026_10_11_000002_add_clinical_result_validation.php');
        $finalizationMigration = require database_path('migrations/2026_10_11_000003_add_clinical_order_finalization.php');
        $reportMigration = require database_path('migrations/2026_10_11_000004_create_clinical_report_issuances.php');
        $deliveryMigration = require database_path('migrations/2026_10_11_000005_create_report_delivery_controls.php');
        $integration = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $localIntegration = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $replacementEvidence = require database_path('migrations/2026_10_29_000001_add_clinical_replacement_evidence.php');
        $exceptionEvidence = require database_path('migrations/2026_10_29_150442_add_clinical_exception_evidence_reviews.php');
        $reportExceptions = require database_path('migrations/2026_10_29_164459_version_clinical_report_exception_snapshots.php');
        $reportExceptions->down();
        $exceptionEvidence->down();
        $replacementEvidence->down();
        $localIntegration->down();
        $integration->down();
        $deliveryMigration->down();
        $reportMigration->down();
        $finalizationMigration->down();
        $validationMigration->down();
        $resultsMigration->down();
        $physical->down();
        $migration->down();
        $lab = Laboratory::factory()->create();
        $exam = LaboratoryExam::factory()->for($lab)->create();
        $prices = PriceList::factory()->for($lab)->create(['is_default' => true]);
        $order = LaboratoryOrder::factory()->for($lab)->create(['price_list_id' => $prices->id, 'status' => 'completed']);
        $line = LaboratoryOrderExam::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_exam_id' => $exam->id, 'price_list_id' => $prices->id, 'unit_price' => '91.00', 'exam_code' => 'OLD', 'exam_name' => 'Old', 'price_list_name' => 'Old price list']);
        $migration->up();
        $physical->up();
        $resultsMigration->up();
        $validationMigration->up();
        $finalizationMigration->up();
        $reportMigration->up();
        $deliveryMigration->up();
        $integration->up();
        $localIntegration->up();
        $replacementEvidence->up();
        $exceptionEvidence->up();
        $reportExceptions->up();
        setPermissionsTeamId($lab->id);
        $role = Role::create(['name' => 'administrator', 'guard_name' => 'web', 'laboratory_id' => $lab->id]);
        $role->givePermissionTo(Permission::findOrCreate('orders.view', 'web'));
        $legacy = DB::table('laboratory_order_legacy_exams')->get()->toJson();
        $before = [$order->fresh()->getRawOriginal(), $line->fresh()->getRawOriginal(), $prices->fresh()->getRawOriginal(), $role->permissions->pluck('name')->all()];
        StructuredClinicalReasonFixture::prepareDemoCatalog();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($lab->id, getPermissionsTeamId());
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $line->fresh()->getRawOriginal(), $prices->fresh()->getRawOriginal(), $role->fresh()->permissions->pluck('name')->all()]);
        $this->assertSame($legacy, DB::table('laboratory_order_legacy_exams')->get()->toJson());
    }

    public function test_preexisting_unregistered_user_is_rejected_without_password_reset_or_partial_writes(): void
    {
        $user = User::factory()->create(['email' => 'reception@donqerlab.test']);
        $before = $user->fresh()->getRawOriginal();
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Must not adopt a user.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Unregistered preexisting', $exception->getMessage());
        }
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('demo_datasets', 0);
        $this->assertDatabaseCount('demo_dataset_records', 0);
    }

    public function test_preexisting_unregistered_laboratory_is_rejected_atomically(): void
    {
        $lab = Laboratory::factory()->create(['nit' => '000000000001']);
        $before = $lab->fresh()->getRawOriginal();
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Must not adopt a laboratory.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Unregistered preexisting', $exception->getMessage());
        }
        $this->assertSame($before, $lab->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseCount('demo_datasets', 0);
    }

    public function test_production_is_blocked_before_any_write_even_when_forced(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true, '--no-interaction' => true]);
            $this->fail('Production must be blocked.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('restricted to local and testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('demo_datasets', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_late_audit_failure_rolls_back_the_entire_dataset(): void
    {
        $permissionsBefore = DB::table('permissions')->orderBy('id')->get()->all();
        AuditLog::creating(static function (AuditLog $event): void {
            if ($event->event === 'order_exam.added') {
                throw new LogicException('Simulated late audit failure');
            }
        });
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Failure must roll back everything.');
        } catch (LogicException $exception) {
            $this->assertSame('Simulated late audit failure', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        foreach (['users', 'laboratories', 'laboratory_exam_versions', 'laboratory_orders', 'laboratory_order_exams', 'laboratory_order_sample_requirements', 'audit_logs', 'demo_datasets', 'demo_dataset_records', 'roles'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertEquals($permissionsBefore, DB::table('permissions')->orderBy('id')->get()->all());
    }

    public function test_missing_registered_dependency_is_not_recreated(): void
    {
        StructuredClinicalReasonFixture::prepareDemoCatalog();
        $this->seed(DatabaseSeeder::class);
        DB::table('doctors')->where('license_number', 'DEMO-MED-0020')->delete();
        $before = $this->snapshots();
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('A missing identity must not be repaired automatically.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('automatic repair is forbidden', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshots());
    }

    public function test_unregistered_exam_inside_the_demo_laboratory_is_not_adopted_or_published(): void
    {
        $this->seed([UserSeeder::class, SaasFoundationSeeder::class]);
        $lab = Laboratory::where('nit', '000000000001')->sole();
        $exam = LaboratoryExam::factory()->for($lab)->create(['code' => 'HEM001']);
        $before = $this->snapshots();
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('An existing exam must not be adopted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Unregistered preexisting', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshots());
        $this->assertDatabaseMissing('laboratory_exam_clinical_states', ['laboratory_exam_id' => $exam->id]);
        $this->assertDatabaseCount('laboratory_exam_versions', 0);
    }
}
