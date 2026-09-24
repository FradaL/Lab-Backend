<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentLaboratory;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function __construct(private CurrentLaboratory $currentLaboratory) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $subscription = $this->currentLaboratory->get()
            ->subscriptions()
            ->active()
            ->first();

        if ($subscription === null) {
            return $this->error(
                'El laboratorio requiere una suscripción activa.',
                'SUBSCRIPTION_REQUIRED',
            );
        }

        if (! $subscription->hasStarted()) {
            return $this->error(
                'La suscripción todavía no ha iniciado.',
                'SUBSCRIPTION_NOT_STARTED',
            );
        }

        if ($subscription->hasExpired()) {
            return $this->error(
                $subscription->isTrial()
                    ? 'El período de demostración ha vencido.'
                    : 'La suscripción ha vencido.',
                $subscription->isTrial()
                    ? 'TRIAL_EXPIRED'
                    : 'SUBSCRIPTION_EXPIRED',
            );
        }

        return $next($request);
    }

    private function error(string $message, string $code): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
        ], Response::HTTP_FORBIDDEN);
    }
}
