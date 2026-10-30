<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\RbacCatalog;
use App\Authorization\RolePermissionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReplaceRolePermissionsRequest;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;

class RolePermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (string $code): array => [
            'code' => $code,
            'group' => explode('.', $code, 2)[0],
            'label' => str_replace(['.', '_'], ' ', $code),
        ], RbacCatalog::PERMISSIONS)]);
    }

    public function show(CurrentLaboratory $current, RolePermissionManager $manager, string $role): JsonResponse
    {
        return response()->json(['data' => $manager->show($current->get(), $role)]);
    }

    public function update(ReplaceRolePermissionsRequest $request, CurrentLaboratory $current, RolePermissionManager $manager, string $role): JsonResponse
    {
        return response()->json(['data' => $manager->replace($current->get(), $request->user(), $role, $request->validated())]);
    }
}
