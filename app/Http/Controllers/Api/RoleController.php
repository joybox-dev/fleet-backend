<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $companyId = app('current_company_id') ?? 1;
        $roles = Role::where('company_id', $companyId)->get();
        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'allowed_modules' => 'nullable|array',
        ]);

        $validated['company_id'] = $companyId;
        $role = Role::create($validated);

        return response()->json($role, 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'allowed_modules' => 'nullable|array',
        ]);

        $role->update($validated);

        return response()->json($role);
    }

    public function destroy(Role $role): JsonResponse
    {
        // Check if role is used by any employee
        if ($role->employees()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف الدور لكونه مرتبط بموظفين حالياً.'
            ], 422);
        }

        $role->delete();
        return response()->json(['message' => 'تم حذف الدور بنجاح.']);
    }
}
