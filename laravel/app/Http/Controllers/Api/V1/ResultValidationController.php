<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResultValidationController extends Controller
{
    public function __construct(private LaboratoryValidationService $validation, private ValidationAdministrationService $administration, private CurrentLaboratory $current) {}

    public function policy(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->administration->policy($this->current->get(), $r->user())]);
    }

    public function setPolicy(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->administration->setPolicy($this->current->get(), $r->user(), $r->all())]);
    }

    public function professionals(Request $r): JsonResponse
    {
        return response()->json($this->administration->professionals($this->current->get(), $r->user()));
    }

    public function register(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->administration->register($this->current->get(), $r->user(), $r->all())], 201);
    }

    public function authorizeProfessional(Request $r, int $professional): JsonResponse
    {
        return response()->json(['data' => $this->administration->professionalState($this->current->get(), $r->user(), $professional, true, $r->all())]);
    }

    public function revokeProfessional(Request $r, int $professional): JsonResponse
    {
        return response()->json(['data' => $this->administration->professionalState($this->current->get(), $r->user(), $professional, false, $r->all())]);
    }

    public function validateResults(Request $r, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->validation->validate($this->current->get(), $r->user(), $order, $line, $r->all())]);
    }

    public function rectify(Request $r, int $order, int $line): JsonResponse
    {
        return response()->json(['data' => $this->validation->rectify($this->current->get(), $r->user(), $order, $line, $r->all())]);
    }

    public function history(Request $r, int $order, int $line): JsonResponse
    {
        return response()->json($this->validation->history($this->current->get(), $r->user(), $order, $line));
    }
}
