<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\RbacCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryUser\IndexLaboratoryUserRequest;
use App\Http\Requests\Api\V1\LaboratoryUser\InviteLaboratoryUserRequest;
use App\Http\Requests\Api\V1\LaboratoryUser\UpdateLaboratoryUserRoleRequest;
use App\Http\Requests\Api\V1\LaboratoryUser\UpdateLaboratoryUserStatusRequest;
use App\Http\Resources\Api\V1\LaboratoryUserResource;
use App\Models\Laboratory;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use App\UserAdministration\LaboratoryUserManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class LaboratoryUserController extends Controller
{
    #[OA\Get(
        path: '/api/v1/laboratory/users',
        operationId: 'laboratoryUsersIndex',
        summary: 'Listar colaboradores del laboratorio',
        description: 'Lista exclusivamente identidades con membresía en el laboratorio activo. Admite búsqueda local por nombre/correo, estado, rol, paginación y orden estable. Requiere users.view.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory users'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'role', in: 'query', schema: new OA\Schema(type: 'string', enum: RbacCatalog::ROLES)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['name', 'email', 'created_at'], default: 'name')),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado tenant-aware.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryUserListResponse')),
            new OA\Response(response: 401, description: 'Sin sesión autenticada.'),
            new OA\Response(response: 403, description: 'Membresía, suscripción o permiso inválido.'),
            new OA\Response(response: 422, description: 'Filtros inválidos.'),
        ],
    )]
    public function index(IndexLaboratoryUserRequest $request, CurrentLaboratory $current): AnonymousResourceCollection
    {
        $laboratory = $current->get();
        $query = $this->memberQuery($laboratory);

        if (($search = $request->search()) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
                $pattern = "%{$escaped}%";

                $query->whereRaw("users.name {$operator} ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("users.email {$operator} ? ESCAPE '!'", [$pattern]);
            });
        }

        if (($status = $request->status()) !== null) {
            $query->where('laboratory_user.is_active', $status === 'active');
        }

        if (($role = $request->role()) !== null) {
            $query->whereHas('roles', fn (Builder $roles) => $roles
                ->where('roles.laboratory_id', $laboratory->getKey())
                ->where('roles.name', $role));
        }

        $sortColumn = $request->sort() === 'created_at' ? 'laboratory_user.created_at' : 'users.'.$request->sort();
        $query->orderBy($sortColumn, $request->direction())->orderBy('users.id');

        return LaboratoryUserResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    #[OA\Post(
        path: '/api/v1/laboratory/users',
        operationId: 'laboratoryUsersInvite',
        summary: 'Invitar a un colaborador',
        description: 'Emite una invitación expirable y de un solo uso. No revela si el correo ya posee una identidad global. Una identidad existente debe autenticarse como titular al aceptar; una nueva define su propia contraseña. Requiere users.create y users.assign_role.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory users'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InviteLaboratoryUserInput')),
        responses: [
            new OA\Response(response: 202, description: 'Invitación emitida sin revelar la existencia global del correo.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryUserInvitationResponse')),
            new OA\Response(response: 401, description: 'Sin sesión autenticada.'),
            new OA\Response(response: 403, description: 'Sin permisos o rol no asignable.'),
            new OA\Response(response: 409, description: 'La invitación no puede emitirse.'),
            new OA\Response(response: 422, description: 'Entrada inválida.'),
        ],
    )]
    public function store(InviteLaboratoryUserRequest $request, CurrentLaboratory $current, LaboratoryUserManager $manager): JsonResponse
    {
        $result = $manager->invite($current->get(), $request->user(), $request->validated());

        return response()->json([
            'message' => 'Si el correo puede recibir invitaciones, se enviaron las instrucciones de acceso.',
            'data' => [
                'invitation_id' => $result['invitation']->getKey(),
                'status' => 'pending',
                'expires_at' => $result['invitation']->expires_at->toISOString(),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    #[OA\Get(
        path: '/api/v1/laboratory/users/{user}',
        operationId: 'laboratoryUsersShow',
        summary: 'Consultar un colaborador',
        description: 'Devuelve la identidad, membresía, roles y estado profesional exclusivamente en el laboratorio activo. Requiere users.view.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle local.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryUserResponse')),
            new OA\Response(response: 404, description: 'No existe en el laboratorio activo.'),
        ],
    )]
    public function show(CurrentLaboratory $current, int $user): LaboratoryUserResource
    {
        return LaboratoryUserResource::make($this->memberQuery($current->get())->findOrFail($user));
    }

    #[OA\Patch(
        path: '/api/v1/laboratory/users/{user}/role',
        operationId: 'laboratoryUsersUpdateRole',
        summary: 'Cambiar el rol local',
        description: 'Reemplaza los roles del usuario solo en el laboratorio activo. Owner puede asignar cualquier rol; administrator solo roles operativos. Protege owner y el último administrador activo. Requiere users.assign_role.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateLaboratoryUserRoleInput')),
        responses: [
            new OA\Response(response: 200, description: 'Rol actualizado.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryUserResponse')),
            new OA\Response(response: 403, description: 'Autoescalamiento o administración del rol no autorizada.'),
            new OA\Response(response: 404, description: 'No existe en el laboratorio activo.'),
            new OA\Response(response: 409, description: 'Se protegería el último administrador activo.'),
            new OA\Response(response: 422, description: 'Rol inválido.'),
        ],
    )]
    public function updateRole(UpdateLaboratoryUserRoleRequest $request, CurrentLaboratory $current, LaboratoryUserManager $manager, int $user): LaboratoryUserResource
    {
        $target = $this->memberQuery($current->get())->findOrFail($user);

        return LaboratoryUserResource::make($manager->changeRole($current->get(), $request->user(), $target, $request->validated('role')));
    }

    #[OA\Patch(
        path: '/api/v1/laboratory/users/{user}/status',
        operationId: 'laboratoryUsersUpdateStatus',
        summary: 'Cambiar el estado de membresía',
        description: 'Activa o desactiva solo la membresía del laboratorio actual. No altera la identidad, otras membresías ni evidencia clínica. Protege el último administrador activo. Requiere users.change_status.',
        security: [['sanctumCookie' => []]],
        tags: ['Laboratory users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateLaboratoryUserStatusInput')),
        responses: [
            new OA\Response(response: 200, description: 'Estado local actualizado.', content: new OA\JsonContent(ref: '#/components/schemas/LaboratoryUserResponse')),
            new OA\Response(response: 404, description: 'No existe en el laboratorio activo.'),
            new OA\Response(response: 409, description: 'Identidad global inactiva o último administrador protegido.'),
            new OA\Response(response: 422, description: 'Estado inválido.'),
        ],
    )]
    public function updateStatus(UpdateLaboratoryUserStatusRequest $request, CurrentLaboratory $current, LaboratoryUserManager $manager, int $user): LaboratoryUserResource
    {
        $target = $this->memberQuery($current->get())->findOrFail($user);

        return LaboratoryUserResource::make($manager->changeStatus($current->get(), $request->user(), $target, $request->validated('status')));
    }

    /** @return Builder<User> */
    private function memberQuery(Laboratory $laboratory): Builder
    {
        return User::query()
            ->select('users.*')
            ->addSelect([
                DB::raw('laboratory_user.id AS membership_id'),
                DB::raw('laboratory_user.is_active AS membership_is_active'),
                DB::raw('laboratory_user.is_default AS membership_is_default'),
                DB::raw('laboratory_user.created_at AS membership_created_at'),
                DB::raw('laboratory_user.updated_at AS membership_updated_at'),
            ])
            ->join('laboratory_user', 'laboratory_user.user_id', '=', 'users.id')
            ->where('laboratory_user.laboratory_id', $laboratory->getKey())
            ->with([
                'roles' => fn ($query) => $query->where('roles.laboratory_id', $laboratory->getKey())->orderBy('name'),
                'validationProfessionals' => fn ($query) => $query->where('laboratory_id', $laboratory->getKey()),
            ]);
    }
}
