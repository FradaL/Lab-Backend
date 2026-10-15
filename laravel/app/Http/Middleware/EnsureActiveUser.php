<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // Re-read the persisted flag: a session or a long-lived guard may hold an older User.
        if (! User::query()->whereKey($request->user()->getAuthIdentifier())->value('is_active')) {
            return response()->json([
                'message' => 'El usuario está desactivado.',
                'code' => 'USER_INACTIVE',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
