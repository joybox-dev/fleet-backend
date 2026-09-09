<?php

use App\Http\Middleware\CheckModuleEnabled;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetCurrentCompany;
use App\Http\Middleware\SuperAdminOnly;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

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
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Register named middleware
        $middleware->alias([
            'role' => CheckRole::class,
            'permission' => RequirePermission::class,
            'company' => SetCurrentCompany::class,
            'module' => CheckModuleEnabled::class,
            'super_admin' => SuperAdminOnly::class,
        ]);

        // Prioritize company middleware so it runs before Route Model Binding (SubstituteBindings)
        $middleware->priority([
            EnsureFrontendRequestsAreStateful::class,
            Authenticate::class,
            SetCurrentCompany::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON errors for API routes
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*')
        );
    })->create();
