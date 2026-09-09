<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fine gate on a route: ->middleware('permission:settings.edit'), or several separated by
 * commas when any one of them is enough — ->middleware('permission:vehicles.edit,employees.edit').
 *
 * The role gate admits every company-defined role; this is what actually decides. Twenty-three
 * controllers carried no can() check of their own, so a data-entry login could rewrite any role's
 * permissions, reprice a driver's contract, or read the WhatsApp token.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        $labels = array_map(fn (string $p) => PermissionService::label($p), $permissions);

        return response()->json([
            'message' => 'غير مصرح لك — هذا الإجراء يحتاج صلاحية «'.implode('» أو «', $labels).'».',
            'required_permission' => $permissions,
        ], 403);
    }
}
