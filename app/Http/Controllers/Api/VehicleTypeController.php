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
