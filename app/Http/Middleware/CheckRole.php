<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based access control middleware (multi-tenant aware).
 *
 * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,accountant')
 *
 * Roles are now read from the company_user pivot table,
 * set by the SetCurrentCompany middleware.
 * Super admins bypass all role checks.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Super admin bypasses all role checks
        if (app()->bound('is_super_admin') && app('is_super_admin')) {
            return $next($request);
        }

        // Read role from company context (set by SetCurrentCompany middleware)
        $userRole = app()->bound('current_company_role')
            ? app('current_company_role')
            : $user->role; // Fallback to legacy column during migration

        if (!in_array($userRole, $roles)) {
            return response()->json([
                'message'   => 'غير مصرح. الدور المطلوب: ' . implode(' أو ', $roles),
                'your_role' => $userRole,
            ], 403);
        }

        return $next($request);
    }
}
