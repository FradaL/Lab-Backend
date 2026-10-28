<?php

namespace Tests\Feature\ClinicalReasons;

use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\GlobalClinicalReasonManager;
use App\Models\GlobalClinicalReason;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\SaasAuditLog;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class DemoClinicalReasonProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function provision(): void
    {
        config(['clinical_reason_demo.owner_password' => 'Synthetic-testing-only-2026']);
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing', '--yes' => true])->assertSuccessful();
    }

    public function test_authorized_bootstrap_provisions_an_audited_idempotent_catalog(): void
    {
        $this->provision();
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('saas_capability_assignments', 5);
        $this->assertDatabaseCount('global_clinical_reasons', 51);
        $this->assertDatabaseCount('global_clinical_reason_versions', 56);
        $this->assertDatabaseCount('saas_audit_logs', 61);
        $a = User::where('email', 'exc-demo-a@donqerlab.test')->sole();
        $b = User::where('email', 'exc-demo-b@donqerlab.test')->sole();
        $this->assertSame(['saas.access.manage'], DB::table('saas_capability_assignments')->where('user_id', $a->id)->pluck('capability_code')->all());
        $this->assertSame(4, DB::table('saas_capability_assignments')->where('granted_by', $a->id)->where('user_id', $b->id)->count());
        $this->assertSame(0, $a->laboratories()->count());
        $this->assertSame(0, $b->laboratories()->count());
        $this->assertSame(3, SaasAuditLog::where('action', 'saas.clinical_reasons.activation_changed')->count());
        $this->assertSame(2, SaasAuditLog::where('action', 'saas.clinical_reasons.published')->count());
        $before = DB::table('saas_audit_logs')->orderBy('id')->get()->toJson();
        $this->provision();
        $this->assertSame($before, DB::table('saas_audit_logs')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('global_clinical_reason_versions', 56);
        foreach (GlobalClinicalReasonVersion::all() as $version) {
            $this->assertSame(1, SaasAuditLog::where('global_reason_version_id', $version->id)->where('actor_id', $b->id)->count());
        }
    }

    public function test_tenant_reads_pagination_and_explicit_adoption_update_preserve_isolation(): void
    {
        $this->provision();
        $owner = User::where('email', 'exc-demo-owner@donqerlab.test')->sole();
        $lab = Laboratory::sole();
        $this->actingAs($owner)->withHeader('X-Laboratory-Id', (string) $lab->id);
        $base = '/api/v1/laboratory/clinical-reasons/global';
        $this->getJson($base)->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 51)->assertJsonPath('meta.last_page', 2);
        $this->getJson($base.'?page=2')->assertOk()->assertJsonCount(1, 'data');
        $reason = GlobalClinicalReason::where('code', 'demo_exc_003')->sole();
        $v1 = $reason->versions()->where('number', 1)->sole();
        $v2 = $reason->versions()->where('number', 2)->sole();
        $this->getJson($base.'/'.$reason->id)->assertOk()->assertJsonPath('data.code', 'demo_exc_003');
        $this->getJson($base.'/'.$reason->id.'/versions')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(6, GlobalClinicalReason::all()->filter(fn ($reason) => ! $reason->latestVersion->is_active)->count());
        $this->getJson('/api/v1/laboratory/clinical-reasons?source=global')->assertOk()->assertJsonCount(0, 'data');
        $adoptions = '/api/v1/laboratory/clinical-reason-adoptions';
        $adopted = $this->postJson($adoptions, ['reason_id' => $reason->id, 'version_id' => $v1->id, 'justification' => 'DEMO: adopción explícita.'])->assertCreated();
        $id = $adopted->json('data.adoption.id');
        $this->getJson('/api/v1/laboratory/clinical-reasons?source=global')->assertOk()->assertJsonPath('data.0.version.id', $v1->id)->assertJsonPath('data.0.actions.can_update_adoption', true)->assertJsonPath('data.0.eligibility.request_blockers.0', 'impact_unclassified');
        $this->postJson($adoptions.'/'.$id.'/versions', ['version_id' => $v2->id, 'expected_revision' => 1, 'justification' => 'DEMO: actualización explícita.'])->assertCreated();
        $this->getJson($adoptions.'/'.$id.'/versions')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/laboratory/clinical-reasons?source=global')->assertOk()->assertJsonPath('data.0.version.id', $v2->id);
        $this->getJson('/api/v1/platform/clinical-reasons')->assertForbidden();
        $b = User::where('email', 'exc-demo-b@donqerlab.test')->sole();
        $this->actingAs($b)->getJson($base)->assertForbidden();
        $a = User::where('email', 'exc-demo-a@donqerlab.test')->sole();
        try {
            app(GlobalClinicalReasonManager::class)->create($a, ['code' => 'forbidden']);
            $this->fail('Access management must not confer clinical creation.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $other = Laboratory::factory()->create();
        setPermissionsTeamId($other->id);
        try {
            app(ClinicalReasonAdoptionManager::class)->catalog($other, $owner);
            $this->fail('Owner must not access a foreign laboratory.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(2, DB::table('audit_logs')->whereIn('event', ['clinical_reason.adopted', 'clinical_reason.adoption_updated'])->count());
    }

    public function test_confirmation_and_environment_guards_prevent_writes(): void
    {
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'wrong', '--yes' => true])->assertFailed();
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing'])->expectsConfirmation('Write synthetic SaaS identities and DEMO catalog to donqer_lab_testing?', 'no')->assertFailed();
        config(['database.connections.pgsql.host' => 'shared-postgres']);
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing', '--yes' => true])->assertFailed();
        config(['database.connections.pgsql.host' => 'postgres-testing']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing', '--yes' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
        $this->app->detectEnvironment(fn () => 'testing');
    }

    public function test_preexisting_data_are_rejected_and_rolled_back(): void
    {
        $real = User::factory()->create();
        $before = $real->fresh()->getRawOriginal();
        config(['clinical_reason_demo.owner_password' => 'Synthetic-testing-only-2026']);
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing', '--yes' => true])->assertFailed();
        $this->assertSame($before, $real->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('saas_audit_logs', 0);
        $this->assertDatabaseCount('demo_datasets', 0);
    }

    public function test_administrative_changes_to_demo_history_are_preserved_and_reported(): void
    {
        $this->provision();
        $b = User::where('email', 'exc-demo-b@donqerlab.test')->sole();
        $reason = GlobalClinicalReason::where('code', 'demo_exc_051')->sole();
        app(GlobalClinicalReasonManager::class)->activation($b, $reason->id, ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'DEMO: administrative change.']);
        $before = SaasAuditLog::count();
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing', '--yes' => true])->assertFailed();
        $this->assertSame($before, SaasAuditLog::count());
        $this->assertDatabaseCount('global_clinical_reason_versions', 57);
        $this->assertFalse($reason->fresh()->latestVersion->is_active);
    }

    public function test_catalog_audit_failure_rolls_back_the_entire_provisioning(): void
    {
        config(['clinical_reason_demo.owner_password' => 'Synthetic-testing-only-2026']);
        SaasAuditLog::created(function (SaasAuditLog $event): void {
            if ($event->global_reason_id !== null) {
                throw new \RuntimeException('Injected catalog audit failure.');
            }
        });
        try {
            $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing', '--yes' => true])->assertFailed();
        } finally {
            SaasAuditLog::flushEventListeners();
            SaasAuditLog::clearBootedModels();
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('laboratories', 0);
        $this->assertDatabaseCount('global_clinical_reasons', 0);
        $this->assertDatabaseCount('saas_capability_assignments', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
        $this->assertDatabaseCount('demo_datasets', 0);
    }

    public function test_revoked_capabilities_are_not_restored_on_rerun(): void
    {
        $this->provision();
        $a = User::where('email', 'exc-demo-a@donqerlab.test')->sole();
        $b = User::where('email', 'exc-demo-b@donqerlab.test')->sole();
        $assignment = DB::table('saas_capability_assignments')->where('user_id', $b->id)->where('capability_code', GlobalClinicalReasonManager::CREATE)->sole();
        app(SaasAccessManager::class)->revoke($a, $b->id, $assignment->id, ['revision' => 1, 'reason' => 'DEMO: revocación explícita.']);
        $before = SaasAuditLog::count();
        $this->artisan('demo:clinical-reasons', ['--confirm-database' => 'donqer_lab_testing', '--yes' => true])->assertFailed();
        $this->assertSame($before, SaasAuditLog::count());
        $this->assertDatabaseCount('saas_capability_assignments', 5);
    }
}
