<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum token auth for all /api/* routes
        $middleware->api(append: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Register named middleware
        $middleware->alias([
            'role'        => \App\Http\Middleware\CheckRole::class,
            'company'     => \App\Http\Middleware\SetCurrentCompany::class,
            'module'      => \App\Http\Middleware\CheckModuleEnabled::class,
            'super_admin' => \App\Http\Middleware\SuperAdminOnly::class,
        ]);

        // Prioritize company middleware so it runs before Route Model Binding (SubstituteBindings)
        $middleware->priority([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\SetCurrentCompany::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON errors for API routes
        $exceptions->shouldRenderJsonWhen(
            fn($request) => $request->is('api/*')
        );
    })->create();

