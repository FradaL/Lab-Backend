<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SaasAccessController extends Controller
{
    public function __construct(private SaasAccessManager $manager) {}

    public function capabilities(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->capabilities($request->user()));
    }

    public function users(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->users($request->user()));
    }

    public function assignments(Request $request, int $user): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->assignments($request->user(), $user));
    }

    public function grant(Request $request, int $user): JsonResponse
    {
        return response()->json(['data' => $this->manager->grant($request->user(), $user, $request->all())], 201);
    }

    public function revoke(Request $request, int $user, int $assignment): JsonResponse
    {
        return response()->json(['data' => $this->manager->revoke($request->user(), $user, $assignment, $request->all())]);
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->history($request->user()));
    }
}
