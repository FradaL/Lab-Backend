<?php

namespace App\UserAdministration;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Authorization\RbacCatalog;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\LaboratoryUserInvitation;
use App\Models\User;
use App\Notifications\LaboratoryUserInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final class LaboratoryUserManager
{
    private const array LOWER_ROLES = [
        'receptionist', 'cashier', 'laboratory_technician', 'viewer',
    ];

    private const array ADMINISTRATIVE_ROLES = ['owner', 'administrator'];

    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  array{name: string, email: string, role: string}  $input
     * @return array{invitation: LaboratoryUserInvitation, token: string}
     */
    public function invite(Laboratory $laboratory, User $actor, array $input): array
    {
        $email = Str::lower(trim($input['email']));
        $result = DB::transaction(function () use ($laboratory, $actor, $input, $email): array {
            Laboratory::query()->whereKey($laboratory->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeActor($laboratory, $actor, 'users.create', true);
            $this->assertAssignableRole($laboratory, $actor, $input['role']);

            $existingMember = DB::table('users')
                ->join('laboratory_user', 'laboratory_user.user_id', '=', 'users.id')
                ->where('laboratory_user.laboratory_id', $laboratory->getKey())
                ->whereRaw('LOWER(users.email) = ?', [$email])
                ->exists();

            if ($existingMember) {
                throw new UserAdministrationConflict(
                    'laboratory_user_invitation_unavailable',
                    'No fue posible procesar la invitación.',
                );
            }

            LaboratoryUserInvitation::query()
                ->where('laboratory_id', $laboratory->getKey())
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $token = Str::random(64);
            $invitation = LaboratoryUserInvitation::query()->create([
                'laboratory_id' => $laboratory->getKey(),
                'email' => $email,
                'name' => trim($input['name']),
                'role' => $input['role'],
                'token_hash' => hash('sha256', $token),
                'invited_by' => $actor->getKey(),
                'expires_at' => now()->addHours(max(1, (int) config('laboratory_users.invitation_ttl_hours'))),
            ]);

            $this->audit->record($laboratory, $actor, new AuditEvent(
                'laboratory_user.invitation_issued',
                'laboratory_user_invitation',
                $invitation->getKey(),
                newValues: ['role' => $invitation->role, 'status' => 'pending'],
            ));

            return ['invitation' => $invitation, 'token' => $token];
        });

        $result['invitation']->load('laboratory');
        Notification::route('mail', $result['invitation']->email)
            ->notify(new LaboratoryUserInvitationNotification($result['invitation'], $result['token']));

        return $result;
    }

    /**
     * @param  array{name?: string|null, password?: string|null}  $input
     */
    public function accept(string $token, array $input, ?User $authenticatedUser): User
    {
        return DB::transaction(function () use ($token, $input, $authenticatedUser): User {
            $invitation = LaboratoryUserInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (
                $invitation === null
                || $invitation->accepted_at !== null
                || $invitation->revoked_at !== null
                || $invitation->expires_at->isPast()
            ) {
                throw new UserAdministrationConflict(
                    'laboratory_user_invitation_invalid',
                    'La invitación no existe, expiró o ya fue utilizada.',
                    Response::HTTP_GONE,
                );
            }

            $laboratory = Laboratory::query()->whereKey($invitation->laboratory_id)->lockForUpdate()->firstOrFail();
            if (! $laboratory->is_active) {
                throw new UserAdministrationConflict(
                    'laboratory_user_invitation_laboratory_inactive',
                    'El laboratorio de la invitación no está activo.',
                );
            }
            $this->lockInvitationEmail($invitation->email);

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$invitation->email])
                ->lockForUpdate()
                ->first();

            if ($user !== null) {
                if ($authenticatedUser?->getKey() !== $user->getKey() || ! $user->is_active) {
                    throw new UserAdministrationConflict(
                        'laboratory_user_invitation_identity_required',
                        'Debe iniciar sesión con la identidad destinataria activa para aceptar esta invitación.',
                        Response::HTTP_FORBIDDEN,
                    );
                }
            } else {
                $name = trim((string) ($input['name'] ?? ''));
                $password = (string) ($input['password'] ?? '');

                if ($name === '' || $password === '') {
                    throw new UserAdministrationConflict(
                        'laboratory_user_invitation_registration_required',
                        'Una identidad nueva debe proporcionar nombre y contraseña.',
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    );
                }

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $invitation->email,
                    'password' => Hash::make($password),
                    'is_active' => true,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $this->audit->record($laboratory, $user, new AuditEvent(
                    'laboratory_user.identity_created',
                    'user',
                    $user->getKey(),
                    newValues: ['status' => 'active'],
                    metadata: ['invitation_id' => $invitation->getKey()],
                ));
            }

            if (LaboratoryUser::query()->where('laboratory_id', $laboratory->getKey())->where('user_id', $user->getKey())->exists()) {
                throw new UserAdministrationConflict(
                    'laboratory_user_membership_exists',
                    'La identidad ya posee una membresía en este laboratorio.',
                );
            }

            $membership = LaboratoryUser::query()->create([
                'laboratory_id' => $laboratory->getKey(),
                'user_id' => $user->getKey(),
                'is_active' => true,
                'is_default' => ! LaboratoryUser::query()->where('user_id', $user->getKey())->where('is_default', true)->exists(),
            ]);

            $this->withinPermissionTeam($laboratory, function () use ($user, $invitation): void {
                $role = Role::findByName($invitation->role, RbacCatalog::GUARD);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncRoles([$role]);
                $user->unsetRelation('roles')->unsetRelation('permissions');
            });

            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->getKey()]);
            $this->audit->record($laboratory, $user, new AuditEvent(
                'laboratory_user.membership_accepted',
                'laboratory_user',
                $membership->getKey(),
                newValues: ['status' => 'active', 'role' => $invitation->role],
                metadata: ['invitation_id' => $invitation->getKey(), 'target_user_id' => $user->getKey()],
            ));

            return $user;
        });
    }

    /** @return list<string> */
    public function assignableRoles(Laboratory $laboratory, User $actor, string $permission = 'roles.view'): array
    {
        $this->authorizeActor($laboratory, $actor, $permission);

        return match ($this->actorLevel($laboratory, $actor)) {
            2 => RbacCatalog::ROLES,
            1 => self::LOWER_ROLES,
            default => [],
        };
    }

    public function changeRole(Laboratory $laboratory, User $actor, User $target, string $roleName): User
    {
        return DB::transaction(function () use ($laboratory, $actor, $target, $roleName): User {
            Laboratory::query()->whereKey($laboratory->getKey())->lockForUpdate()->firstOrFail();
            $membership = $this->lockMembership($laboratory, $target);
            $this->authorizeActor($laboratory, $actor, 'users.assign_role', true);
            $this->assertAssignableRole($laboratory, $actor, $roleName);

            $oldRoles = $this->roleNames($laboratory, $target);
            $actorLevel = $this->actorLevel($laboratory, $actor);
            if ($this->hasAdministrativeRole($oldRoles) && $actorLevel < 2) {
                abort(Response::HTTP_FORBIDDEN, 'Solo un owner puede modificar roles administrativos.');
            }

            if (in_array($roleName, $oldRoles, true) && count($oldRoles) === 1) {
                return $this->memberForResponse($laboratory, $target);
            }

            if ($membership->is_active && $this->hasAdministrativeRole($oldRoles) && ! in_array($roleName, self::ADMINISTRATIVE_ROLES, true)) {
                $this->assertAnotherActiveAdministrator($laboratory, $target);
            }

            $this->withinPermissionTeam($laboratory, function () use ($target, $roleName): void {
                $target->unsetRelation('roles')->unsetRelation('permissions');
                $target->syncRoles([Role::findByName($roleName, RbacCatalog::GUARD)]);
                $target->unsetRelation('roles')->unsetRelation('permissions');
            });

            $this->audit->record($laboratory, $actor, new AuditEvent(
                'laboratory_user.role_changed',
                'laboratory_user',
                $membership->getKey(),
                oldValues: ['roles' => $oldRoles],
                newValues: ['roles' => [$roleName]],
                metadata: ['target_user_id' => $target->getKey()],
            ));

            return $this->memberForResponse($laboratory, $target);
        });
    }

    public function changeStatus(Laboratory $laboratory, User $actor, User $target, string $status): User
    {
        return DB::transaction(function () use ($laboratory, $actor, $target, $status): User {
            Laboratory::query()->whereKey($laboratory->getKey())->lockForUpdate()->firstOrFail();
            $membership = $this->lockMembership($laboratory, $target);
            $this->authorizeActor($laboratory, $actor, 'users.change_status', true);
            $newActive = $status === 'active';
            $targetRoles = $this->roleNames($laboratory, $target);

            if ($this->hasAdministrativeRole($targetRoles) && $this->actorLevel($laboratory, $actor) < 2) {
                abort(Response::HTTP_FORBIDDEN, 'Solo un owner puede cambiar el estado de otro miembro administrativo.');
            }

            if ($membership->is_active === $newActive) {
                return $this->memberForResponse($laboratory, $target);
            }

            if (! $newActive && $this->hasAdministrativeRole($targetRoles)) {
                $this->assertAnotherActiveAdministrator($laboratory, $target);
            }

            if ($newActive && ! $target->is_active) {
                throw new UserAdministrationConflict(
                    'laboratory_user_global_identity_inactive',
                    'La identidad global está inactiva y no puede habilitarse desde un laboratorio.',
                );
            }

            $oldStatus = $membership->is_active ? 'active' : 'inactive';
            $membership->update(['is_active' => $newActive]);
            $this->audit->record($laboratory, $actor, new AuditEvent(
                'laboratory_user.status_changed',
                'laboratory_user',
                $membership->getKey(),
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $status],
                metadata: ['target_user_id' => $target->getKey()],
            ));

            return $this->memberForResponse($laboratory, $target);
        });
    }

    private function assertAssignableRole(Laboratory $laboratory, User $actor, string $role): void
    {
        if (! in_array($role, $this->assignableRoles($laboratory, $actor, 'users.assign_role'), true)) {
            abort(Response::HTTP_FORBIDDEN, 'No está autorizado para asignar ese rol.');
        }
    }

    private function authorizeActor(Laboratory $laboratory, User $actor, string $permission, bool $lock = false): void
    {
        $userQuery = User::query()->whereKey($actor->getKey())->where('is_active', true);
        $membershipQuery = LaboratoryUser::query()
            ->where('laboratory_id', $laboratory->getKey())
            ->where('user_id', $actor->getKey())
            ->where('is_active', true);

        if ($lock) {
            $userQuery->sharedLock();
            $membershipQuery->sharedLock();
        }

        if ($userQuery->first() === null || $membershipQuery->first() === null || (int) getPermissionsTeamId() !== (int) $laboratory->getKey()) {
            abort(Response::HTTP_FORBIDDEN, 'Se requiere una identidad y membresía activas en el laboratorio actual.');
        }

        if ($lock) {
            DB::table('model_has_roles')->where('laboratory_id', $laboratory->getKey())->where('model_id', $actor->getKey())->where('model_type', $actor->getMorphClass())->sharedLock()->get();
            DB::table('model_has_permissions')->where('laboratory_id', $laboratory->getKey())->where('model_id', $actor->getKey())->where('model_type', $actor->getMorphClass())->sharedLock()->get();
        }

        $actor->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::forUser($actor)->authorize($permission);
    }

    private function actorLevel(Laboratory $laboratory, User $actor): int
    {
        $roles = $this->roleNames($laboratory, $actor);

        return in_array('owner', $roles, true) ? 2 : (in_array('administrator', $roles, true) ? 1 : 0);
    }

    /** @return list<string> */
    private function roleNames(Laboratory $laboratory, User $user): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.laboratory_id', $laboratory->getKey())
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('roles.laboratory_id', $laboratory->getKey())
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    /** @param list<string> $roles */
    private function hasAdministrativeRole(array $roles): bool
    {
        return array_intersect($roles, self::ADMINISTRATIVE_ROLES) !== [];
    }

    private function assertAnotherActiveAdministrator(Laboratory $laboratory, User $target): void
    {
        $exists = DB::table('laboratory_user')
            ->join('users', 'users.id', '=', 'laboratory_user.user_id')
            ->join('model_has_roles', function ($join) use ($target): void {
                $join->on('model_has_roles.model_id', '=', 'laboratory_user.user_id')
                    ->where('model_has_roles.model_type', '=', $target->getMorphClass());
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('laboratory_user.laboratory_id', $laboratory->getKey())
            ->where('laboratory_user.is_active', true)
            ->where('users.is_active', true)
            ->where('model_has_roles.laboratory_id', $laboratory->getKey())
            ->where('roles.laboratory_id', $laboratory->getKey())
            ->whereIn('roles.name', self::ADMINISTRATIVE_ROLES)
            ->where('laboratory_user.user_id', '!=', $target->getKey())
            ->exists();

        if (! $exists) {
            throw new UserAdministrationConflict(
                'laboratory_last_administrator',
                'El laboratorio debe conservar al menos un owner o administrator activo.',
            );
        }
    }

    private function lockMembership(Laboratory $laboratory, User $target): LaboratoryUser
    {
        User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

        return LaboratoryUser::query()
            ->where('laboratory_id', $laboratory->getKey())
            ->where('user_id', $target->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function memberForResponse(Laboratory $laboratory, User $user): User
    {
        return User::query()
            ->select('users.*')
            ->addSelect([
                'membership_id' => LaboratoryUser::query()->select('id')->where('laboratory_id', $laboratory->getKey())->whereColumn('user_id', 'users.id')->limit(1),
                'membership_is_active' => LaboratoryUser::query()->select('is_active')->where('laboratory_id', $laboratory->getKey())->whereColumn('user_id', 'users.id')->limit(1),
                'membership_is_default' => LaboratoryUser::query()->select('is_default')->where('laboratory_id', $laboratory->getKey())->whereColumn('user_id', 'users.id')->limit(1),
                'membership_created_at' => LaboratoryUser::query()->select('created_at')->where('laboratory_id', $laboratory->getKey())->whereColumn('user_id', 'users.id')->limit(1),
                'membership_updated_at' => LaboratoryUser::query()->select('updated_at')->where('laboratory_id', $laboratory->getKey())->whereColumn('user_id', 'users.id')->limit(1),
            ])
            ->with([
                'roles' => fn ($query) => $query->where('roles.laboratory_id', $laboratory->getKey())->orderBy('name'),
                'validationProfessionals' => fn ($query) => $query->where('laboratory_id', $laboratory->getKey()),
            ])
            ->findOrFail($user->getKey());
    }

    private function withinPermissionTeam(Laboratory $laboratory, callable $callback): mixed
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($laboratory->getKey());

        try {
            return $callback();
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    private function lockInvitationEmail(string $email): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \LogicException('Laboratory user invitation acceptance requires PostgreSQL.');
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
    }
}
