<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalExams\LocalClinicalReasonManager;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocalClinicalReasonController extends Controller
{
    public function __construct(private LocalClinicalReasonManager $manager, private CurrentLaboratory $current) {}

    public function index(Request $r): JsonResponse
    {
        $r->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->index($this->current->get(), $r->user()));
    }

    public function show(Request $r, int $reason): JsonResponse
    {
        return response()->json($this->manager->show($this->current->get(), $r->user(), $reason));
    }

    public function history(Request $r, int $reason): JsonResponse
    {
        $r->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->history($this->current->get(), $r->user(), $reason));
    }

    public function events(Request $r, int $reason): JsonResponse
    {
        $r->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->events($this->current->get(), $r->user(), $reason));
    }

    public function store(Request $r): JsonResponse
    {
        return response()->json($this->manager->create($this->current->get(), $r->user(), $r->all()), 201);
    }

    public function publish(Request $r, int $reason): JsonResponse
    {
        return response()->json($this->manager->publish($this->current->get(), $r->user(), $reason, $r->all()), 201);
    }

    public function activation(Request $r, int $reason): JsonResponse
    {
        return response()->json($this->manager->activation($this->current->get(), $r->user(), $reason, $r->all()), 201);
    }

    public function reviews(Request $r, int $reason): JsonResponse
    {
        $r->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $r->validate(['exception_request_id' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->reviews($this->current->get(), $r->user(), $reason, $r->has('exception_request_id') ? (int) $r->query('exception_request_id') : null));
    }

    public function resolutions(Request $r, int $reason, int $review): JsonResponse
    {
        $r->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->manager->resolutions($this->current->get(), $r->user(), $reason, $review));
    }

    public function resolve(Request $r, int $reason, int $review): JsonResponse
    {
        return response()->json($this->manager->resolve($this->current->get(), $r->user(), $reason, $review, $r->all()), 201);
    }
}
