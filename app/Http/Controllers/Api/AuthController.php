<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login — returns a Sanctum token + company context.
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        $user  = Auth::user();
        $token = $user->createToken('fleetops-api')->plainTextToken;

        // Load user's companies
        $companies = $user->isSuperAdmin()
            ? \App\Models\Company::where('is_active', true)->orderBy('name')->get()
            : $user->companies()->where('is_active', true)->orderBy('name')->get();

        // Determine default company
        $defaultCompany = $user->isSuperAdmin()
            ? $companies->first()
            : $companies->firstWhere('pivot.is_default', true) ?? $companies->first();

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'role'           => $defaultCompany?->pivot?->role ?? ($user->isSuperAdmin() ? 'admin' : $user->role),
                'is_super_admin' => $user->isSuperAdmin(),
            ],
            'companies' => $companies->map(fn($c) => [
                'id'       => $c->id,
                'name'     => $c->name,
                'name_ar'  => $c->name_ar,
                'code'     => $c->code,
                'logo_path'=> $c->logo_path,
                'role'     => $c->pivot?->role ?? 'admin',
                'is_default' => $c->pivot?->is_default ?? false,
            ]),
            'current_company' => $defaultCompany ? [
                'id'              => $defaultCompany->id,
                'name'            => $defaultCompany->name,
                'name_ar'         => $defaultCompany->name_ar,
                'code'            => $defaultCompany->code,
                'logo_path'       => $defaultCompany->logo_path,
                'branding'        => $defaultCompany->branding,
                'enabled_modules' => $defaultCompany->enabled_modules,
                'currency'        => $defaultCompany->currency,
            ] : null,
        ]);
    }

    /**
     * Logout — revoke current token.
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.']);
    }

    /**
     * Get current authenticated user + company context.
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = app()->bound('current_company') ? app('current_company') : null;

        return response()->json([
            'user' => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'role'           => app()->bound('current_company_role') ? app('current_company_role') : $user->role,
                'is_super_admin' => $user->isSuperAdmin(),
            ],
            'current_company' => $company ? [
                'id'              => $company->id,
                'name'            => $company->name,
                'name_ar'         => $company->name_ar,
                'branding'        => $company->branding,
                'enabled_modules' => $company->enabled_modules,
                'logo_path'       => $company->logo_path,
            ] : null,
        ]);
    }
}
