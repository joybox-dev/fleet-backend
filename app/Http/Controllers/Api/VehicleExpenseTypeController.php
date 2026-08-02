<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleExpenseType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleExpenseTypeController extends Controller
{
    /**
     * Default standard vehicle expense types to auto-seed if company has none.
     */
    private const DEFAULT_EXPENSE_TYPES = [
        ['name' => 'Fuel / Gas', 'name_ar' => 'بنزين / محروقات', 'description' => 'تكاليف الوقود والبنزين للمركبة'],
        ['name' => 'Oil & Filter Change', 'name_ar' => 'تغيير زيت وفلتر', 'description' => 'خدمات غيار الزيت والفلاتر الفورية'],
        ['name' => 'Spare Parts & Repairs', 'name_ar' => 'قطع غيار وإصلاحات', 'description' => 'شراء قطع غيار وتصليح الأعطال الطارئة'],
        ['name' => 'Tire Repair & Replacement', 'name_ar' => 'إصلاح وتبديل إطارات', 'description' => 'مباشرة وإصلاح ميزان وتغيير الإطارات'],
        ['name' => 'Car Wash & Cleaning', 'name_ar' => 'غسيل وتنظيف مركبات', 'description' => 'رسوم الغسيل الدعم والنظافة الدورية'],
        ['name' => 'Periodic Inspection & Service', 'name_ar' => 'صيانة دورية وفحص', 'description' => 'فحص فني وشامل وتجديد فحص'],
        ['name' => 'Other Expenses', 'name_ar' => 'مصاريف ونثريات أخرى', 'description' => 'مصاريف تشغيلية نثرية متنوعة للمركبة'],
    ];

    public function index(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        if ($companyId) {
            if (VehicleExpenseType::where('company_id', $companyId)->count() === 0) {
                foreach (self::DEFAULT_EXPENSE_TYPES as $def) {
                    VehicleExpenseType::create([
                        'company_id'  => $companyId,
                        'name'        => $def['name'],
                        'name_ar'     => $def['name_ar'],
                        'description' => $def['description'],
                    ]);
                }
            }
        }

        $types = VehicleExpenseType::orderBy('id')->get();
        return response()->json($types);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        $validated = $request->validate([
            'name'        => 'nullable|string|max:255',
            'name_ar'     => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $validated['name'] = $validated['name'] ?? $validated['name_ar'];
        $validated['company_id'] = $companyId;

        $type = VehicleExpenseType::create($validated);
        return response()->json($type, 201);
    }

    public function update(Request $request, VehicleExpenseType $vehicleExpenseType): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'nullable|string|max:255',
            'name_ar'     => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $validated['name'] = $validated['name'] ?? $validated['name_ar'];
        $vehicleExpenseType->update($validated);

        return response()->json($vehicleExpenseType);
    }

    public function destroy(VehicleExpenseType $vehicleExpenseType): JsonResponse
    {
        $vehicleExpenseType->delete();
        return response()->json(['message' => 'تم حذف نوع المصروف بنجاح.']);
    }
}
