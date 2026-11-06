<?php

namespace Tests\Feature\Cash;

use App\Authorization\RbacCatalog;
use App\Authorization\RolePermissionManager;
use App\Cash\CashAuthority;
use App\Cash\CashPermissionManager;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\Subscription;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CashAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'owner'): array
    {
        $lab = Laboratory::factory()->create();
        Subscription::factory()->for($lab)->create();
        $this->seed(RolePermissionSeeder::class);
        $user = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($user, $lab, $role);

        return [$lab, $user];
    }

    public static function roles(): array
    {
        return array_map(fn (string $role): array => [$role], RbacCatalog::ROLES);
    }

    #[DataProvider('roles')]
    public function test_no_role_receives_financial_permissions_automatically_even_after_reseeding(string $role): void
    {
        [$lab, $actor] = $this->context($role);
        $this->seed(RolePermissionSeeder::class);
        setPermissionsTeamId($lab->id);
        foreach (CashAuthority::PERMISSIONS as $permission) {
            $this->assertDenied(fn () => app(CashAuthority::class)->run($lab, $actor, $permission, fn () => $this->fail('Unauthorized callback')));
        }
        $response = $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->getJson('/api/v1/auth/authorization')->assertOk();
        $this->assertSame([], array_values(array_intersect(CashAuthority::PERMISSIONS, $response->json('data.permissions'))));
    }

    public static function permissions(): array
    {
        return array_map(fn (string $permission): array => [$permission], CashAuthority::PERMISSIONS);
    }

    #[DataProvider('permissions')]
    public function test_each_explicit_permission_allows_only_its_own_operation(string $permission): void
    {
        [$lab, $actor] = $this->context();
        $this->assignDirectLaboratoryPermission($actor, $lab, $permission);
        $this->assertSame('allowed', app(CashAuthority::class)->run($lab, $actor, $permission, fn () => 'allowed'));
        foreach (array_diff(CashAuthority::PERMISSIONS, [$permission]) as $other) {
            $this->assertDenied(fn () => app(CashAuthority::class)->run($lab, $actor, $other, fn () => $this->fail('Implicit financial permission')));
        }
    }

    #[DataProvider('roles')]
    public function test_credit_depends_on_effective_permission_for_every_role_and_response_matches(string $role): void
    {
        [$lab, $actor] = $this->context($role);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREDIT);
        $this->assertSame('allowed', app(CashAuthority::class)->run($lab, $actor, CashAuthority::CREDIT, fn () => 'allowed'));
        $response = $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->getJson('/api/v1/auth/authorization')->assertOk();
        $this->assertContains(CashAuthority::CREDIT, $response->json('data.permissions'));
    }

    public function test_inactive_actor_membership_laboratory_subscription_and_wrong_context_are_denied(): void
    {
        [$lab, $actor] = $this->context();
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        $call = fn () => app(CashAuthority::class)->run($lab, $actor, CashAuthority::CREATE, fn () => $this->fail('Inactive context'));
        $actor->update(['is_active' => false]);
        $this->assertDenied($call);
        $actor->update(['is_active' => true]);
        $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => false]);
        $this->assertDenied($call);
        $actor->laboratories()->updateExistingPivot($lab->id, ['is_active' => true]);
        $lab->update(['is_active' => false]);
        $this->assertDenied($call);
        $lab->update(['is_active' => true]);
        setPermissionsTeamId(null);
        $this->assertDenied($call);
        setPermissionsTeamId($lab->id);
        $lab->subscriptions()->update(['status' => Subscription::STATUS_INACTIVE]);
        $this->assertDenied($call);
    }

    public function test_grants_and_authorization_are_isolated_between_laboratories_with_warm_relations(): void
    {
        [$a, $actor] = $this->context();
        $b = Laboratory::factory()->create();
        Subscription::factory()->for($b)->create();
        $actor->laboratories()->attach($b, ['is_active' => true]);
        $this->assignDirectLaboratoryPermission($actor, $a, CashAuthority::VIEW);
        $this->assertSame('a', app(CashAuthority::class)->run($a, $actor, CashAuthority::VIEW, fn () => 'a'));
        setPermissionsTeamId($b->id);
        $this->assertDenied(fn () => app(CashAuthority::class)->run($b, $actor, CashAuthority::VIEW, fn () => $this->fail('Cross tenant grant')));
        setPermissionsTeamId($a->id);
        $this->assertSame('a', app(CashAuthority::class)->run($a, $actor, CashAuthority::VIEW, fn () => 'a'));
    }

    public function test_explicit_direct_grants_are_audited_idempotent_and_revocable_with_warm_cache(): void
    {
        [$lab, $owner] = $this->context();
        $target = $this->createLaboratoryMember($lab);
        $manager = app(CashPermissionManager::class);
        $manager->set($lab, $owner, $target, CashAuthority::CREATE, true, 'Responsable de cobros');
        $manager->set($lab, $owner, $target, CashAuthority::CREATE, true, 'Mismo permiso');
        $this->assertSame('ok', app(CashAuthority::class)->run($lab, $target, CashAuthority::CREATE, fn () => 'ok'));
        $manager->set($lab, $owner, $target, CashAuthority::CREATE, false, 'Retiro de facultad');
        $this->assertDenied(fn () => app(CashAuthority::class)->run($lab, $target, CashAuthority::CREATE, fn () => $this->fail('Revoked permission')));
        $events = AuditLog::where('laboratory_id', $lab->id)->orderBy('id')->get();
        $this->assertSame(['cash.permission_granted', 'cash.permission_revoked'], $events->pluck('event')->all());
        $this->assertSame($owner->id, $events[0]->user_id);
        $this->assertSame($target->id, $events[0]->auditable_id);
        $this->assertSame('Responsable de cobros', $events[0]->metadata['reason']);
    }

    public function test_credit_can_be_granted_and_revoked_through_audited_cashier_role_matrix(): void
    {
        [$lab, $owner] = $this->context();
        $cashier = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($cashier, $lab, 'cashier');
        $manager = app(RolePermissionManager::class);
        $manager->replace($lab, $owner, 'cashier', ['expected_revision' => 1, 'permissions' => [CashAuthority::CREDIT], 'reason' => 'Crédito delegado']);
        $this->assertSame('ok', app(CashAuthority::class)->run($lab, $cashier, CashAuthority::CREDIT, fn () => 'ok'));
        $this->actingAs($cashier)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->getJson('/api/v1/auth/authorization')->assertOk()->assertJsonPath('data.permissions', [CashAuthority::CREDIT]);
        setPermissionsTeamId($lab->id);
        $manager->replace($lab, $owner, 'cashier', ['expected_revision' => 2, 'permissions' => [], 'reason' => 'Retiro de crédito']);
        $this->assertDenied(fn () => app(CashAuthority::class)->run($lab, $cashier, CashAuthority::CREDIT, fn () => $this->fail('Revoked role credit')));
        $this->assertSame(2, AuditLog::where('event', 'role.permissions_replaced')->count());
    }

    public function test_grant_validation_for_authority_target_and_reason_has_no_side_effects(): void
    {
        [$lab, $owner] = $this->context();
        $target = $this->createLaboratoryMember($lab);
        $outsider = $this->createLaboratoryMember(Laboratory::factory()->create());
        $manager = app(CashPermissionManager::class);
        $this->assertDenied(fn () => $manager->set($lab, $target, $owner, CashAuthority::VIEW, true, 'No autoridad'));
        $this->assertDenied(fn () => $manager->set($lab, $owner, $outsider, CashAuthority::VIEW, true, 'Otro laboratorio'), 404);
        $this->assertDenied(fn () => $manager->set($lab, $owner, $target, 'patients.view', true, 'Fuera de Caja'), 422);
        $this->assertDenied(fn () => $manager->set($lab, $owner, $target, CashAuthority::VIEW, true, ' '), 422);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('model_has_permissions', 0);
    }

    public function test_manager_can_explicitly_authorize_and_revoke_credit_without_administrative_role(): void
    {
        [$lab, $owner] = $this->context();
        $admin = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($admin, $lab, 'receptionist');
        $manager = app(CashPermissionManager::class);
        $manager->set($lab, $owner, $admin, CashAuthority::CREDIT, true, 'Facultad expresa de crédito');
        $this->assertSame('ok', app(CashAuthority::class)->run($lab, $admin, CashAuthority::CREDIT, fn () => 'ok'));
        $manager->set($lab, $owner, $admin, CashAuthority::CREDIT, false, 'Retiro de facultad');
        $this->assertDenied(fn () => app(CashAuthority::class)->run($lab, $admin, CashAuthority::CREDIT, fn () => $this->fail('Revoked credit')));
    }

    public function test_direct_credit_permission_remains_effective_after_administrator_role_removal(): void
    {
        [$lab, $actor] = $this->context('administrator');
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREDIT);
        app(CashAuthority::class)->run($lab, $actor, CashAuthority::CREDIT, fn () => null);
        $actor->removeRole('administrator');
        $this->assertSame('ok', app(CashAuthority::class)->run($lab, $actor, CashAuthority::CREDIT, fn () => 'ok'));
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id)
            ->getJson('/api/v1/auth/authorization')->assertOk()->assertJsonPath('data.permissions', [CashAuthority::CREDIT]);
    }

    public function test_failed_audit_rolls_back_assignment(): void
    {
        [$lab, $owner] = $this->context();
        $target = $this->createLaboratoryMember($lab);
        DB::statement("CREATE FUNCTION reject_cash_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'audit unavailable'; END $$");
        DB::statement('CREATE TRIGGER reject_cash_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_cash_audit()');
        try {
            app(CashPermissionManager::class)->set($lab, $owner, $target, CashAuthority::VIEW, true, 'Prueba ficticia');
            $this->fail('Audit failure must roll back');
        } catch (QueryException $error) {
            $this->assertStringContainsString('audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('model_has_permissions', 0);
    }

    public function test_command_applies_only_explicit_assignment_and_restores_team(): void
    {
        [$lab, $owner] = $this->context();
        $target = $this->createLaboratoryMember($lab);
        setPermissionsTeamId(null);
        $this->artisan('cash:permission', ['laboratory' => $lab->id, 'actor' => $owner->id, 'user' => $target->id, 'permission' => CashAuthority::VIEW, '--reason' => 'Consulta autorizada'])->assertSuccessful();
        $this->assertNull(getPermissionsTeamId());
        $this->assertDatabaseHas('audit_logs', ['event' => 'cash.permission_granted']);
    }

    private function assertDenied(callable $operation, int $status = 403): void
    {
        try {
            $operation();
        } catch (AuthorizationException $error) {
            $this->assertSame(403, $status);

            return;
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());

            return;
        }
        $this->fail('Expected authorization rejection.');
    }
}
