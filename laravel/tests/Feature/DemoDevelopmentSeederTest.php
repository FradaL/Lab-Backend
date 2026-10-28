<?php

namespace Tests\Feature;

use App\Models\GlobalClinicalReason;
use App\Models\SaasAuditLog;
use App\Models\SaasCapabilityAssignment;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Database\Seeders\DemoClinicalReasonsSeeder;
use Database\Seeders\DemoDevelopmentSeeder;
use Database\Seeders\DemoLaboratorySeeder;
use Database\Seeders\DemoSaasUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class DemoDevelopmentSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-testing-only-2026';

    private function provision(): void
    {
        config(['clinical_reason_demo.owner_password' => self::PASSWORD]);
        $this->seed(DemoDevelopmentSeeder::class);
    }

    private function snapshot(): array
    {
        $tables = ['users', 'laboratories', 'laboratory_user', 'roles', 'model_has_roles', 'role_has_permissions',
            'subscriptions', 'saas_capability_assignments', 'saas_audit_logs', 'global_clinical_reasons',
            'global_clinical_reason_versions', 'global_clinical_reason_impacts', 'clinical_reason_impact_reviews',
            'clinical_reason_adoptions', 'clinical_reason_adoption_versions', 'audit_logs', 'demo_dataset_records'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->get()->toJson();
        }

        return $snapshot;
    }

    public function test_nine_profiles_login_through_sanctum_and_have_exact_access_and_memberships(): void
    {
        $this->provision();
        $full = ['saas.clinical_reasons.create', 'saas.clinical_reasons.manage', 'saas.clinical_reasons.publish', 'saas.clinical_reasons.view'];
        $profiles = [
            'exc-demo-owner' => [[], 2],
            'demo-saas-view' => [['saas.clinical_reasons.view'], 0],
            'demo-saas-create' => [['saas.clinical_reasons.create'], 0],
            'demo-saas-publish' => [['saas.clinical_reasons.publish'], 0],
            'demo-saas-manage' => [['saas.clinical_reasons.manage'], 0],
            'demo-saas-full' => [$full, 0],
            'demo-saas-mixed' => [$full, 1],
            'demo-no-access' => [[], 0],
            'demo-saas-impact' => [['saas.clinical_reasons.classify_impact', 'saas.clinical_reasons.view'], 0],
        ];
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        foreach ($profiles as $profile => [$capabilities, $memberships]) {
            $this->get('/sanctum/csrf-cookie')->assertNoContent();
            $email = $profile.'@donqerlab.test';
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertOk();
            Auth::forgetGuards();
            $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.email', $email)
                ->assertJsonPath('data.saas_capabilities', $capabilities);
            $this->getJson('/api/v1/auth/laboratories')->assertOk()->assertJsonCount($memberships, 'data');
            $this->getJson('/api/v1/platform/clinical-reasons')->assertStatus(in_array('saas.clinical_reasons.view', $capabilities, true) ? 200 : 403);
            $this->postJson('/api/v1/auth/logout')->assertOk();
            Auth::forgetGuards();
        }
        $this->assertDatabaseCount('users', 11); // Nine frontend profiles plus two private service actors.
        $this->assertDatabaseCount('laboratory_user', 3);
        $this->assertDatabaseCount('saas_audit_logs', 75);
        $this->assertDatabaseCount('saas_capability_assignments', 19);
        $before = $this->snapshot();
        $this->provision();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_impact_profile_can_read_and_classify_but_cannot_publish_manage_or_grant(): void
    {
        $this->provision();
        $user = User::where('email', 'demo-saas-impact@donqerlab.test')->sole();
        $actor = User::where('email', 'exc-demo-a@donqerlab.test')->sole();
        $grants = SaasCapabilityAssignment::where('user_id', $user->id)->get();
        $this->assertCount(2, $grants);
        foreach ($grants as $grant) {
            $this->assertSame($actor->id, $grant->granted_by);
            $this->assertNotSame($user->id, $grant->granted_by);
            $this->assertDatabaseHas('saas_audit_logs', ['id' => $grant->grant_audit_id,
                'actor_id' => $actor->id, 'affected_user_id' => $user->id, 'action' => 'saas.access.granted']);
        }
        $base = '/api/v1/platform/clinical-reasons';
        $this->actingAs($user);
        $reason = collect($this->getJson($base)->assertOk()->json('data'))->firstWhere('code', 'demo_exc_003');
        $id = $reason['id'];
        $this->getJson($base.'/'.$id)->assertOk();
        $version = $this->getJson($base.'/'.$id.'/versions')->assertOk()->json('data.0.id');
        $this->getJson($base.'/'.$id.'/events')->assertOk();
        $impact = $base.'/'.$id.'/versions/'.$version.'/impact';
        $this->getJson($impact)->assertOk()->assertJsonPath('data.classification', 'unclassified')->assertJsonPath('data.declaration', null);
        $body = ['expected_revision' => 0, 'classification' => 'non_restrictive', 'justification' => 'FICTICIO: prueba de Cristian.'];
        $this->postJson($impact.'/declarations', $body)->assertCreated()->assertJsonPath('data.revision', 1)->assertJsonPath('data.created_by', $user->id);
        $this->getJson($impact)->assertOk()->assertJsonPath('data.classification', 'non_restrictive');
        $this->getJson($impact.'/declarations')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($impact.'/declarations', $body)->assertConflict();
        $this->postJson($base, [])->assertForbidden();
        $this->postJson($base.'/'.$id.'/versions', [])->assertForbidden();
        $this->postJson($base.'/'.$id.'/activation', [])->assertForbidden();
        $this->getJson('/api/v1/platform/access/users')->assertForbidden();
        $this->postJson('/api/v1/platform/access/users/'.$user->id.'/capabilities', [
            'capability' => 'saas.access.manage', 'reason' => 'FICTICIO autoconcesión prohibida',
        ])->assertForbidden();
        foreach (array_diff(array_keys(DemoSaasUsersSeeder::profiles()), ['demo-saas-impact']) as $profile) {
            $this->actingAs(User::where('email', $profile.'@donqerlab.test')->sole());
            $this->getJson($impact)->assertForbidden();
            $this->getJson($impact.'/declarations')->assertForbidden();
            $this->postJson($impact.'/declarations', $body)->assertForbidden();
        }
        $before = $this->snapshot();
        $this->provision();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_impact_revocation_via_official_api_is_immediate_and_not_repaired(): void
    {
        $this->provision();
        $user = User::where('email', 'demo-saas-impact@donqerlab.test')->sole();
        $actor = User::where('email', 'exc-demo-a@donqerlab.test')->sole();
        $base = '/api/v1/platform/access/users/'.$user->id.'/capabilities';
        $assignments = $this->actingAs($actor)->getJson($base)->assertOk()->json('data');
        $grant = collect($assignments)->firstWhere('capability_code', 'saas.clinical_reasons.classify_impact');
        $this->postJson($base.'/'.$grant['id'].'/revoke', [
            'revision' => $grant['revision'], 'reason' => 'FICTICIO: fin de prueba de Cristian.',
        ])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.saas_capabilities', ['saas.clinical_reasons.view']);
        $reason = GlobalClinicalReason::where('code', 'demo_exc_003')->sole();
        $impact = '/api/v1/platform/clinical-reasons/'.$reason->id.'/versions/'.$reason->latestVersion->id.'/impact';
        $this->getJson($impact)->assertForbidden();
        $this->getJson($impact.'/declarations')->assertForbidden();
        $this->postJson($impact.'/declarations', [])->assertForbidden();
        $this->getJson('/api/v1/platform/clinical-reasons')->assertOk();
        $before = $this->snapshot();
        try {
            $this->provision();
            $this->fail('Revoked classification must never be restored.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('automatic repair is forbidden', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_both_laboratories_and_mixed_profile_can_paginate_adopt_and_update(): void
    {
        $this->provision();
        foreach (['exc-demo-owner', 'demo-saas-mixed'] as $profile) {
            $reason = GlobalClinicalReason::where('code', $profile === 'exc-demo-owner' ? 'demo_exc_003' : 'demo_exc_001')->sole();
            $versions = $reason->versions()->orderBy('number')->get();
            $user = User::where('email', $profile.'@donqerlab.test')->sole();
            foreach ($user->laboratories()->get() as $lab) {
                $this->actingAs($user)->withHeader('X-Laboratory-ID', (string) $lab->id);
                $this->getJson('/api/v1/laboratory/clinical-reasons/global')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 51);
                $this->getJson('/api/v1/laboratory/clinical-reasons/global?page=2')->assertOk()->assertJsonCount(1, 'data');
                $base = '/api/v1/laboratory/clinical-reason-adoptions';
                $id = $this->postJson($base, ['reason_id' => $reason->id, 'version_id' => $versions[0]->id, 'justification' => 'DEMO adopción'])
                    ->assertCreated()->json('data.adoption.id');
                $this->postJson($base.'/'.$id.'/versions', ['version_id' => $versions[1]->id, 'expected_revision' => 1, 'justification' => 'DEMO actualización'])->assertCreated();
                $this->getJson($base.'/'.$id.'/versions')->assertOk()->assertJsonCount(2, 'data');
            }
        }
        $this->assertDatabaseCount('global_clinical_reasons', 51);
        $this->assertDatabaseCount('global_clinical_reason_versions', 56);
        $before = $this->snapshot();
        $this->provision();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_revocation_is_effective_and_never_restored_by_seeders(): void
    {
        $this->provision();
        $actor = User::where('email', 'exc-demo-a@donqerlab.test')->sole();
        $user = User::where('email', 'demo-saas-create@donqerlab.test')->sole();
        $grant = SaasCapabilityAssignment::where('user_id', $user->id)->sole();
        app(SaasAccessManager::class)->revoke($actor, $user->id, $grant->id, ['revision' => 1, 'reason' => 'DEMO revocación']);
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.saas_capabilities', []);
        $this->postJson('/api/v1/platform/clinical-reasons', [])->assertForbidden();
        $before = $this->snapshot();
        try {
            $this->provision();
            $this->fail('A revoked DEMO grant must reject reruns.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('automatic repair is forbidden', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_all_entry_points_reject_foreign_databases_and_production(): void
    {
        foreach ([DemoDevelopmentSeeder::class, DemoClinicalReasonsSeeder::class, DemoSaasUsersSeeder::class, DemoLaboratorySeeder::class] as $seeder) {
            foreach (['host', 'production'] as $scenario) {
                config(['database.connections.pgsql.host' => $scenario === 'host' ? 'shared-postgres' : 'postgres-testing']);
                $this->app->detectEnvironment(fn () => $scenario === 'production' ? 'production' : 'testing');
                try {
                    $this->app->make($seeder)->run();
                    $this->fail('Unsafe DEMO target must be rejected.');
                } catch (RuntimeException $error) {
                    $this->assertMatchesRegularExpression('/rejected|forbidden/', $error->getMessage());
                } finally {
                    config(['database.connections.pgsql.host' => 'postgres-testing']);
                    $this->app->detectEnvironment(fn () => 'testing');
                }
            }
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_connection_overrides_and_missing_secrets_are_rejected_before_writes(): void
    {
        $original = config('database.connections.pgsql');
        $cases = [
            ['database' => 'donqer_lab'], ['username' => 'another-user'], ['port' => '5433'],
            ['url' => 'postgres://forbidden'], ['read' => ['host' => 'replica']], ['driver' => 'sqlite'],
        ];
        foreach ($cases as $override) {
            config(['database.connections.pgsql' => array_replace($original, $override)]);
            try {
                $this->app->make(DemoDevelopmentSeeder::class)->run();
                $this->fail('Connection overrides must be rejected.');
            } catch (RuntimeException $error) {
                $this->assertMatchesRegularExpression('/rejected|forbidden/', $error->getMessage());
            } finally {
                config(['database.connections.pgsql' => $original]);
            }
        }
        config(['clinical_reason_demo.owner_password' => null]);
        try {
            $this->app->make(DemoDevelopmentSeeder::class)->run();
            $this->fail('A secret is required.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('DONQER_EXC_DEMO_OWNER_PASSWORD', $error->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
    }

    public function test_modified_membership_is_preserved_and_reported(): void
    {
        $this->provision();
        $user = User::where('email', 'demo-saas-mixed@donqerlab.test')->sole();
        DB::table('laboratory_user')->where('user_id', $user->id)->update(['is_active' => false]);
        $before = $this->snapshot();
        try {
            $this->provision();
            $this->fail('Inactive membership must never be repaired.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('membership/role changed', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_preexisting_real_users_are_preserved_and_first_installation_rejected(): void
    {
        $real = User::factory()->create();
        $before = $this->snapshot();
        try {
            $this->provision();
            $this->fail('First installation must use an empty dedicated database.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('empty, dedicated database', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue($real->fresh()->exists);
    }

    public function test_failure_in_new_profile_audit_rolls_back_catalog_and_profiles(): void
    {
        SaasAuditLog::created(function (SaasAuditLog $event): void {
            if ($event->action === 'saas.access.granted' && User::find($event->affected_user_id)?->email === 'demo-saas-impact@donqerlab.test') {
                throw new RuntimeException('Injected profile audit failure.');
            }
        });
        try {
            $this->provision();
            $this->fail('Audit failure must roll back all DEMO data.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected profile audit failure.', $error->getMessage());
        } finally {
            SaasAuditLog::flushEventListeners();
            SaasAuditLog::clearBootedModels();
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('global_clinical_reason_versions', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
        $this->assertDatabaseCount('demo_datasets', 0);
    }

    public function test_existing_catalog_can_be_extended_without_resetting_credentials_or_touching_unrelated_users(): void
    {
        config(['clinical_reason_demo.owner_password' => self::PASSWORD]);
        $this->seed(DemoClinicalReasonsSeeder::class);
        $this->provision();
        $real = User::factory()->create();
        $before = $real->fresh()->getRawOriginal();
        $this->provision();
        $this->assertSame($before, $real->fresh()->getRawOriginal());
        config(['clinical_reason_demo.owner_password' => 'Another-synthetic-secret-2026']);
        try {
            $this->seed(DemoDevelopmentSeeder::class);
            $this->fail('Existing passwords must not be replaced.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('credential mismatch', $error->getMessage());
        }
        $this->assertTrue(Hash::check(self::PASSWORD, User::where('email', 'demo-saas-view@donqerlab.test')->sole()->password));
    }
}
