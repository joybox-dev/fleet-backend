<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coarse role gate on a route group: ->middleware('role:admin') or ->middleware('role:admin,accountant').
 *
 * Only the three built-in roles are matched by name. A role the company defined itself carries an
 * Arabic name no route could list, so it is admitted here and judged by the permission set it
 * carries — the `permission:` middleware on the route and the controllers' own can() checks.
 *
 * This used to admit ANY role that was not «driver» whenever «admin» was in the list, which is
 * every group in the API. Not one of the 24 live users is a driver, so the gate held nobody.
 */
class CheckRole
{
    private const BUILT_IN = ['admin', 'operator', 'accountant'];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $userRole = (string) $user->role;

        if (in_array($userRole, self::BUILT_IN, true)) {
            if (in_array($userRole, $roles, true)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'غير مصرح. الدور المطلوب: '.implode(' أو ', $roles),
                'your_role' => $userRole,
            ], 403);
        }

        // A driver login has no back-office role at all.
        if ($userRole === 'driver') {
            return response()->json([
                'message' => 'غير مصرح. الدور المطلوب: '.implode(' أو ', $roles),
                'your_role' => $userRole,
            ], 403);
        }

        return $next($request);
    }
}
