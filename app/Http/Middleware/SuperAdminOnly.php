<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only allows super admins to access the route.
 *
 * Usage: Route::middleware('super_admin')->group(...)
 */
class SuperAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'هذا الإجراء متاح فقط للمسؤول الأعلى.',
            ], 403);
        }

        return $next($request);
    }
}
