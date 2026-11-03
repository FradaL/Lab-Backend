<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalValidation\ValidationSignatureService;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ValidationSignatureController extends Controller
{
    public function __construct(private ValidationSignatureService $service, private CurrentLaboratory $current) {}

    public function show(Request $request)
    {
        return response()->json(['data' => $this->service->read($this->current->get(), $request->user())]);
    }

    public function update(Request $request)
    {
        $request->validate(['revision' => ['required', 'regex:/\A[0-9]+\z/'], 'consent' => ['required', 'accepted'], 'signature' => ['required', 'file', 'max:1024']]);
        if (array_diff(array_keys($request->all()), ['revision', 'consent', 'signature'])) {
            throw ValidationException::withMessages(['input' => ['Unknown signature fields.']]);
        }

        return response()->json(['data' => $this->service->change($this->current->get(), $request->user(), (int) $request->input('revision'), $request->file('signature'))]);
    }

    public function destroy(Request $request)
    {
        $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        if (array_keys($request->all()) !== ['revision'] || ! is_int($request->input('revision'))) {
            throw ValidationException::withMessages(['revision' => ['Send only an integer revision.']]);
        }

        return response()->json(['data' => $this->service->change($this->current->get(), $request->user(), $request->input('revision'), null)]);
    }
}
