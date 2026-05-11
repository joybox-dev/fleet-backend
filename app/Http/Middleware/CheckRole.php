<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based access control middleware.
 *
 * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,accountant')
 * Role is read from users.role column.
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
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $userRole = $user->role;

        if (!in_array($userRole, $roles)) {
            return response()->json([
                'message'   => 'غير مصرح. الدور المطلوب: ' . implode(' أو ', $roles),
                'your_role' => $userRole,
            ], 403);
        }

        return $next($request);
    }
}
