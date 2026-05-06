<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Role-based access control middleware.
 *
 * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,accountant')
 *
 * Roles are stored in users.role column.
 * Default roles: admin, operator, accountant
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'Unauthorized. Required role: ' . implode(' or ', $roles),
                'your_role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
