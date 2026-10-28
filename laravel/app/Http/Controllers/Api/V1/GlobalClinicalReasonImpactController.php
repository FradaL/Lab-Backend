<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalExams\GlobalClinicalReasonImpactManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalClinicalReasonImpactController extends Controller
{
    public function __construct(private GlobalClinicalReasonImpactManager $manager) {}

    public function show(Request $request, int $reason, int $version): JsonResponse
    {
        return response()->json($this->manager->show($request->user(), $reason, $version));
    }

    public function history(Request $request, int $reason, int $version): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->history($request->user(), $reason, $version));
    }

    public function store(Request $request, int $reason, int $version): JsonResponse
    {
        return response()->json($this->manager->declare($request->user(), $reason, $version, $request->all()), 201);
    }
}
