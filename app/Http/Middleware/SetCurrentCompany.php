<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and sets the current company context for every request.
 *
 * SECURITY: This is the tenant-isolation gatekeeper.
 * - Regular users can only access companies they belong to.
 * - Super admins can access any company via X-Company-Id header.
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

        // ── Super admin: can access any company ──
        if ($user->isSuperAdmin()) {
            $companyId = $request->header('X-Company-Id')
                ?? $request->query('company_id');

            app()->instance('is_super_admin', true);

            if ($companyId) {
                $company = \App\Models\Company::find($companyId);
                if ($company) {
                    app()->instance('current_company_id', (int) $companyId);
                    app()->instance('current_company_role', 'admin');
                    app()->instance('current_company', $company);
                }
            }
            // Super admin without company header → no company scope (global view)
            return $next($request);
        }

        // ── Regular user: resolve company ──
        $companyId = $request->header('X-Company-Id')
            ?? $user->companies()->wherePivot('is_default', true)->value('companies.id')
            ?? $user->companies()->first()?->id;

        if (!$companyId) {
            return response()->json([
                'message' => 'لا توجد شركة مرتبطة بحسابك. تواصل مع المسؤول.',
            ], 403);
        }

        // Verify user actually belongs to this company
        $company = $user->companies()->where('companies.id', $companyId)->first();
        if (!$company) {
            return response()->json([
                'message' => 'غير مصرح لك بالوصول إلى هذه الشركة.',
            ], 403);
        }

        // Check company is active
        if (!$company->is_active) {
            return response()->json([
                'message' => 'هذه الشركة معطّلة حالياً. تواصل مع المسؤول.',
            ], 403);
        }

        // Bind to container — BelongsToCompany trait reads these
        app()->instance('current_company_id', (int) $companyId);
        app()->instance('current_company_role', $company->pivot->role);
        app()->instance('current_company', $company);
        app()->instance('is_super_admin', false);

        return $next($request);
    }
}
