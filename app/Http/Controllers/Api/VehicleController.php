<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vehicles = Vehicle::query()
            ->with(['activeAssignment.employee:id,name', 'activeAssignment.contract:id,name'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->search, fn($q) => $q->where('plate_number', 'like', "%{$request->search}%"))
            ->orderBy('plate_number')
            ->paginate(50);

        return response()->json($vehicles);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');

        $validated = $request->validate([
            'plate_number'                      => [
                'required',
                'string',
                Rule::unique('vehicles', 'plate_number')->where('company_id', $companyId),
            ],
            'make'                              => 'nullable|string|max:100',
            'model'                             => 'nullable|string|max:100',
            'year'                              => 'nullable|integer|min:2000|max:2030',
            'color'                             => 'nullable|string|max:50',
            'vin'                               => [
                'nullable',
                'string',
                Rule::unique('vehicles', 'vin')->where('company_id', $companyId),
            ],
            'oil_change_interval_km'            => 'nullable|integer|min:1000',
            'monthly_fuel_allowance'            => 'nullable|numeric|min:0',
            'insurance_expiry'                  => 'nullable|date',
            'comprehensive_insurance_expiry'    => 'nullable|date',
            'food_authority_license_expiry'     => 'nullable|date',
            'next_service_due'                  => 'nullable|date',
            'notes'                             => 'nullable|string',
        ]);

        // Strip null values so DB column defaults (e.g. oil_change_interval_km=4000) are used
        $validated = array_filter($validated, fn($v) => $v !== null);

        $vehicle = Vehicle::create($validated);



        return response()->json($vehicle, 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        return response()->json($vehicle->load([
            'activeAssignment.employee:id,name',
            'activeAssignment.contract:id,name',
        ]));
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'status'                            => 'sometimes|in:available,working,maintenance,idle',
            'monthly_fuel_allowance'            => 'sometimes|numeric|min:0',
            'insurance_expiry'                  => 'nullable|date',
            'comprehensive_insurance_expiry'    => 'nullable|date',
            'food_authority_license_expiry'     => 'nullable|date',
            'next_service_due'                  => 'nullable|date',
            'notes'                             => 'nullable|string',
        ]);

        $vehicle->update($validated);

        return response()->json($vehicle->fresh());
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->delete();
        return response()->json(['message' => 'Vehicle deleted.']);
    }

    /**
     * POST /api/vehicles/{vehicle}/assign
     * Assign vehicle to driver + contract.
     */
    public function assign(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'   => 'required|exists:employees,id',
            'contract_id'   => 'required|exists:contracts,id',
            'assigned_date' => 'required|date',
            'notes'         => 'nullable|string',
        ]);

        // Close any existing active assignment
        VehicleAssignment::where('vehicle_id', $vehicle->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'unassigned_date' => $validated['assigned_date']]);

        $assignment = VehicleAssignment::create([
            'vehicle_id'    => $vehicle->id,
            'employee_id'   => $validated['employee_id'],
            'contract_id'   => $validated['contract_id'],
            'assigned_date' => $validated['assigned_date'],
            'is_active'     => true,
            'notes'         => $validated['notes'] ?? null,
        ]);

        $vehicle->update(['status' => 'working']);

        return response()->json($assignment->load(['employee:id,name', 'contract:id,name']), 201);
    }

    /**
     * POST /api/vehicles/{vehicle}/unassign
     */
    public function unassign(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'unassigned_date' => 'required|date',
        ]);

        VehicleAssignment::where('vehicle_id', $vehicle->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'unassigned_date' => $validated['unassigned_date']]);

        $vehicle->update(['status' => 'available']);

        return response()->json(['message' => 'Vehicle unassigned.']);
    }

    /**
     * PATCH /api/vehicles/{vehicle}/odometer
     * Update odometer — triggers oil change alert if due.
     */
    public function updateOdometer(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'odometer_km' => 'required|integer|min:0',
        ]);

        $vehicle->update(['odometer_km' => $validated['odometer_km']]);

        $kmSinceOilChange = $vehicle->odometer_km - $vehicle->last_oil_change_km;
        $oilChangeDue     = $kmSinceOilChange >= $vehicle->oil_change_interval_km;

        return response()->json([
            'odometer_km'          => $vehicle->odometer_km,
            'km_since_oil_change'  => $kmSinceOilChange,
            'oil_change_due'       => $oilChangeDue,
            'oil_change_at_km'     => $vehicle->last_oil_change_km + $vehicle->oil_change_interval_km,
        ]);
    }
}
