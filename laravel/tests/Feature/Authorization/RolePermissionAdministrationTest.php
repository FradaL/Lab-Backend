<?php

namespace Tests\Feature\Authorization;

use App\Authorization\LaboratoryRoleProvisioner;
use App\Authorization\RbacCatalog;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $lab = Laboratory::factory()->create();
        Subscription::factory()->create(['laboratory_id' => $lab->id]);
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($owner, $lab, 'owner');
        $this->actingAs($owner)->withHeader('X-Laboratory-ID', (string) $lab->id);

        return [$lab, $owner];
    }

    private function replace(string $role, array $permissions, int $revision = 1)
    {
        return $this->putJson('/api/v1/laboratory/roles/'.$role.'/permissions', [
            'expected_revision' => $revision, 'permissions' => $permissions, 'reason' => 'Cambio de responsabilidades',
        ]);
    }

    public function test_catalog_and_protected_matrices_are_consultable(): void
    {
        [$lab] = $this->context();
        $response = $this->getJson('/api/v1/laboratory/permissions')->assertOk()->assertJsonCount(count(RbacCatalog::PERMISSIONS), 'data');
        $this->assertEqualsCanonicalizing(RbacCatalog::PERMISSIONS, array_column($response->json('data'), 'code'));
        foreach ($response->json('data') as $item) {
            $this->assertNotEmpty($item['group']);
            $this->assertNotEmpty($item['label']);
            $this->assertFalse(str_starts_with($item['code'], 'saas.'));
        }
        $owner = $this->getJson('/api/v1/laboratory/roles/owner/permissions')->assertOk()
            ->assertJsonPath('data.protected', true)->assertJsonPath('data.editable', false)->assertJsonPath('data.revision', 1);
        $this->assertEqualsCanonicalizing(RbacCatalog::PERMISSIONS, $owner->json('data.permissions'));
        $this->assertDatabaseCount('roles', 6);
        $this->getJson('/api/v1/laboratory/roles/viewer/permissions')->assertOk()->assertJsonPath('data.editable', true);
        $id = DB::table('roles')->where('laboratory_id', $lab->id)->where('name', 'viewer')->value('id');
        $this->getJson('/api/v1/laboratory/roles/'.$id.'/permissions')->assertOk()->assertJsonPath('data.name', 'viewer');
    }

    public function test_delegation_can_be_granted_redelegated_and_revoked_without_owner_role(): void
    {
        [$lab, $owner] = $this->context();
        $viewer = $this->createLaboratoryMember($lab);
        $cashier = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($viewer, $lab, 'viewer');
        $this->assignLaboratoryRole($cashier, $lab, 'cashier');
        $this->actingAs($owner);
        $this->replace('viewer', ['roles.permissions.manage'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($viewer);
        $this->replace('cashier', ['roles.permissions.manage', 'orders.manage_discount', 'results.validate', 'results.rectify', 'reports.delivery_exceptions.authorize'])->assertOk();
        $this->actingAs($cashier);
        $this->replace('receptionist', ['roles.permissions.manage'])->assertOk();
        // Revoke another manager; same authenticated User object must lose access next request.
        $this->replace('viewer', [], 2)->assertOk();
        $this->actingAs($viewer);
        $this->getJson('/api/v1/laboratory/permissions')->assertForbidden();
        $this->replace('cashier', [])->assertForbidden();
        $this->actingAs($cashier);
        $this->replace('cashier', [], 2)->assertOk();
        $this->getJson('/api/v1/laboratory/permissions')->assertForbidden();
    }

    public function test_protected_unknown_saas_duplicate_and_controlled_fields_are_rejected(): void
    {
        $this->context();
        foreach (['owner', 'administrator'] as $role) {
            $this->replace($role, [])->assertForbidden();
        }
        foreach ([['saas.access.manage'], ['unknown.permission'], ['orders.view', 'orders.view']] as $permissions) {
            $this->replace('viewer', $permissions)->assertUnprocessable();
        }
        foreach (['laboratory_id', 'guard_name', 'unexpected'] as $field) {
            $this->putJson('/api/v1/laboratory/roles/viewer/permissions', [
                'permissions' => [], 'expected_revision' => 1, 'reason' => 'Cambio', $field => null,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->putJson('/api/v1/laboratory/roles/viewer/permissions', ['permissions' => []])->assertUnprocessable();
        $this->replace('custom_role', [])->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'role.permissions_replaced']);
    }

    public function test_revision_and_audit_capture_full_replacement_and_reject_stale_writes(): void
    {
        [$lab, $owner] = $this->context();
        $before = $this->getJson('/api/v1/laboratory/roles/viewer/permissions')->json('data.permissions');
        $this->replace('viewer', ['results.validate', 'roles.permissions.manage'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->replace('viewer', [], 1)->assertConflict()->assertJsonPath('code', 'role_permissions_revision_conflict');
        $audit = AuditLog::query()->where('event', 'role.permissions_replaced')->sole();
        $this->assertSame($lab->id, $audit->laboratory_id);
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertNotNull($audit->created_at);
        $this->assertSame(['revision' => 1, 'permissions' => $before], $audit->old_values);
        $this->assertSame(['revision' => 2, 'permissions' => ['results.validate', 'roles.permissions.manage']], $audit->new_values);
        $this->assertSame('Cambio de responsabilidades', $audit->metadata['reason']);
        $this->assertEqualsCanonicalizing($before, $audit->metadata['removed']);
        $this->assertSame(['results.validate', 'roles.permissions.manage'], $audit->metadata['added']);
        $this->replace('viewer', [], 2)->assertOk()->assertJsonPath('data.permissions', [])->assertJsonPath('data.revision', 3);
    }

    public function test_audit_failure_rolls_back_pivots_and_revision(): void
    {
        $this->context();
        $before = $this->getJson('/api/v1/laboratory/roles/viewer/permissions')->json('data');
        DB::unprepared("CREATE FUNCTION reject_role_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event = 'role.permissions_replaced' THEN RAISE EXCEPTION 'forced audit failure'; END IF; RETURN NEW; END $$");
        DB::unprepared('CREATE TRIGGER reject_role_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_role_audit()');
        $this->replace('viewer', [])->assertStatus(500);
        $this->assertSame($before, $this->getJson('/api/v1/laboratory/roles/viewer/permissions')->assertOk()->json('data'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'role.permissions_replaced']);
    }

    public function test_multiple_roles_and_direct_permissions_preserve_effective_authorization(): void
    {
        [$lab, $owner] = $this->context();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($actor, $lab, 'viewer');
        $this->assignLaboratoryRole($actor, $lab, 'cashier');
        $this->actingAs($owner);
        $this->replace('viewer', ['roles.permissions.manage'])->assertOk();
        $this->replace('cashier', ['roles.permissions.manage'])->assertOk();
        $this->actingAs($actor);
        $this->getJson('/api/v1/laboratory/permissions')->assertOk();
        $this->replace('viewer', [], 2)->assertOk();
        $this->getJson('/api/v1/laboratory/permissions')->assertOk();
        $this->assignDirectLaboratoryPermission($actor, $lab, 'roles.permissions.manage');
        $this->replace('cashier', [], 2)->assertOk();
        $this->getJson('/api/v1/laboratory/permissions')->assertOk();
        $this->setPermissionTeam($lab);
        $actor->revokePermissionTo('roles.permissions.manage');
        $this->getJson('/api/v1/laboratory/permissions')->assertForbidden();
    }

    public function test_cross_tenant_roles_and_multi_laboratory_users_are_isolated(): void
    {
        [$lab, $owner] = $this->context();
        $other = Laboratory::factory()->create();
        Subscription::factory()->create(['laboratory_id' => $other->id]);
        $owner->laboratories()->attach($other, ['is_active' => true]);
        app(LaboratoryRoleProvisioner::class)->provision($other);
        $this->assignLaboratoryRole($owner, $other, 'viewer');
        $otherRole = DB::table('roles')->where('laboratory_id', $other->id)->where('name', 'viewer')->value('id');
        $this->getJson('/api/v1/laboratory/roles/'.$otherRole.'/permissions')->assertNotFound();
        $this->replace((string) $otherRole, [])->assertNotFound();
        $this->withHeader('X-Laboratory-ID', (string) $other->id)->getJson('/api/v1/laboratory/permissions')->assertForbidden();
        $this->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson('/api/v1/laboratory/permissions')->assertOk();
        DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $owner->id)->update(['is_active' => false]);
        $this->getJson('/api/v1/laboratory/permissions')->assertForbidden();
    }

    public function test_openapi_exposes_the_matrix_contract_and_authorized_catalog(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $paths = $document['paths'];
        $this->assertArrayHasKey('get', $paths['/api/v1/laboratory/permissions']);
        $matrix = $paths['/api/v1/laboratory/roles/{role}/permissions'];
        $this->assertArrayHasKey('get', $matrix);
        $this->assertArrayHasKey('put', $matrix);
        $this->assertArrayHasKey('409', $matrix['put']['responses']);
        $this->assertSame('#/components/schemas/ReplaceRolePermissions', $matrix['put']['requestBody']['content']['application/json']['schema']['$ref']);
        $input = $document['components']['schemas']['ReplaceRolePermissions'];
        $this->assertFalse($input['additionalProperties']);
        $this->assertTrue($input['properties']['permissions']['uniqueItems']);
        $this->assertEqualsCanonicalizing(RbacCatalog::PERMISSIONS, $input['properties']['permissions']['items']['enum']);
    }

    public function test_context_header_membership_and_subscription_are_enforced(): void
    {
        [$lab] = $this->context();
        $this->withoutHeader('X-Laboratory-ID')->getJson('/api/v1/laboratory/permissions')->assertStatus(400);
        $this->withHeader('X-Laboratory-ID', 'invalid')->getJson('/api/v1/laboratory/permissions')->assertStatus(400);
        $this->withHeader('X-Laboratory-ID', (string) $lab->id);
        Subscription::query()->where('laboratory_id', $lab->id)->delete();
        $this->getJson('/api/v1/laboratory/permissions')->assertForbidden();
        $this->replace('viewer', [])->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'role.permissions_replaced']);
    }

    public function test_last_owner_cannot_be_demoted_or_disabled_when_only_an_administrator_remains(): void
    {
        [$lab, $owner] = $this->context();
        $admin = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($admin, $lab, 'administrator');
        $inactiveOwner = $this->createLaboratoryMember($lab, false);
        $this->assignLaboratoryRole($inactiveOwner, $lab, 'owner');
        $this->actingAs($owner);
        $this->patchJson('/api/v1/laboratory/users/'.$owner->id.'/role', ['role' => 'administrator'])
            ->assertConflict()->assertJsonPath('code', 'laboratory_last_owner');
        $this->patchJson('/api/v1/laboratory/users/'.$owner->id.'/status', ['status' => 'inactive'])
            ->assertConflict()->assertJsonPath('code', 'laboratory_last_owner');
        $other = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($other, $lab, 'owner');
        $this->patchJson('/api/v1/laboratory/users/'.$owner->id.'/role', ['role' => 'administrator'])->assertOk();
    }

    public function test_reprovisioning_preserves_custom_matrices_and_new_laboratories_get_current_owner_defaults(): void
    {
        [$lab] = $this->context();
        $this->replace('viewer', [])->assertOk();
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray();
        app(LaboratoryRoleProvisioner::class)->provision($lab);
        $this->assertEquals($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray());
        $new = Laboratory::factory()->create();
        app(LaboratoryRoleProvisioner::class)->provision($new);
        $owner = DB::table('roles')->where('laboratory_id', $new->id)->where('name', 'owner')->value('id');
        $codes = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('role_id', $owner)->pluck('name')->all();
        $this->assertEqualsCanonicalizing(RbacCatalog::PERMISSIONS, $codes);
        $this->assertSame(6, DB::table('roles')->where('laboratory_id', $new->id)->count());
        $this->getJson('/api/v1/laboratory/roles/viewer/permissions')->assertOk()->assertJsonPath('data.permissions', [])->assertJsonPath('data.revision', 2);
    }
}
