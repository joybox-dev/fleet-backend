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

    public function availablePermissions(): JsonResponse
    {
        return response()->json([
            'all_permissions' => \App\Services\PermissionService::ALL_PERMISSIONS,
            'modules' => [
                ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊'],
                ['key' => 'clients', 'label' => 'العملاء', 'icon' => '🏢'],
                ['key' => 'contracts', 'label' => 'العقود والشرائح', 'icon' => '📜'],
                ['key' => 'employees', 'label' => 'الموظفين والسائقين', 'icon' => '👥'],
                ['key' => 'driver_expenses', 'label' => 'مصاريف السائقين', 'icon' => '💸'],
                ['key' => 'leaves', 'label' => 'الإجازات والغياب', 'icon' => '📅'],
                ['key' => 'evaluations', 'label' => 'التقييم الأداء', 'icon' => '⭐'],
                ['key' => 'custody', 'label' => 'العهد والأمانات', 'icon' => '📦'],
                ['key' => 'guarantees', 'label' => 'الضمانات المالية', 'icon' => '🔒'],
                ['key' => 'daily_logs', 'label' => 'سجلات العمل التشغيلية', 'icon' => '📝'],
                ['key' => 'operations', 'label' => 'العمليات التشغيلية', 'icon' => '⚡'],
                ['key' => 'violations', 'label' => 'المخالفات المرورية', 'icon' => '⚠️'],
                ['key' => 'cash', 'label' => 'تصفية الكاش والتسويات', 'icon' => '💰'],
                ['key' => 'vehicles', 'label' => 'المركبات والأسطول', 'icon' => '🚗'],
                ['key' => 'maintenance', 'label' => 'الصيانة والورش', 'icon' => '🔧'],
                ['key' => 'vehicle_expenses', 'label' => 'مصاريف المركبات', 'icon' => '⛽'],
                ['key' => 'payroll', 'label' => 'مسير الرواتب', 'icon' => '💵'],
                ['key' => 'salary_advances', 'label' => 'السلف الشخصية', 'icon' => '🏦'],
                ['key' => 'op_advances', 'label' => 'السلف التشغيلية', 'icon' => '🛠️'],
                ['key' => 'reports', 'label' => 'التقارير والإحصائيات', 'icon' => '📈'],
                ['key' => 'settings', 'label' => 'إعدادات النظام', 'icon' => '⚙️'],
            ]
        ]);
    }
}
