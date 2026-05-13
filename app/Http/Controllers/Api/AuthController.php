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

        // Load user's company
        $company = $user->company;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'role'           => $user->role,
                'is_super_admin' => $user->isSuperAdmin(),
                'company_id'     => $user->company_id,
                'permissions'    => $user->resolvePermissions(),
            ],
            'current_company' => $company ? [
                'id'              => $company->id,
                'name'            => $company->name,
                'name_ar'         => $company->name_ar,
                'code'            => $company->code,
                'logo_path'       => $company->logo_path,
                'branding'        => $company->branding,
                'enabled_modules' => $company->enabled_modules,
                'currency'        => $company->currency,
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
                'permissions'    => $user->resolvePermissions(),
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
