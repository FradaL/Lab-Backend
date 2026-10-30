<?php

namespace Tests\Feature\UserAdministration;

use App\Models\Laboratory;
use App\Models\LaboratoryUserInvitation;
use App\Models\Subscription;
use App\Models\User;
use App\Models\ValidationProfessional;
use App\Notifications\LaboratoryUserInvitationNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LaboratoryUserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    public function test_list_is_paginated_searchable_filterable_and_tenant_isolated(): void
    {
        [$laboratory, $owner] = $this->laboratoryWithOwner();
        $otherLaboratory = $this->activeLaboratory();
        $ana = $this->createLaboratoryMember($laboratory);
        $ana->update(['name' => 'Ana Técnica', 'email' => 'ana@example.test']);
        $inactive = $this->createLaboratoryMember($laboratory, false);
        $outsider = $this->createLaboratoryMember($otherLaboratory);
        $this->assignLaboratoryRole($ana, $laboratory, 'laboratory_technician');
        $this->assignLaboratoryRole($inactive, $laboratory, 'viewer');
        $this->assignLaboratoryRole($outsider, $otherLaboratory, 'laboratory_technician');
        ValidationProfessional::query()->create([
            'laboratory_id' => $laboratory->id,
            'user_id' => $ana->id,
            'professional_name' => 'Lic. Ana Técnica',
            'profession' => 'Química bióloga',
            'status' => 'registered',
        ]);

        $response = $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory/users?search=ANA&status=active&role=laboratory_technician&per_page=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ana->id)
            ->assertJsonPath('data.0.membership.status', 'active')
            ->assertJsonPath('data.0.roles.0', 'laboratory_technician')
            ->assertJsonPath('data.0.validation_professional.status', 'registered')
            ->assertJsonPath('data.0.can_register_validation_professional', false)
            ->assertJsonPath('meta.total', 1);
        $response->assertJsonMissing(['id' => $outsider->id]);

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory/users?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory/users/'.$outsider->id)
            ->assertNotFound();
    }

    public function test_invitation_creates_a_new_verified_identity_and_membership_only_after_acceptance(): void
    {
        Notification::fake();
        [$laboratory, $owner] = $this->laboratoryWithOwner();

        $response = $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory/users', [
                'name' => 'Nueva Colaboradora',
                'email' => 'NEW.USER@example.test',
                'role' => 'laboratory_technician',
            ]);

        $response->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseMissing('users', ['email' => 'new.user@example.test']);

        $token = $this->invitationToken();
        $this->postJson('/api/v1/laboratory-user-invitations/'.$token.'/accept', [
            'name' => 'Nueva Colaboradora',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ])->assertCreated();

        $user = User::query()->where('email', 'new.user@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-secure-password', $user->password));
        $this->assertDatabaseHas('laboratory_user', [
            'laboratory_id' => $laboratory->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'laboratory_id' => $laboratory->id,
            'model_id' => $user->id,
            'model_type' => User::class,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'laboratory_user.identity_created', 'auditable_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'laboratory_user.membership_accepted']);

        $this->postJson('/api/v1/laboratory-user-invitations/'.$token.'/accept', [])->assertStatus(410);
    }

    public function test_existing_identity_requires_its_own_authenticated_session_to_link(): void
    {
        Notification::fake();
        [$laboratory, $owner] = $this->laboratoryWithOwner();
        $existing = User::factory()->create(['email' => 'shared@example.test']);
        $attacker = User::factory()->create();

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory/users', [
                'name' => 'Nombre proporcionado por admin',
                'email' => $existing->email,
                'role' => 'viewer',
            ])
            ->assertAccepted();

        $token = $this->invitationToken();
        $this->postJson('/api/v1/laboratory-user-invitations/'.$token.'/accept', [])->assertForbidden();
        $this->actingAs($attacker)->postJson('/api/v1/laboratory-user-invitations/'.$token.'/accept', [])->assertForbidden();
        $this->assertDatabaseMissing('laboratory_user', ['laboratory_id' => $laboratory->id, 'user_id' => $existing->id]);

        $this->actingAs($existing)->postJson('/api/v1/laboratory-user-invitations/'.$token.'/accept', [])->assertCreated();
        $this->assertDatabaseHas('laboratory_user', ['laboratory_id' => $laboratory->id, 'user_id' => $existing->id]);
        $this->assertSame($existing->name, $existing->fresh()->name);
    }

    public function test_reissuing_an_invitation_revokes_the_previous_token_without_creating_a_duplicate_identity(): void
    {
        Notification::fake();
        [$laboratory, $owner] = $this->laboratoryWithOwner();
        $payload = [
            'name' => 'Invitada Única',
            'email' => 'duplicate@example.test',
            'role' => 'viewer',
        ];

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory/users', $payload)
            ->assertAccepted();
        $firstToken = $this->invitationToken();

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory/users', $payload)
            ->assertAccepted();
        $secondToken = $this->invitationToken();

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertDatabaseHas('laboratory_user_invitations', [
            'token_hash' => hash('sha256', $firstToken),
        ]);
        $this->assertNotNull(LaboratoryUserInvitation::query()
            ->where('token_hash', hash('sha256', $firstToken))
            ->value('revoked_at'));

        $this->postJson('/api/v1/laboratory-user-invitations/'.$firstToken.'/accept', [
            'name' => 'Invitada Única',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ])->assertGone();
        $this->postJson('/api/v1/laboratory-user-invitations/'.$secondToken.'/accept', [
            'name' => 'Invitada Única',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ])->assertCreated();

        $this->assertSame(1, User::query()->where('email', 'duplicate@example.test')->count());
        $this->assertSame(1, DB::table('laboratory_user')
            ->where('laboratory_id', $laboratory->id)
            ->whereIn('user_id', User::query()->where('email', 'duplicate@example.test')->select('id'))
            ->count());
    }

    public function test_administrator_only_sees_and_assigns_lower_roles_without_self_escalation(): void
    {
        $laboratory = $this->activeLaboratory();
        $administrator = $this->createLaboratoryMember($laboratory);
        $target = $this->createLaboratoryMember($laboratory);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($administrator, $laboratory, 'administrator');
        $this->assignLaboratoryRole($target, $laboratory, 'viewer');

        $this->actingAs($administrator)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory/roles')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonMissing(['name' => 'owner'])
            ->assertJsonMissing(['name' => 'administrator']);

        $this->actingAs($administrator)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory/users/'.$target->id.'/role', ['role' => 'owner'])
            ->assertForbidden();

        $this->assertDatabaseHas('laboratory_user', ['laboratory_id' => $laboratory->id, 'user_id' => $administrator->id]);
        $this->actingAs($administrator)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory/users/'.$administrator->id.'/role', ['role' => 'owner'])
            ->assertForbidden();
    }

    public function test_role_change_is_independent_per_laboratory(): void
    {
        [$laboratoryA, $ownerA] = $this->laboratoryWithOwner();
        $laboratoryB = $this->activeLaboratory();
        $user = $this->createLaboratoryMember($laboratoryA);
        $user->laboratories()->attach($laboratoryB, ['is_active' => true]);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratoryA, 'viewer');
        $this->assignLaboratoryRole($user, $laboratoryB, 'cashier');
        $this->assertSame(['viewer'], DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.laboratory_id', $laboratoryA->id)
            ->where('model_has_roles.model_id', $user->id)
            ->pluck('roles.name')->all());

        $this->actingAs($ownerA)
            ->withHeader('X-Laboratory-ID', (string) $laboratoryA->id)
            ->patchJson('/api/v1/laboratory/users/'.$user->id.'/role', ['role' => 'receptionist'])
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'receptionist');

        $this->setPermissionTeam($laboratoryB);
        $user->unsetRelation('roles');
        $this->assertTrue($user->hasExactRoles(['cashier']));
    }

    public function test_last_active_administrator_is_protected_and_deactivation_blocks_access(): void
    {
        [$laboratory, $owner] = $this->laboratoryWithOwner();

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory/users/'.$owner->id.'/status', ['status' => 'inactive'])
            ->assertConflict()
            ->assertJsonPath('code', 'laboratory_last_owner');

        $secondAdmin = $this->createLaboratoryMember($laboratory);
        $this->assignLaboratoryRole($secondAdmin, $laboratory, 'administrator');
        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory/users/'.$secondAdmin->id.'/status', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.membership.status', 'inactive');

        $this->actingAs($secondAdmin)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_missing_permission_and_cross_tenant_mutation_are_rejected(): void
    {
        [$laboratory, $owner] = $this->laboratoryWithOwner();
        $viewer = $this->createLaboratoryMember($laboratory);
        $otherLaboratory = $this->activeLaboratory();
        $outsider = $this->createLaboratoryMember($otherLaboratory);
        $this->assignLaboratoryRole($viewer, $laboratory, 'viewer');

        $this->actingAs($viewer)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory/users')
            ->assertForbidden();

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory/users/'.$outsider->id.'/status', ['status' => 'inactive'])
            ->assertNotFound();
        $this->assertDatabaseHas('laboratory_user', [
            'laboratory_id' => $otherLaboratory->id,
            'user_id' => $outsider->id,
            'is_active' => true,
        ]);
    }

    public function test_unknown_and_tenant_controlled_fields_are_rejected(): void
    {
        [$laboratory, $owner] = $this->laboratoryWithOwner();
        $member = $this->createLaboratoryMember($laboratory);
        $this->assignLaboratoryRole($member, $laboratory, 'viewer');

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory/users?unexpected=value')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unexpected');

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory/users', [
                'name' => 'Intento',
                'email' => 'attempt@example.test',
                'role' => 'viewer',
                'laboratory_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('laboratory_id');

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory/users/'.$member->id.'/status', [
                'status' => 'inactive',
                'unexpected' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unexpected');
        $this->assertDatabaseHas('laboratory_user', [
            'laboratory_id' => $laboratory->id,
            'user_id' => $member->id,
            'is_active' => true,
        ]);
    }

    public function test_runtime_and_openapi_expose_the_exact_user_administration_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory/users')
                || $route->uri() === 'api/v1/laboratory/roles'
                || $route->uri() === 'api/v1/laboratory-user-invitations/{token}/accept');

        $this->assertSame([
            'GET api/v1/laboratory/roles',
            'GET api/v1/laboratory/users',
            'GET api/v1/laboratory/users/{user}',
            'PATCH api/v1/laboratory/users/{user}/role',
            'PATCH api/v1/laboratory/users/{user}/status',
            'POST api/v1/laboratory-user-invitations/{token}/accept',
            'POST api/v1/laboratory/users',
        ], $routes->map(fn ($route): string => $route->methods()[0].' '.$route->uri())->sort()->values()->all());

        $protected = $routes->reject(fn ($route): bool => str_contains($route->uri(), 'invitations'));
        $this->assertTrue($protected->every(fn ($route): bool => in_array('saas', $route->gatherMiddleware(), true)));
        $this->assertTrue($protected->every(fn ($route): bool => collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'can:'))));

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['get', 'post'], array_keys($document['paths']['/api/v1/laboratory/users']));
        $this->assertSame(['get'], array_keys($document['paths']['/api/v1/laboratory/users/{user}']));
        $this->assertSame(['patch'], array_keys($document['paths']['/api/v1/laboratory/users/{user}/role']));
        $this->assertSame(['patch'], array_keys($document['paths']['/api/v1/laboratory/users/{user}/status']));
        $this->assertSame(['get'], array_keys($document['paths']['/api/v1/laboratory/roles']));
        $this->assertSame(['post'], array_keys($document['paths']['/api/v1/laboratory-user-invitations/{token}/accept']));
        $this->assertFalse($document['components']['schemas']['InviteLaboratoryUserInput']['additionalProperties']);
        $this->assertFalse($document['components']['schemas']['AcceptLaboratoryUserInvitationInput']['additionalProperties']);
    }

    public function test_audit_failure_rolls_back_status_change(): void
    {
        [$laboratory, $owner] = $this->laboratoryWithOwner();
        $member = $this->createLaboratoryMember($laboratory);
        $this->assignLaboratoryRole($member, $laboratory, 'viewer');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_laboratory_user_status_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event = 'laboratory_user.status_changed' THEN
        RAISE EXCEPTION 'forced audit failure';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER reject_laboratory_user_status_audit
BEFORE INSERT ON audit_logs
FOR EACH ROW EXECUTE FUNCTION reject_laboratory_user_status_audit();
SQL);

        $this->actingAs($owner)
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory/users/'.$member->id.'/status', ['status' => 'inactive'])
            ->assertServerError();

        $this->assertDatabaseHas('laboratory_user', [
            'laboratory_id' => $laboratory->id,
            'user_id' => $member->id,
            'is_active' => true,
        ]);
    }

    /** @return array{Laboratory, User} */
    private function laboratoryWithOwner(): array
    {
        $laboratory = $this->activeLaboratory();
        $owner = $this->createLaboratoryMember($laboratory);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($owner, $laboratory, 'owner');

        return [$laboratory, $owner];
    }

    private function activeLaboratory(): Laboratory
    {
        $laboratory = Laboratory::factory()->create();
        Subscription::factory()->create(['laboratory_id' => $laboratory->id]);

        return $laboratory;
    }

    private function invitationToken(): string
    {
        $token = '';
        Notification::assertSentOnDemand(
            LaboratoryUserInvitationNotification::class,
            function (LaboratoryUserInvitationNotification $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );
        $this->assertNotSame('', $token);
        $this->assertSame(hash('sha256', $token), LaboratoryUserInvitation::query()->latest('id')->value('token_hash'));

        return $token;
    }
}
