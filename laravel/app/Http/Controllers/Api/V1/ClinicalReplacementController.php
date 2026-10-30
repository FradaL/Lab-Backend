<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalReplacements\ClinicalReplacementService;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ClinicalReplacementController extends Controller
{
    public function __construct(private ClinicalReplacementService $service, private CurrentLaboratory $current) {}

    public function index(Request $request, int $order, int $line): JsonResponse
    {
        $page = Validator::make($request->query(), ['page' => ['sometimes', 'integer', 'min:1']])->validate()['page'] ?? 1;

        return response()->json(['data' => $this->service->read($this->current->get(), $request->user(), $order, $line, (int) $page)]);
    }

    public function intention(Request $request, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->service->createIntention($this->current->get(), $request->user(), $order, $line, $request->all())], 201);
    }

    public function evidence(Request $request, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->service->recordEvidence($this->current->get(), $request->user(), $order, $line, $request->all())], 201);
    }

    public function link(Request $request, int $order, int $line, int $intention): JsonResponse
    {
        return response()->json(['data' => $this->service->linkSample($this->current->get(), $request->user(), $order, $line, $intention, $request->all())], 201);
    }

    public function fulfill(Request $request, int $order, int $line, int $intention): JsonResponse
    {
        return response()->json(['data' => $this->service->fulfill($this->current->get(), $request->user(), $order, $line, $intention, $request->all())], 201);
    }

    public function assess(Request $request, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->service->assess($this->current->get(), $request->user(), $order, $line, $request->all())], 201);
    }
}
