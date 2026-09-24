<?php

use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\ResolveLaboratoryContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'laboratory.context' => ResolveLaboratoryContext::class,
            'subscription.active' => EnsureActiveSubscription::class,
        ]);
        $middleware->group('saas', [
            'auth:sanctum',
            'laboratory.context',
            'subscription.active',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
