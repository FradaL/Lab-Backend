<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\ClinicalReasonEligibilityPolicy;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClinicalReasonAdoptionController extends Controller
{
    public function __construct(private ClinicalReasonAdoptionManager $manager, private ClinicalReasonEligibilityPolicy $eligibility, private CurrentLaboratory $current) {}

    public function catalog(Request $request): JsonResponse
    {
        $this->page($request);

        return response()->json($this->manager->catalog($this->current->get(), $request->user()));
    }

    public function globalDetail(Request $request, int $reason): JsonResponse
    {
        return response()->json($this->manager->catalog($this->current->get(), $request->user(), $reason));
    }

    public function globalVersions(Request $request, int $reason): JsonResponse
    {
        $this->page($request);

        return response()->json($this->manager->catalog($this->current->get(), $request->user(), $reason, true));
    }

    public function index(Request $request): JsonResponse
    {
        $this->page($request);

        return response()->json($this->manager->index($this->current->get(), $request->user()));
    }

    public function adopt(Request $request): JsonResponse
    {
        return response()->json($this->manager->adopt($this->current->get(), $request->user(), $request->all()), 201);
    }

    public function update(Request $request, int $adoption): JsonResponse
    {
        return response()->json($this->manager->update($this->current->get(), $request->user(), $adoption, $request->all()), 201);
    }

    public function history(Request $request, int $adoption): JsonResponse
    {
        $this->page($request);

        return response()->json($this->manager->history($this->current->get(), $request->user(), $adoption));
    }

    public function updates(Request $request, int $adoption): JsonResponse
    {
        $this->page($request);

        return response()->json($this->manager->updates($this->current->get(), $request->user(), $adoption));
    }

    public function eligibility(Request $request, int $adoption): JsonResponse
    {
        return response()->json(['data' => $this->eligibility->eligibility($this->current->get(), $request->user(), $adoption)])->header('Cache-Control', 'private, no-store');
    }

    public function reviews(Request $request, int $adoption): JsonResponse
    {
        $this->page($request);

        return response()->json($this->manager->reviews($this->current->get(), $request->user(), $adoption));
    }

    public function resolve(Request $request, int $adoption, int $review): JsonResponse
    {
        return response()->json($this->manager->resolve($this->current->get(), $request->user(), $adoption, $review, $request->all()), 201);
    }

    public function resolutions(Request $request, int $adoption, int $review): JsonResponse
    {
        $this->page($request);

        return response()->json($this->manager->resolutions($this->current->get(), $request->user(), $adoption, $review));
    }

    private function page(Request $request): void
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
    }
}
