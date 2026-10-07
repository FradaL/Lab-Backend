<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionTeamContext
{
    public function __construct(private CurrentLaboratory $currentLaboratory) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        setPermissionsTeamId($this->currentLaboratory->id());
        $user?->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $next($request);
        } finally {
            $user?->unsetRelation('roles')->unsetRelation('permissions');
            setPermissionsTeamId(null);
        }
    }
}
