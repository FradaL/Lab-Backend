<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalExams\ClinicalReasonReadService;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClinicalReasonReadController extends Controller
{
    public function __invoke(Request $request, CurrentLaboratory $current, ClinicalReasonReadService $reads): JsonResponse
    {
        return response()->json($reads->index($current->get(), $request->user(), $request->query()))
            ->header('Cache-Control', 'private, no-store');
    }
}
