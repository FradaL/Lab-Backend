<?php

namespace App\Http\Middleware;

use App\ReportDelivery\ReportDeliveryBlockAudit;
use App\Tenancy\CurrentLaboratory;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class AuditReportDeliveryPermission
{
    public function __construct(private CurrentLaboratory $current, private ReportDeliveryBlockAudit $audit) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        try {
            Gate::forUser($request->user())->authorize($permission);
        } catch (AuthorizationException $error) {
            $this->audit->record($this->current->get(), $request->user(), (int) $request->route('order'), (int) $request->route('report'), $permission, 'permission_denied');
            throw $error;
        }

        return $next($request);
    }
}
