<?php

namespace Tests\Feature\SaasAccess;

use App\Authorization\RbacCatalog;
use App\Authorization\SaasAccessAuthority;
use App\Models\Laboratory;
use App\Models\SaasAuditLog;
use App\Models\SaasCapability;
use App\Models\SaasCapabilityAssignment;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use App\UserAdministration\SaasAccessManager;
use Database\Seeders\SaasAccessBootstrapSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SaasAccessTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/platform/access';

    private function bootstrapUser(?User $user = null): User
    {
        $user ??= User::factory()->create();
        config(['saas.bootstrap.enabled' => true, 'saas.bootstrap.user_id' => $user->id]);
        $this->seed(SaasAccessBootstrapSeeder::class);

        return $user;
    }

    private function grant(User $actor, User $target): SaasCapabilityAssignment
    {
        return app(SaasAccessManager::class)->grant($actor, $target->id, ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO: autorización explícita.']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson(self::BASE.'/users')->assertUnauthorized();
        $this->postJson(self::BASE.'/users/1/capabilities', [])->assertUnauthorized();
        $this->assertDatabaseCount('saas_capability_assignments', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
    }

    #[DataProvider('tenantRoles')]
    public function test_tenant_roles_cannot_administer_global_access(string $roleName): void
    {
        $lab = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($lab);
        setPermissionsTeamId($lab->id);
        $user->assignRole(Role::findOrCreate($roleName, 'web'));
        $this->actingAs($user)->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson(self::BASE.'/capabilities')->assertForbidden();
        $this->postJson(self::BASE.'/users/'.$user->id.'/capabilities', ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO'])->assertForbidden();
        $this->assertDatabaseCount('saas_capability_assignments', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
        $this->assertNull(getPermissionsTeamId());
    }

    public static function tenantRoles(): array
    {
        return [['owner'], ['administrator']];
    }

    public function test_bootstrap_is_explicit_idempotent_and_grants_no_tenant_or_clinical_authority(): void
    {
        $user = $this->bootstrapUser();
        $before = DB::table('users')->where('id', $user->id)->first();
        $this->seed(SaasAccessBootstrapSeeder::class);
        $this->assertDatabaseCount('saas_capability_assignments', 1);
        $this->assertDatabaseCount('saas_audit_logs', 1);
        $this->assertDatabaseCount('laboratory_user', 0);
        $this->assertDatabaseCount('model_has_roles', 0);
        $this->assertDatabaseCount('model_has_permissions', 0);
        $this->assertSame([SaasAccessAuthority::MANAGE], SaasCapabilityAssignment::where('user_id', $user->id)->pluck('capability_code')->all());
        $this->assertSame(6, SaasCapability::count());
        $audit = SaasAuditLog::sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('SaasAccessBootstrapSeeder', $audit->actor_label);
        $this->assertSame($user->id, $audit->affected_user_id);
        $this->assertSame('succeeded', $audit->result);
        $this->assertNotEmpty($audit->operation_id);
        $this->assertEquals($before, DB::table('users')->where('id', $user->id)->first());
        $this->actingAs($user)->getJson(self::BASE.'/capabilities')->assertOk()->assertJsonPath('data.0.code', SaasAccessAuthority::MANAGE);
        $this->getJson(self::BASE.'/users')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::BASE.'/users/'.$user->id.'/capabilities')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::BASE.'/history')->assertOk()->assertJsonPath('data.0.actor_type', 'system');
        $this->getJson('/api/v1/auth/authorization')->assertStatus(400);
        RbacCatalog::assertValid();
    }

    #[DataProvider('badBootstrap')]
    public function test_bootstrap_fails_without_an_explicit_active_existing_user(string $case): void
    {
        $user = User::factory()->create(['is_active' => $case !== 'inactive']);
        config(['saas.bootstrap.enabled' => $case !== 'disabled', 'saas.bootstrap.user_id' => match ($case) {
            'missing' => null, 'unknown' => 999999, default => $user->id,
        }]);
        if ($case === 'production') {
            app()->detectEnvironment(fn () => 'production');
        }
        try {
            (new SaasAccessBootstrapSeeder)->run();
            $this->fail('Bootstrap must reject this configuration.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('saas_capability_assignments', 0);
            $this->assertDatabaseCount('saas_audit_logs', 0);
        }
    }

    public static function badBootstrap(): array
    {
        return [['missing'], ['unknown'], ['inactive'], ['disabled'], ['production']];
    }

    public function test_grants_revocations_history_and_regrant_preserve_evidence(): void
    {
        $admin = $this->bootstrapUser();
        $target = User::factory()->create();
        $this->actingAs($admin);
        $r = $this->postJson(self::BASE.'/users/'.$target->id.'/capabilities', ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO: concesión'])->assertCreated();
        $id = $r->json('data.id');
        $auditBefore = SaasAuditLog::findOrFail($r->json('data.grant_audit_id'))->getRawOriginal();
        $this->postJson(self::BASE.'/users/'.$target->id.'/capabilities/'.$id.'/revoke', ['revision' => 1, 'reason' => 'FICTICIO: revocación'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame($auditBefore, SaasAuditLog::findOrFail($r->json('data.grant_audit_id'))->getRawOriginal());
        $this->getJson(self::BASE.'/users/'.$target->id.'/capabilities')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson(self::BASE.'/users/'.$target->id.'/capabilities/'.$id.'/revoke', ['revision' => 1, 'reason' => 'FICTICIO'])->assertConflict();
        $new = $this->grant($admin, $target);
        $this->assertNotSame($id, $new->id);
        $this->assertSame(2, SaasCapabilityAssignment::where('user_id', $target->id)->count());
        $this->assertSame($admin->id, $new->grantedBy->id);
        $this->assertDatabaseCount('saas_audit_logs', 4);
    }

    public function test_self_grant_unknown_capability_and_forged_fields_are_rejected_without_writes(): void
    {
        $admin = $this->bootstrapUser();
        $target = User::factory()->create();
        $this->actingAs($admin)->postJson(self::BASE.'/users/'.$admin->id.'/capabilities', ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO'])->assertForbidden();
        $this->postJson(self::BASE.'/users/'.$target->id.'/capabilities', ['capability' => 'saas.clinical_reasons.unknown', 'reason' => 'FICTICIO'])->assertUnprocessable();
        $this->postJson(self::BASE.'/users/'.$target->id.'/capabilities', ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO', 'granted_by' => $target->id])->assertUnprocessable();
        $this->postJson(self::BASE.'/users/'.$target->id.'/capabilities', ['capability' => SaasAccessAuthority::MANAGE, 'reason' => ' '])->assertUnprocessable();
        $this->assertDatabaseCount('saas_capability_assignments', 1);
        $this->assertDatabaseCount('saas_audit_logs', 1);
    }

    public function test_last_active_manager_cannot_be_revoked_or_deactivated_even_through_sql(): void
    {
        $admin = $this->bootstrapUser();
        $id = SaasCapabilityAssignment::sole()->id;
        $this->actingAs($admin)->postJson(self::BASE.'/users/'.$admin->id.'/capabilities/'.$id.'/revoke', ['revision' => 1, 'reason' => 'FICTICIO'])->assertConflict()->assertJsonPath('code', 'saas_last_access_manager');
        $this->sqlRejected(fn () => DB::table('users')->where('id', $admin->id)->update(['is_active' => false]));
        $this->sqlRejected(fn () => DB::table('users')->where('id', $admin->id)->delete());
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertDatabaseCount('saas_audit_logs', 1);
    }

    public function test_account_deactivation_and_revocation_are_rechecked_without_cache(): void
    {
        $admin = $this->bootstrapUser();
        $second = User::factory()->create();
        $grant = $this->grant($admin, $second);
        $this->actingAs($second)->getJson(self::BASE.'/users')->assertOk();
        DB::table('users')->where('id', $second->id)->update(['is_active' => false]);
        $this->getJson(self::BASE.'/users')->assertForbidden();
        DB::table('users')->where('id', $second->id)->update(['is_active' => true]);
        app(SaasAccessManager::class)->revoke($admin, $second->id, $grant->id, ['revision' => 1, 'reason' => 'FICTICIO']);
        $this->getJson(self::BASE.'/users')->assertForbidden();
        config(['saas.bootstrap.user_id' => $second->id]);
        $this->expectException(\RuntimeException::class);
        (new SaasAccessBootstrapSeeder)->run();
    }

    public function test_tenant_context_is_ignored_and_never_granted_by_saas_access(): void
    {
        $lab = Laboratory::factory()->create();
        Subscription::factory()->for($lab)->create();
        $user = $this->createLaboratoryMember($lab);
        $this->bootstrapUser($user);
        $this->actingAs($user)->withHeader('X-Laboratory-ID', '999999')->getJson(self::BASE.'/users')->assertOk();
        $this->assertFalse(app(CurrentLaboratory::class)->has());
        $this->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson('/api/v1/patients')->assertForbidden();
        $this->assertDatabaseCount('model_has_permissions', 0);
    }

    public function test_service_boundary_denies_unprivileged_callers(): void
    {
        $user = User::factory()->create();
        try {
            app(SaasAccessManager::class)->grant($user, $user->id, ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO']);
            $this->fail('Service must authorize independently of HTTP.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('saas_audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_grant_and_audit_rows(): void
    {
        $admin = $this->bootstrapUser();
        $target = User::factory()->create();
        SaasAuditLog::created(fn () => throw new \RuntimeException('Injected global audit failure.'));
        try {
            $this->grant($admin, $target);
            $this->fail('Audit should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected global audit failure.', $e->getMessage());
        }
        $this->assertDatabaseCount('saas_capability_assignments', 1);
        $this->assertDatabaseCount('saas_audit_logs', 1);
    }

    public function test_postgresql_protects_audit_grants_and_required_evidence(): void
    {
        $admin = $this->bootstrapUser();
        $grant = SaasCapabilityAssignment::sole();
        $this->sqlRejected(fn () => DB::table('saas_audit_logs')->where('id', $grant->grant_audit_id)->update(['justification' => 'Altered']));
        $this->sqlRejected(fn () => DB::table('saas_audit_logs')->where('id', $grant->grant_audit_id)->delete());
        $this->sqlRejected(fn () => DB::table('saas_capability_assignments')->where('id', $grant->id)->delete());
        $this->sqlRejected(fn () => DB::table('saas_capability_assignments')->where('id', $grant->id)->update(['grant_reason' => 'Altered']));
        $this->sqlRejected(fn () => DB::table('saas_capability_assignments')->insert(['user_id' => $admin->id, 'capability_code' => SaasAccessAuthority::MANAGE, 'granted_by' => $admin->id, 'granted_at' => now(), 'grant_reason' => 'FICTICIO', 'grant_audit_id' => $grant->grant_audit_id]));
        $this->assertDatabaseCount('saas_capability_assignments', 1);
    }

    public function test_saas_routes_match_openapi_and_keep_tenant_permissions_separate(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/platform/access/'));
        $this->assertCount(6, $routes);
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains('user.active', $middleware);
            $this->assertContains('saas.capability:saas.access.manage', $middleware);
            $this->assertNotContains('saas', $middleware);
            $operation = $document['paths']['/'.$route->uri()][strtolower($route->methods()[0])];
            $this->assertSame(['SaaS Access'], $operation['tags']);
            $this->assertSame([['sanctumCookie' => []]], $operation['security']);
            $this->assertEmpty(collect($operation['parameters'] ?? [])->where('name', 'X-Laboratory-ID'));
        }
    }

    public function test_existing_session_authentication_works_without_a_tenant_membership(): void
    {
        $user = $this->bootstrapUser();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        Auth::forgetGuards();
        $this->getJson(self::BASE.'/users')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson(self::BASE.'/users')->assertUnauthorized();
    }

    public function test_bootstrap_never_restores_a_revoked_grant(): void
    {
        $first = $this->bootstrapUser();
        $second = User::factory()->create();
        $this->grant($first, $second);
        $grant = SaasCapabilityAssignment::where('user_id', $first->id)->sole();
        app(SaasAccessManager::class)->revoke($second, $first->id, $grant->id, ['revision' => 1, 'reason' => 'FICTICIO']);
        $this->seed(SaasAccessBootstrapSeeder::class);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertDatabaseCount('saas_capability_assignments', 2);
        $this->assertDatabaseCount('saas_audit_logs', 3);
    }

    public function test_missing_targets_inactive_targets_and_stale_input_have_no_side_effects(): void
    {
        $admin = $this->bootstrapUser();
        $target = User::factory()->create(['is_active' => false]);
        $body = ['capability' => SaasAccessAuthority::MANAGE, 'reason' => 'FICTICIO'];
        $this->actingAs($admin)->postJson(self::BASE.'/users/999999/capabilities', $body)->assertNotFound();
        $this->postJson(self::BASE.'/users/'.$target->id.'/capabilities', $body)->assertConflict();
        $this->getJson(self::BASE.'/users/999999/capabilities')->assertNotFound();
        $this->getJson(self::BASE.'/history?page=0')->assertUnprocessable();
        $this->postJson(self::BASE.'/users/'.$admin->id.'/capabilities/1/revoke', ['revision' => '1', 'reason' => 'FICTICIO'])->assertUnprocessable();
        $this->assertDatabaseCount('saas_capability_assignments', 1);
        $this->assertDatabaseCount('saas_audit_logs', 1);
    }

    public function test_audit_failure_during_revocation_restores_the_active_assignment(): void
    {
        $admin = $this->bootstrapUser();
        $target = User::factory()->create();
        $grant = $this->grant($admin, $target);
        SaasAuditLog::created(fn () => throw new \RuntimeException('Injected revocation failure.'));
        try {
            app(SaasAccessManager::class)->revoke($admin, $target->id, $grant->id, ['revision' => 1, 'reason' => 'FICTICIO']);
            $this->fail('Audit failure must roll back revocation.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected revocation failure.', $e->getMessage());
        }
        $this->assertNull($grant->fresh()->revoked_at);
        $this->assertDatabaseCount('saas_audit_logs', 2);
    }

    private function sqlRejected(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL must reject the mutation.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }
}
