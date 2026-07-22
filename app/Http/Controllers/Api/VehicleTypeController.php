<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        if ($companyId) {
            if (VehicleType::where('company_id', $companyId)->count() === 0) {
                VehicleType::create(['company_id' => $companyId, 'name' => 'Motorcycle', 'name_ar' => 'سيكل / دراجة نارية']);
                VehicleType::create(['company_id' => $companyId, 'name' => 'Small Car', 'name_ar' => 'سيارة صغيرة']);
                VehicleType::create(['company_id' => $companyId, 'name' => 'Large Car', 'name_ar' => 'سيارة كبيرة']);
            } else if (!VehicleType::where('company_id', $companyId)->where('name_ar', 'like', '%كبيرة%')->exists()) {
                VehicleType::create(['company_id' => $companyId, 'name' => 'Large Car', 'name_ar' => 'سيارة كبيرة']);
            }
        }

        $types = VehicleType::orderBy('id')->get();
        return response()->json($types);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
        ]);
        $validated['company_id'] = $companyId;

        $type = VehicleType::create($validated);
        return response()->json($type, 201);
    }

    public function destroy(VehicleType $vehicleType): JsonResponse
    {
        if ($vehicleType->vehicles()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف نوع المركبة لكونه مرتبط بمركبات حالياً.'
            ], 422);
        }

        $vehicleType->delete();
        return response()->json(['message' => 'تم حذف نوع المركبة بنجاح.']);
    }
}
