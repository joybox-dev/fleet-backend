<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and sets the current company context for every request.
 *
 * SECURITY: This is the tenant-isolation gatekeeper.
 * - Regular users: company comes from users.company_id (one company per user).
 * - Super admins: can override via X-Company-Id header to view any company.
 * - All downstream global scopes (BelongsToCompany) depend on this.
 */
class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        // ── Super admin: can access any company via header ──
        if ($user->isSuperAdmin()) {
            app()->instance('is_super_admin', true);

            // Use header override, or fall back to user's own company
            $companyId = $request->header('X-Company-Id')
                ?? $user->company_id;

            if ($companyId) {
                $company = \App\Models\Company::find($companyId);
                if ($company) {
                    app()->instance('current_company_id', (int) $companyId);
                    app()->instance('current_company_role', 'admin');
                    app()->instance('current_company', $company);
                }
            }

            return $next($request);
        }

        // ── Regular user: company from users.company_id ──
        if (!$user->company_id) {
            return response()->json([
                'message' => 'لا توجد شركة مرتبطة بحسابك. تواصل مع المسؤول.',
            ], 403);
        }

        $company = $user->company;

        if (!$company) {
            return response()->json([
                'message' => 'الشركة غير موجودة. تواصل مع المسؤول.',
            ], 403);
        }

        if (!$company->is_active) {
            return response()->json([
                'message' => 'هذه الشركة معطّلة حالياً. تواصل مع المسؤول.',
            ], 403);
        }

        // Bind to container — BelongsToCompany trait reads these
        app()->instance('current_company_id', (int) $user->company_id);
        app()->instance('current_company_role', $user->role);
        app()->instance('current_company', $company);
        app()->instance('is_super_admin', false);

        return $next($request);
    }
}

