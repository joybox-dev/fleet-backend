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

    /**
     * PUT /api/company — update current company info (admin only).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (!$company) {
            return response()->json(['message' => 'لا توجد شركة مرتبطة بحسابك.'], 404);
        }

        // Only admins can update company info
        if ($user->role !== 'admin' && !$user->is_super_admin) {
            return response()->json(['message' => 'غير مصرح لك بتعديل بيانات الشركة.'], 403);
        }

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'name_ar'    => 'nullable|string|max:255',
            'phone'      => 'nullable|string|max:50',
            'email'      => 'nullable|email|max:255',
            'address'    => 'nullable|string|max:500',
            'tax_number' => 'nullable|string|max:50',
            'branding'   => 'nullable|array',
            'branding.primary_color' => 'nullable|string|max:20',
            'branding.accent_color'  => 'nullable|string|max:20',
            'branding.sidebar_bg'    => 'nullable|string|max:20',
            'branding.sidebar_text'  => 'nullable|string|max:20',
            'branding.header_bg'     => 'nullable|string|max:20',
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            $validated['logo_path'] = '/storage/' . $path;
        }

        $company->update($validated);

        return response()->json([
            'message' => 'تم تحديث بيانات الشركة.',
            'company' => $company->fresh(),
        ]);
    }
}
