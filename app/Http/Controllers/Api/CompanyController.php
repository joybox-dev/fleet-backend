<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company endpoints available to regular authenticated users.
 * - List their own companies
 * - Switch active company
 * - Get current company info (with branding)
 */
class CompanyController extends Controller
{
    /**
     * GET /api/companies/mine — list companies the authenticated user belongs to.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            // Super admin sees all companies
            $companies = Company::orderBy('name')
                ->get()
                ->map(fn($c) => [
                    'id'       => $c->id,
                    'name'     => $c->name,
                    'name_ar'  => $c->name_ar,
                    'code'     => $c->code,
                    'logo_path'=> $c->logo_path,
                    'is_active'=> $c->is_active,
                    'role'     => 'admin',
                    'is_default' => false,
                ]);
        } else {
            $companies = $user->companies()
                ->orderBy('name')
                ->get()
                ->map(fn($c) => [
                    'id'       => $c->id,
                    'name'     => $c->name,
                    'name_ar'  => $c->name_ar,
                    'code'     => $c->code,
                    'logo_path'=> $c->logo_path,
                    'is_active'=> $c->is_active,
                    'role'     => $c->pivot->role,
                    'is_default' => $c->pivot->is_default,
                ]);
        }

        return response()->json(['companies' => $companies]);
    }

    /**
     * GET /api/companies/current — get current company with branding.
     */
    public function current(): JsonResponse
    {
        $company = app()->bound('current_company') ? app('current_company') : null;

        if (!$company) {
            return response()->json(['message' => 'لا توجد شركة نشطة.'], 404);
        }

        return response()->json([
            'company' => [
                'id'              => $company->id,
                'name'            => $company->name,
                'name_ar'         => $company->name_ar,
                'code'            => $company->code,
                'logo_path'       => $company->logo_path,
                'branding'        => $company->branding,
                'enabled_modules' => $company->enabled_modules,
                'currency'        => $company->currency,
                'phone'           => $company->phone,
                'email'           => $company->email,
                'address'         => $company->address,
                'tax_number'      => $company->tax_number,
            ],
        ]);
    }

    /**
     * POST /api/companies/switch/{company} — switch active company.
     */
    public function switch(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();

        // Super admin can switch to any company
        if (!$user->isSuperAdmin()) {
            if (!$user->companies()->where('companies.id', $company->id)->exists()) {
                return response()->json(['message' => 'غير مصرح لك بالوصول إلى هذه الشركة.'], 403);
            }

            // Set this as the default company
            $user->companies()->updateExistingPivot(
                $user->companies()->pluck('companies.id'),
                ['is_default' => false]
            );
            $user->companies()->updateExistingPivot($company->id, ['is_default' => true]);
        }

        return response()->json([
            'message' => 'تم التبديل بنجاح.',
            'company' => [
                'id'              => $company->id,
                'name'            => $company->name,
                'name_ar'         => $company->name_ar,
                'code'            => $company->code,
                'logo_path'       => $company->logo_path,
                'branding'        => $company->branding,
                'enabled_modules' => $company->enabled_modules,
            ],
        ]);
    }
}
