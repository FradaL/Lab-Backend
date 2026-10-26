<?php

namespace Tests\Feature\ClinicalReasons;

use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\ClinicalReasonEligibilityPolicy;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\ClinicalExams\GlobalClinicalReasonImpactManager;
use App\ClinicalExams\LocalClinicalReasonManager;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\Models\AuditLog;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\LocalClinicalReasonReview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class ClinicalReasonIntegralAuditTest extends TestCase
{
    use ClinicalResultScenario, RefreshDatabase;

    private function definition(): array
    {
        return ['name' => 'FICTICIO: auditoría integral', 'description' => 'FICTICIO', 'classification' => 'terminal_candidate', 'evidence_requirements' => ['schema_version' => 1, 'requirements' => []], 'is_active' => true, 'change_reason' => 'FICTICIO'];
    }

    private function local(Laboratory $lab, User $actor, string $code = 'ficticio'): array
    {
        setPermissionsTeamId($lab->id);
        $this->assignLaboratoryRole($actor, $lab, 'owner');

        return app(LocalClinicalReasonManager::class)->create($lab, $actor, ['code' => $code, 'definition' => $this->definition()])['data'];
    }

    public function test_privileged_multilaboratory_actor_cannot_transplant_private_ids_across_the_selected_context(): void
    {
        [$a, $actor, $orderA, $lineA] = $this->scenario(false);
        $this->local($a, $actor);
        [$b, $other, $orderB, $lineB] = $this->scenario(false);
        $localB = $this->local($b, $other);
        $reference = ['source' => 'local', 'reason_id' => $localB['reason']->id, 'version_id' => $localB['version']->id];
        $created = app(ClinicalResolutionService::class)->requestException($b, $other, $orderB->id, $lineB->id, ['clinical_revision' => $orderB->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO']);
        $manager = app(LocalClinicalReasonManager::class);
        $manager->publish($b, $other, $localB['reason']->id, ['expected_number' => 1, 'definition' => array_replace($this->definition(), ['classification' => 'incidence_only'])]);
        $review = LocalClinicalReasonReview::sole();
        $manager->resolve($b, $other, $localB['reason']->id, $review->id, ['expected_revision' => 0, 'outcome' => 'non_favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
        $globalB = $this->exceptionReference($b, $other);
        $actor->laboratories()->attach($b, ['is_active' => true]);
        $this->assignDirectLaboratoryPermissions($actor, $b, RbacCatalog::PERMISSIONS);
        $this->assignLaboratoryRole($actor, $b, 'owner');
        $before = ['versions' => LaboratoryClinicalReasonVersion::count(), 'audits' => AuditLog::count(), 'reference' => ClinicalExceptionLocalReasonReference::sole()->getRawOriginal()];
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $a->id);
        $path = '/api/v1/laboratory/clinical-reasons/local/'.$localB['reason']->id;
        foreach (['', '/versions', '/events', '/reviews', '/reviews/'.$review->id.'/resolutions'] as $suffix) {
            $this->getJson($path.$suffix)->assertNotFound();
        }
        $this->postJson($path.'/versions', ['expected_number' => 2, 'definition' => $this->definition()])->assertNotFound();
        $this->postJson($path.'/activation', ['expected_number' => 2, 'is_active' => false, 'change_reason' => 'FICTICIO'])->assertNotFound();
        $this->postJson($path.'/reviews/'.$review->id.'/resolutions', ['expected_revision' => 1, 'outcome' => 'favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO'])->assertNotFound();
        $this->getJson('/api/v1/laboratory/clinical-reason-adoptions/'.$globalB['adoption_id'].'/versions')->assertNotFound();
        $this->postJson('/api/v1/laboratory/clinical-reason-adoptions/'.$globalB['adoption_id'].'/versions', ['expected_revision' => 1, 'version_id' => $globalB['version_id'], 'justification' => 'FICTICIO'])->assertNotFound();
        $clinicalB = "/api/v1/laboratory-orders/$orderB->id/exams/$lineB->id/exceptions";
        $this->getJson($clinicalB)->assertNotFound();
        $this->postJson($clinicalB.'/'.$created['exception']['id'].'/approve', ['clinical_revision' => $orderB->fresh()->clinical_revision, 'request_revision' => 1])->assertNotFound();
        foreach ([$reference, $globalB] as $foreign) {
            $this->postJson("/api/v1/laboratory-orders/$orderA->id/exams/$lineA->id/exceptions", ['clinical_revision' => $orderA->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $foreign, 'justification' => 'FICTICIO'])->assertNotFound();
        }
        $this->getJson('/api/v1/laboratory/clinical-reasons')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.laboratory_id', $a->id);
        $this->assertSame($before['versions'], LaboratoryClinicalReasonVersion::count());
        $this->assertSame($before['audits'], AuditLog::count());
        $this->assertSame($before['reference'], ClinicalExceptionLocalReasonReference::sole()->getRawOriginal());
        $this->assertDatabaseCount('clinical_exception_requests', 1);
        $this->assertDatabaseCount('clinical_exception_decisions', 0);
        $this->assertDatabaseCount('local_clinical_reason_resolutions', 1);
    }

    public function test_postgresql_composite_keys_reject_a_foreign_parent_even_when_the_actor_belongs_to_both_labs(): void
    {
        [$a, $actor] = $this->scenario(false);
        [$b, $other] = $this->scenario(false);
        $localB = $this->local($b, $other);
        $actor->laboratories()->attach($b, ['is_active' => true]);
        $row = $this->definition() + ['laboratory_id' => $a->id, 'reason_id' => $localB['reason']->id, 'number' => 2, 'created_by' => $actor->id];
        $row['evidence_requirements'] = json_encode($row['evidence_requirements'], JSON_THROW_ON_ERROR);
        try {
            DB::transaction(fn () => DB::table('laboratory_clinical_reason_versions')->insert($row));
            $this->fail('A valid member must not transplant a foreign reason into this lab.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 1);
        $this->artifact('postgresql-schema.json', [
            'server' => DB::selectOne('SELECT version(), current_database(), current_user'),
            'constraints' => DB::select("SELECT c.conrelid::regclass::text AS table_name,c.conname,c.contype,pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c WHERE c.connamespace='public'::regnamespace AND (c.conrelid::regclass::text LIKE '%clinical_reason%' OR c.conrelid::regclass::text LIKE '%exception%' OR c.conrelid::regclass::text LIKE 'saas_%') ORDER BY table_name,c.conname"),
            'triggers' => DB::select("SELECT c.relname AS table_name,t.tgname,pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND c.relnamespace='public'::regnamespace AND (c.relname LIKE '%clinical_reason%' OR c.relname LIKE '%exception%' OR c.relname LIKE 'saas_%') ORDER BY c.relname,t.tgname"),
            'indexes' => DB::select("SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND (tablename LIKE '%clinical_reason%' OR tablename LIKE '%exception%' OR tablename LIKE 'saas_%') ORDER BY tablename,indexname"),
        ]);
    }

    public function test_unified_selection_stays_paged_and_has_a_recorded_real_plan_with_version_history(): void
    {
        [$lab, $actor] = $this->scenario(false);
        $data = $this->local($lab, $actor);
        for ($reason = 0; $reason < 60; $reason++) {
            $current = $reason === 0 ? $data : $this->local($lab, $actor, 'ficticio_'.$reason);
            for ($number = 1; $number < 5; $number++) {
                app(ClinicalReasonVersionStore::class)->appendLocal($lab, $current['reason']->id, $actor, $number, $this->definition());
            }
        }
        $global = $this->exceptionReference($lab, $actor);
        $version = GlobalClinicalReasonVersion::findOrFail($global['version_id']);
        $operator = User::findOrFail($version->created_by);
        for ($revision = 1; $revision <= 20; $revision++) {
            app(GlobalClinicalReasonImpactManager::class)->declare($operator, $global['reason_id'], $version->id, ['expected_revision' => $revision, 'classification' => 'restrictive', 'justification' => 'FICTICIO: historial para medición']);
        }
        foreach (ClinicalReasonImpactReview::orderBy('id')->get() as $review) {
            foreach (['non_favorable', 'non_favorable', 'favorable'] as $revision => $outcome) {
                app(ClinicalReasonAdoptionManager::class)->resolve($lab, $actor, $global['adoption_id'], $review->id, ['expected_revision' => $revision, 'outcome' => $outcome, 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
            }
        }
        $this->assertDatabaseCount('clinical_reason_impact_reviews', 20);
        $this->assertDatabaseCount('clinical_reason_impact_resolutions', 60);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson('/api/v1/laboratory/clinical-reasons?eligible=true')->assertOk()->assertJsonPath('meta.total', 61)->assertJsonCount(50, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(35, count($queries), 'Selection must not fetch history or permissions per result.');
        $policy = app(ClinicalReasonEligibilityPolicy::class);
        $query = DB::query()->fromSub($policy->selectionQuery($policy->globalSelections($lab)->unionAll($policy->localSelections($lab))), 'catalog')->where('request_eligible', true)->orderBy('source')->orderBy('reason_id')->limit(50);
        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings());
        $this->assertSame(5, $response->json('data.1.version.number'));
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 300);
        $this->artifact('selection-performance.json', ['fixture' => ['local_reasons' => 60, 'local_versions' => 300, 'global_adoptions' => 1, 'global_reviews' => 20, 'global_evaluations' => 60], 'query_count' => count($queries), 'database_time_ms' => array_sum(array_column($queries, 'time')), 'queries' => $queries, 'explain' => $plan]);
    }

    private function artifact(string $name, array $data): void
    {
        $directory = getenv('DONQER_AUDIT_ARTIFACT_DIR');
        if ($directory !== false && is_dir($directory)) {
            file_put_contents($directory.'/'.$name, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
