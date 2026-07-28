<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ErpSync;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MaintenanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $records = MaintenanceRecord::with(['vehicle:id,plate_number', 'reportedBy:id,name'])
            ->when($request->vehicle_id, fn($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderByDesc('maintenance_date')
            ->paginate(50);

        return response()->json($records);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id'        => 'required|exists:vehicles,id',
            'garage_name'       => 'nullable|string|max:255',
            'maintenance_type'  => 'required|in:accident,periodic,repair,oil_change,other',
            'maintenance_date'  => 'required|date',
            'estimated_cost'    => 'nullable|numeric|min:0',
            'is_driver_liable'  => 'boolean',
            'liable_employee_id'=> 'nullable|exists:employees,id',
            'driver_deduction'  => 'nullable|numeric|min:0',
            'odometer_km'       => 'nullable|integer|min:0',
            'notes'             => 'nullable|string',
            
            // Accident bearing fields
            'driver_bearing_percentage'  => 'nullable|numeric|min:0|max:100',
            'company_bearing_percentage' => 'nullable|numeric|min:0|max:100',
            'accident_status'            => 'nullable|string|in:open,under_review,closed',
            'accident_description'       => 'nullable|string',
        ]);

        $validated['reported_by'] = $request->user()->id;
        $validated['status']      = 'pending';
        $validated['estimated_cost'] = $validated['estimated_cost'] ?? 0.00;
        $validated['driver_deduction'] = $validated['driver_deduction'] ?? 0.00;

        // Auto-calculate driver deduction and company bearing percentage for accidents
        if ($validated['maintenance_type'] === 'accident') {
            $driverPercent = isset($validated['driver_bearing_percentage']) ? (float)$validated['driver_bearing_percentage'] : 0.00;
            $validated['driver_bearing_percentage'] = $driverPercent;
            $validated['company_bearing_percentage'] = 100.00 - $driverPercent;
            
            if ($validated['is_driver_liable'] ?? false) {
                $estimatedCost = isset($validated['estimated_cost']) ? (float)$validated['estimated_cost'] : 0.00;
                $validated['driver_deduction'] = $estimatedCost * ($driverPercent / 100.00);
            } else {
                $validated['driver_deduction'] = 0.00;
            }
        }

        $record = MaintenanceRecord::create($validated);

        // Set vehicle status to maintenance
        Vehicle::find($validated['vehicle_id'])->update(['status' => 'maintenance']);

        // Handle oil change — update vehicle last_oil_change_km
        if ($validated['maintenance_type'] === 'oil_change' && isset($validated['odometer_km'])) {
            Vehicle::find($validated['vehicle_id'])->update([
                'last_oil_change_km' => $validated['odometer_km'],
                'odometer_km'        => $validated['odometer_km'],
            ]);
        }

        return response()->json($record->load(['vehicle:id,plate_number', 'reportedBy:id,name']), 201);
    }

    public function show(MaintenanceRecord $maintenance): JsonResponse
    {
        return response()->json($maintenance->load(['vehicle', 'reportedBy:id,name', 'approvedBy:id,name', 'liableEmployee:id,name']));
    }

    public function update(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        if (in_array($maintenance->status, ['approved', 'completed'])) {
            return response()->json(['message' => 'Cannot edit an approved or completed record.'], 403);
        }

        $validated = $request->validate([
            'garage_name'       => 'nullable|string',
            'estimated_cost'    => 'nullable|numeric|min:0',
            'actual_cost'       => 'nullable|numeric|min:0',
            'invoice_path'      => 'nullable|string',
            'notes'             => 'nullable|string',
            'is_driver_liable'  => 'boolean',
            'liable_employee_id'=> 'nullable|exists:employees,id',
            'driver_deduction'  => 'nullable|numeric|min:0',
            
            // Accident bearing fields
            'driver_bearing_percentage'  => 'nullable|numeric|min:0|max:100',
            'company_bearing_percentage' => 'nullable|numeric|min:0|max:100',
            'accident_status'            => 'nullable|string|in:open,under_review,closed',
            'accident_description'       => 'nullable|string',
        ]);

        // Auto-calculate for accident type
        $type = $maintenance->maintenance_type;
        if ($type === 'accident') {
            if (isset($validated['driver_bearing_percentage'])) {
                $driverPercent = (float)$validated['driver_bearing_percentage'];
                $validated['company_bearing_percentage'] = 100.00 - $driverPercent;
                
                $liable = isset($validated['is_driver_liable']) ? $validated['is_driver_liable'] : $maintenance->is_driver_liable;
                if ($liable) {
                    $cost = isset($validated['estimated_cost']) ? (float)$validated['estimated_cost'] : (float)$maintenance->estimated_cost;
                    $validated['driver_deduction'] = $cost * ($driverPercent / 100.00);
                } else {
                    $validated['driver_deduction'] = 0.00;
                }
            }
        }

        $maintenance->update($validated);

        return response()->json($maintenance->fresh());
    }

    public function destroy(MaintenanceRecord $maintenance): JsonResponse
    {
        $maintenance->delete();
        return response()->json(['message' => 'Record deleted.']);
    }

    /**
     * POST /api/maintenance/{maintenance}/approve
     * Supervisor approves and sets actual cost.
     */
    public function approve(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        if ($maintenance->status !== 'pending') {
            return response()->json(['message' => 'Only pending records can be approved.'], 422);
        }

        $validated = $request->validate([
            'actual_cost' => 'required|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);

        $updateData = [
            'status'      => 'approved',
            'actual_cost' => $validated['actual_cost'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'notes'       => $validated['notes'] ?? $maintenance->notes,
        ];

        // Recalculate driver deduction based on actual cost for accidents
        if ($maintenance->maintenance_type === 'accident' && $maintenance->is_driver_liable) {
            $driverPercent = (float)($maintenance->driver_bearing_percentage ?? 0);
            $updateData['driver_deduction'] = $validated['actual_cost'] * ($driverPercent / 100.00);
        }

        $maintenance->update($updateData);

        // Sync approved cost to ERPNext as Journal Entry
        ErpSync::dispatch(\App\Services\ErpNext\Jobs\SyncMaintenanceJob::class, $maintenance->id);

        return response()->json(['message' => 'Maintenance approved.', 'record' => $maintenance->fresh()]);
    }

    /**
     * POST /api/maintenance/{maintenance}/reject
     */
    public function reject(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        if ($maintenance->status !== 'pending') {
            return response()->json(['message' => 'Only pending records can be rejected.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $maintenance->update([
            'status'           => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'approved_by'      => $request->user()->id,
            'approved_at'      => now(),
        ]);

        // Return vehicle to available
        $maintenance->vehicle->update(['status' => 'available']);

        return response()->json(['message' => 'Maintenance rejected.']);
    }
}
