<?php

namespace App\Http\Middleware;

use App\Models\Laboratory;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveLaboratoryContext
{
    public function __construct(private CurrentLaboratory $currentLaboratory) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->currentLaboratory->clear();

        if (! $request->headers->has('X-Laboratory-ID')) {
            return $this->error(
                'Debe especificar el laboratorio.',
                'LABORATORY_CONTEXT_REQUIRED',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $header = $request->header('X-Laboratory-ID');

        if (! is_string($header) || preg_match('/^[1-9]\d*$/', $header) !== 1) {
            return $this->error(
                'El identificador del laboratorio no es válido.',
                'INVALID_LABORATORY_CONTEXT',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $laboratoryId = filter_var($header, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($laboratoryId === false) {
            return $this->error(
                'El identificador del laboratorio no es válido.',
                'INVALID_LABORATORY_CONTEXT',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $laboratory = Laboratory::query()->find($laboratoryId);

        if ($laboratory === null) {
            return $this->error(
                'El laboratorio solicitado no existe.',
                'LABORATORY_NOT_FOUND',
                Response::HTTP_NOT_FOUND,
            );
        }

        if (! $laboratory->is_active) {
            return $this->error(
                'El laboratorio no está activo.',
                'LABORATORY_INACTIVE',
                Response::HTTP_FORBIDDEN,
            );
        }

        /** @var User $user */
        $user = $request->user();

        $hasActiveMembership = $user->laboratories()
            ->whereKey($laboratory->getKey())
            ->wherePivot('is_active', true)
            ->exists();

        if (! $hasActiveMembership) {
            return $this->error(
                'No tiene acceso al laboratorio solicitado.',
                'LABORATORY_ACCESS_DENIED',
                Response::HTTP_FORBIDDEN,
            );
        }

        $this->currentLaboratory->set($laboratory);

        return $next($request);
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
