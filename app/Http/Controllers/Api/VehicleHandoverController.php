<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleHandover;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleHandoverController extends Controller
{
    /**
     * GET /api/vehicle-handovers
     * List handovers with filtering options.
     */
    public function index(Request $request): JsonResponse
    {
        $handovers = VehicleHandover::with(['employee:id,name', 'vehicle:id,plate_number'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->vehicle_id, fn($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->orderByDesc('handover_date')
            ->orderByDesc('id')
            ->get();

        return response()->json($handovers);
    }

    /**
     * POST /api/vehicle-handovers
     * Register a new vehicle handover or return. Updates vehicle odometer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id'        => 'required|exists:vehicles,id',
            'employee_id'       => 'required|exists:employees,id',
            'handover_date'     => 'required|date',
            'type'              => 'required|in:handover,return',
            'odometer_reading'  => 'required|integer|min:0',
            'photo_front'       => 'nullable|string',
            'photo_back'        => 'nullable|string',
            'photo_left'        => 'nullable|string',
            'photo_right'       => 'nullable|string',
            'scratches_details' => 'nullable|string',
            'notes'             => 'nullable|string',
        ]);

        $companyId = app('current_company_id');
        $userId = $request->user()->id;

        $handover = VehicleHandover::create(array_merge($validated, [
            'company_id' => $companyId,
            'created_by' => $userId,
        ]));

        // Sync vehicle odometer reading if higher
        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
        if ($validated['odometer_reading'] > ($vehicle->odometer_km ?? 0)) {
            $vehicle->update(['odometer_km' => $validated['odometer_reading']]);
        }

        return response()->json($handover->load(['employee:id,name', 'vehicle:id,plate_number', 'createdBy:id,name']), 201);
    }

    /**
     * GET /api/vehicle-handovers/{id}
     */
    public function show(VehicleHandover $vehicleHandover): JsonResponse
    {
        return response()->json($vehicleHandover->load(['employee', 'vehicle', 'createdBy:id,name']));
    }

    /**
     * DELETE /api/vehicle-handovers/{id}
     */
    public function destroy(VehicleHandover $vehicleHandover): JsonResponse
    {
        $vehicleHandover->delete();
        return response()->json(['message' => 'Vehicle handover protocol deleted.']);
    }
}
