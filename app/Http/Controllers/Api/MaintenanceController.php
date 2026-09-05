<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ErpSync;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Services\ErpNext\Jobs\SyncMaintenanceJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $records = MaintenanceRecord::with(['vehicle:id,plate_number', 'reportedBy:id,name'])
            ->when($request->vehicle_id, fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('maintenance_date')
            ->paginate(50);

        return response()->json($records);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('maintenance.create')) {
            return response()->json(['message' => 'غير مصرح لك بتقديم طلب صيانة.'], 403);
        }

        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'garage_name' => 'nullable|string|max:255',
            'maintenance_type' => 'required|in:accident,periodic,repair,oil_change,other',
            'maintenance_date' => 'required|date',
            'estimated_cost' => 'nullable|numeric|min:0',
            'is_driver_liable' => 'boolean',
            'liable_employee_id' => 'nullable|exists:employees,id',
            'driver_deduction' => 'nullable|numeric|min:0',
            'odometer_km' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',

            // Accident bearing fields
            'driver_bearing_percentage' => 'nullable|numeric|min:0|max:100',
            'company_bearing_percentage' => 'nullable|numeric|min:0|max:100',
            'accident_status' => 'nullable|string|in:open,under_review,closed',
            'accident_description' => 'nullable|string',
            'assignment_override_reason' => 'nullable|string|max:500',
        ]);

        if ($fault = $this->driverLiabilityFault($validated, $request)) {
            return $fault;
        }

        $validated['reported_by'] = $request->user()->id;
        $validated['status'] = 'pending';
        $validated['estimated_cost'] = $validated['estimated_cost'] ?? 0.00;
        $validated['driver_deduction'] = $validated['driver_deduction'] ?? 0.00;

        // Auto-calculate driver deduction and company bearing percentage for accidents
        if ($validated['maintenance_type'] === 'accident') {
            $driverPercent = isset($validated['driver_bearing_percentage']) ? (float) $validated['driver_bearing_percentage'] : 0.00;
            $validated['driver_bearing_percentage'] = $driverPercent;
            $validated['company_bearing_percentage'] = 100.00 - $driverPercent;

            if ($validated['is_driver_liable'] ?? false) {
                $estimatedCost = isset($validated['estimated_cost']) ? (float) $validated['estimated_cost'] : 0.00;
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
                'odometer_km' => $validated['odometer_km'],
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
        if (! $request->user()->can('maintenance.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل سجلات الصيانة.'], 403);
        }

        if (in_array($maintenance->status, ['approved', 'completed'])) {
            return response()->json(['message' => 'Cannot edit an approved or completed record.'], 403);
        }

        $validated = $request->validate([
            'garage_name' => 'nullable|string',
            'estimated_cost' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',
            'invoice_path' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_driver_liable' => 'boolean',
            'liable_employee_id' => 'nullable|exists:employees,id',
            'driver_deduction' => 'nullable|numeric|min:0',

            // Accident bearing fields
            'driver_bearing_percentage' => 'nullable|numeric|min:0|max:100',
            'company_bearing_percentage' => 'nullable|numeric|min:0|max:100',
            'accident_status' => 'nullable|string|in:open,under_review,closed',
            'accident_description' => 'nullable|string',
            'assignment_override_reason' => 'nullable|string|max:500',
        ]);

        // The same guard as on create: an edit can hand a repair to any driver just as easily.
        // Built field by field so what the request left out falls back to the stored record, and
        // a field sent as null is honoured as null rather than quietly replaced.
        $after = [
            'maintenance_type' => $maintenance->maintenance_type,
            'maintenance_date' => $maintenance->maintenance_date?->toDateString(),
            'vehicle_id' => $maintenance->vehicle_id,
            'is_driver_liable' => array_key_exists('is_driver_liable', $validated)
                ? $validated['is_driver_liable'] : $maintenance->is_driver_liable,
            'liable_employee_id' => array_key_exists('liable_employee_id', $validated)
                ? $validated['liable_employee_id'] : $maintenance->liable_employee_id,
            'driver_bearing_percentage' => array_key_exists('driver_bearing_percentage', $validated)
                ? $validated['driver_bearing_percentage'] : $maintenance->driver_bearing_percentage,
            'assignment_override_reason' => $validated['assignment_override_reason']
                ?? $maintenance->assignment_override_reason,
        ];

        if ($fault = $this->driverLiabilityFault($after, $request)) {
            return $fault;
        }

        // Auto-calculate for accident type
        $type = $maintenance->maintenance_type;
        if ($type === 'accident') {
            if (isset($validated['driver_bearing_percentage'])) {
                $driverPercent = (float) $validated['driver_bearing_percentage'];
                $validated['company_bearing_percentage'] = 100.00 - $driverPercent;

                $liable = isset($validated['is_driver_liable']) ? $validated['is_driver_liable'] : $maintenance->is_driver_liable;
                if ($liable) {
                    $cost = isset($validated['estimated_cost']) ? (float) $validated['estimated_cost'] : (float) $maintenance->estimated_cost;
                    $validated['driver_deduction'] = $cost * ($driverPercent / 100.00);
                } else {
                    $validated['driver_deduction'] = 0.00;
                }
            }
        }

        $maintenance->update($validated);

        return response()->json($maintenance->fresh());
    }

    public function destroy(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        if (! $request->user()->can('maintenance.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف سجلات الصيانة.'], 403);
        }

        $maintenance->delete();

        return response()->json(['message' => 'Record deleted.']);
    }

    /**
     * POST /api/maintenance/{maintenance}/approve
     * Supervisor approves and sets actual cost.
     */
    public function approve(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        if (! $request->user()->can('maintenance.edit')) {
            return response()->json(['message' => 'غير مصرح لك واعتماد طلبات الصيانة.'], 403);
        }

        if ($maintenance->status !== 'pending') {
            return response()->json(['message' => 'Only pending records can be approved.'], 422);
        }

        $validated = $request->validate([
            'actual_cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            // The garage invoice, at the moment the cost becomes real. These carry the largest
            // amounts in the system and had the weakest evidence: no file field on either screen
            // that creates a record, and the invoice was refused once approved — so an accident
            // charged to a driver could never have its invoice attached at all, even by hand.
            'invoice_path' => 'nullable|string|max:255',
        ]);

        if ($maintenance->is_driver_liable && empty($validated['invoice_path']) && empty($maintenance->invoice_path)) {
            return response()->json([
                'message' => 'لا يمكن اعتماد صيانة بمسؤولية السائق بدون إرفاق فاتورة الكراج.',
                'errors' => ['invoice_path' => ['فاتورة الكراج مطلوبة عند تحميل السائق أي حصة.']],
            ], 422);
        }

        $updateData = [
            'status' => 'approved',
            'actual_cost' => $validated['actual_cost'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'notes' => $validated['notes'] ?? $maintenance->notes,
        ];

        if (! empty($validated['invoice_path'])) {
            $updateData['invoice_path'] = $validated['invoice_path'];
        }

        // Recalculate driver deduction based on actual cost for accidents
        if ($maintenance->maintenance_type === 'accident' && $maintenance->is_driver_liable) {
            $driverPercent = (float) ($maintenance->driver_bearing_percentage ?? 0);
            $updateData['driver_deduction'] = $validated['actual_cost'] * ($driverPercent / 100.00);
        }

        $maintenance->update($updateData);

        // Sync approved cost to ERPNext as Journal Entry
        ErpSync::dispatch(SyncMaintenanceJob::class, $maintenance->id);

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
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        // Return vehicle to available
        $maintenance->vehicle->update(['status' => 'available']);

        return response()->json(['message' => 'Maintenance rejected.']);
    }

    /**
     * Refuse a driver-liable repair whose driver was picked out of thin air.
     *
     * The liable driver was chosen by hand from a dropdown of the whole company, often months after
     * the fact, with nothing checking that the person had ever held that vehicle — the largest
     * amounts in the system with no control at all. A traffic fine already resolves its driver from
     * the vehicle's assignment history on the date it happened; a repair now has to agree with that
     * history or say in writing why it does not.
     *
     * It also refuses the two silent zeroes: a driver-liable record with nobody named, and one with
     * no bearing percentage, which the accident branch below would quietly turn into 0.000 charged
     * while the record still displayed as the driver's.
     *
     * @return JsonResponse|null a 422 to return, or null to carry on
     */
    private function driverLiabilityFault(array $data, Request $request): ?JsonResponse
    {
        if (! ($data['is_driver_liable'] ?? false)) {
            return null;
        }

        if (empty($data['liable_employee_id'])) {
            return response()->json([
                'message' => 'صيانة بمسؤولية السائق بدون تحديد السائق — لن يُخصم منها شيء.',
                'errors' => ['liable_employee_id' => ['حدّد السائق المسؤول.']],
            ], 422);
        }

        if (($data['maintenance_type'] ?? null) === 'accident'
            && ! isset($data['driver_bearing_percentage'])) {
            return response()->json([
                'message' => 'حدّد نسبة تحمّل السائق — بدونها يُحفظ الخصم صفراً بينما يظهر السجل بمسؤوليته.',
                'errors' => ['driver_bearing_percentage' => ['نسبة تحمّل السائق مطلوبة.']],
            ], 422);
        }

        $date = $data['maintenance_date'] ?? null;
        $vehicleId = $data['vehicle_id'] ?? null;
        if (! $date || ! $vehicleId) {
            return null;
        }

        $held = VehicleAssignment::where('vehicle_id', $vehicleId)
            ->whereDate('assigned_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('unassigned_date')->orWhereDate('unassigned_date', '>=', $date))
            ->pluck('employee_id')
            ->all();

        if (in_array((int) $data['liable_employee_id'], array_map('intval', $held), true)) {
            return null;
        }

        if (! empty($data['assignment_override_reason'])) {
            return null;
        }

        $holders = empty($held)
            ? 'لم تكن المركبة مُسندة لأحد في هذا التاريخ'
            : 'المركبة كانت مع: '.Employee::whereIn('id', $held)->pluck('name')->implode('، ');

        return response()->json([
            'message' => "السائق المحدد لم يكن مسؤولاً عن هذه المركبة بتاريخ {$date}. {$holders}.",
            'errors' => ['liable_employee_id' => [
                'إمّا اختر السائق الصحيح، أو اكتب سبب تحميله في «سبب تجاوز التعيين».',
            ]],
        ], 422);
    }
}
