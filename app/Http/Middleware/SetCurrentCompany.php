<?php

namespace App\Http\Middleware;

use App\Models\Company;
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
        if (! $user) {
            return $next($request);
        }

        // ── Super admin: can access any company via header ──
        if ($user->isSuperAdmin()) {
            app()->instance('is_super_admin', true);

            // Use header override, or fall back to user's own company
            $companyId = $request->header('X-Company-Id')
                ?? $user->company_id;

            $company = $companyId ? Company::find($companyId) : null;
            if ($company) {
                app()->instance('current_company_id', (int) $companyId);
                app()->instance('current_company_role', 'admin');
                app()->instance('current_company', $company);
            }

            // The system owner runs their own company in full and only looks at the others: in
            // another company every permission is its «view», and nothing may be written there.
            // The platform's own screens (companies, their users, modules) stay theirs to change.
            $viewOnly = $company !== null && (int) $company->id !== (int) $user->company_id;
            app()->instance('company_view_only', $viewOnly);

            if ($viewOnly && ! $request->isMethodSafe() && ! $request->is('api/admin/*', 'api/auth/*')) {
                return response()->json([
                    'message' => "أنت تتفرّج على «{$company->name}»: المشاهدة فقط، ولا يمكن التعديل في شركة أخرى.",
                    'view_only' => true,
                ], 403);
            }

            return $next($request);
        }

        app()->instance('company_view_only', false);

        // ── Regular user: company from users.company_id ──
        if (! $user->company_id) {
            return response()->json([
                'message' => 'لا توجد شركة مرتبطة بحسابك. تواصل مع المسؤول.',
            ], 403);
        }

        $company = $user->company;

        if (! $company) {
            return response()->json([
                'message' => 'الشركة غير موجودة. تواصل مع المسؤول.',
            ], 403);
        }

        if (! $company->is_active) {
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
