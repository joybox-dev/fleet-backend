<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates API access based on which modules are enabled for the current company.
 *
 * Usage in routes: Route::middleware('module:accounting')->group(...)
 * Super admins bypass this check entirely.
 */
class CheckModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        // Super admin bypasses all module checks
        if (app()->bound('is_super_admin') && app('is_super_admin')) {
            return $next($request);
        }

        $company = app()->bound('current_company') ? app('current_company') : null;

        if (!$company) {
            return $next($request); // No company context → let other middleware handle it
        }

        $enabled = $company->enabled_modules ?? [];

        if (!in_array($module, $enabled)) {
            return response()->json([
                'message' => 'هذه الميزة غير مفعّلة لشركتك. تواصل مع المسؤول لتفعيلها.',
                'module'  => $module,
            ], 403);
        }

        return $next($request);
    }
}
