<?php

namespace App\Http\Middleware;

use App\Authorization\SaasAccessAuthority;
use App\Tenancy\CurrentLaboratory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSaasCapability
{
    public function __construct(private SaasAccessAuthority $authority, private CurrentLaboratory $current) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $this->current->clear();
        setPermissionsTeamId(null);
        $request->user()?->unsetRelation('roles')->unsetRelation('permissions');
        try {
            $this->authority->authorize($request->user(), $capability);

            return $next($request);
        } finally {
            $this->current->clear();
            setPermissionsTeamId(null);
            $request->user()?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
