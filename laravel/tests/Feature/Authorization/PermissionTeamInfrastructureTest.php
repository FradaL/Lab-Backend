<?php

namespace Tests\Feature\Authorization;

use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithPermissionTeams;
use Tests\TestCase;

class PermissionTeamInfrastructureTest extends TestCase
{
    use InteractsWithPermissionTeams;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('saas')->get('/api/v1/testing/permission-team', function () {
            /** @var User $user */
            $user = request()->user();

            return response()->json([
                'team_id' => getPermissionsTeamId(),
                'administrator' => $user->hasRole('administrator'),
                'viewer' => $user->hasRole('viewer'),
                'patients_create' => $user->can('patients.create'),
                'patients_view' => $user->can('patients.view'),
            ]);
        });
    }

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    public function test_configuration_uses_laboratories_as_permission_teams(): void
    {
        $this->assertTrue(config('permission.teams'));
        $this->assertSame(Laboratory::class, config('permission.models.team'));
        $this->assertSame('laboratory_id', config('permission.column_names.team_foreign_key'));
        $this->assertFalse(config('permission.enable_wildcard_permission'));
    }

    public function test_same_role_name_is_isolated_between_laboratories(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();

        $this->setPermissionTeam($laboratoryA);
        $roleA = Role::findOrCreate('administrator', 'web');
        $this->setPermissionTeam($laboratoryB);
        $roleB = Role::findOrCreate('administrator', 'web');

        $this->assertNotSame($roleA->getKey(), $roleB->getKey());
        $this->assertSame($laboratoryA->id, $roleA->laboratory_id);
        $this->assertSame($laboratoryB->id, $roleB->laboratory_id);
    }

    public function test_roles_and_permissions_follow_a_b_a_team_switches_without_stale_relations(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratoryA);
        $user->laboratories()->attach($laboratoryB, ['is_active' => true]);
        $create = Permission::findOrCreate('patients.create', 'web');
        $view = Permission::findOrCreate('patients.view', 'web');

        $administrator = $this->assignLaboratoryRole($user, $laboratoryA, 'administrator');
        $administrator->syncPermissions([$create, $view]);
        $viewer = $this->assignLaboratoryRole($user, $laboratoryB, 'viewer');
        $viewer->syncPermissions([$view]);

        $this->setPermissionTeam($laboratoryA);
        $this->assertTrue($user->hasRole('administrator'));
        $this->assertFalse($user->hasRole('viewer'));
        $this->assertTrue($user->can('patients.create'));

        $this->setPermissionTeam($laboratoryB);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse($user->hasRole('administrator'));
        $this->assertTrue($user->hasRole('viewer'));
        $this->assertFalse($user->can('patients.create'));
        $this->assertTrue($user->can('patients.view'));

        $this->setPermissionTeam($laboratoryA);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertTrue($user->hasRole('administrator'));
        $this->assertFalse($user->hasRole('viewer'));
    }

    public function test_direct_permission_is_tenant_aware(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratoryA);
        $user->laboratories()->attach($laboratoryB, ['is_active' => true]);

        $this->assignDirectLaboratoryPermission($user, $laboratoryA, 'patients.create');

        $this->assertTrue($user->can('patients.create'));
        $this->setPermissionTeam($laboratoryB);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse($user->can('patients.create'));
    }

    public function test_role_from_another_laboratory_cannot_be_assigned_in_current_team(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $user = User::factory()->create();
        $roleA = $this->assignLaboratoryRole($user, $laboratoryA, 'administrator');
        $user->removeRole($roleA);
        $this->setPermissionTeam($laboratoryB);

        try {
            DB::transaction(fn () => $user->assignRole($roleA));
            $this->fail('A role from another laboratory was accepted.');
        } catch (RoleDoesNotExist|QueryException) {
            $this->assertDatabaseMissing('model_has_roles', [
                'laboratory_id' => $laboratoryB->id,
                'role_id' => $roleA->id,
                'model_id' => $user->id,
                'model_type' => User::class,
            ]);
        }
    }

    public function test_middleware_sets_each_team_clears_stale_relations_and_cleans_up_after_request(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratoryA);
        $user->laboratories()->attach($laboratoryB, ['is_active' => true]);
        $this->createSubscription($laboratoryA);
        $this->createSubscription($laboratoryB);
        Permission::findOrCreate('patients.create', 'web');
        Permission::findOrCreate('patients.view', 'web');

        $administrator = $this->assignLaboratoryRole($user, $laboratoryA, 'administrator');
        $administrator->syncPermissions(['patients.create', 'patients.view']);
        $viewer = $this->assignLaboratoryRole($user, $laboratoryB, 'viewer');
        $viewer->syncPermissions(['patients.view']);

        $this->setPermissionTeam($laboratoryA);
        $user->load('roles');

        $this->teamRequest($user, $laboratoryA)
            ->assertOk()->assertJsonPath('team_id', $laboratoryA->id)
            ->assertJsonPath('administrator', true)->assertJsonPath('patients_create', true);
        $this->assertNull(getPermissionsTeamId());

        $this->teamRequest($user, $laboratoryB)
            ->assertOk()->assertJsonPath('team_id', $laboratoryB->id)
            ->assertJsonPath('administrator', false)->assertJsonPath('viewer', true)
            ->assertJsonPath('patients_create', false);
        $this->assertNull(getPermissionsTeamId());
        $this->assertFalse($user->relationLoaded('roles'));

        $this->teamRequest($user, $laboratoryA)
            ->assertOk()->assertJsonPath('administrator', true);
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_membership_and_subscription_remain_required_even_when_a_role_exists(): void
    {
        $laboratory = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratory, false);
        $this->assignLaboratoryRole($user, $laboratory, 'administrator');
        $this->createSubscription($laboratory);

        $this->teamRequest($user, $laboratory)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $user->laboratories()->updateExistingPivot($laboratory, ['is_active' => true]);
        $laboratory->subscriptions()->delete();

        $this->teamRequest($user, $laboratory)
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_protected_saas_endpoint_denies_member_without_permission(): void
    {
        $laboratory = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratory);
        $this->createSubscription($laboratory);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/patients')
            ->assertForbidden();
    }

    private function createSubscription(Laboratory $laboratory): void
    {
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    private function teamRequest(User $user, Laboratory $laboratory): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/testing/permission-team');
    }
}
