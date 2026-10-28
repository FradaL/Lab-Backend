<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalExams\GlobalClinicalReasonManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalClinicalReasonController extends Controller
{
    public function __construct(private GlobalClinicalReasonManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->index($request->user()));
    }

    public function show(Request $request, int $reason): JsonResponse
    {
        return response()->json(['data' => $this->manager->show($request->user(), $reason)]);
    }

    public function history(Request $request, int $reason): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->history($request->user(), $reason));
    }

    public function events(Request $request, int $reason): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->events($request->user(), $reason));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->manager->create($request->user(), $request->all()), 201);
    }

    public function publish(Request $request, int $reason): JsonResponse
    {
        return response()->json($this->manager->publish($request->user(), $reason, $request->all()), 201);
    }

    public function activation(Request $request, int $reason): JsonResponse
    {
        return response()->json($this->manager->activation($request->user(), $reason, $request->all()), 201);
    }
}
