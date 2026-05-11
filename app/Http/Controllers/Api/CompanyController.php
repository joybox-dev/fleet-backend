<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company endpoints available to regular authenticated users.
 * - Get their company info (with branding)
 *
 * Note: Each user belongs to exactly ONE company (SaaS model).
 * Super admins can view any company via the admin endpoints.
 */
class CompanyController extends Controller
{
    /**
     * GET /api/companies/current — get current company with branding.
     */
    public function current(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        if (!$company) {
            return response()->json(['message' => 'لا توجد شركة مرتبطة بحسابك.'], 404);
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
}
