<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Violation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ViolationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $violations = Violation::with(['employee:id,name', 'vehicle:id,plate_number'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->vehicle_id, fn($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->date_from, fn($q) => $q->whereDate('violation_date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('violation_date', '<=', $request->date_to))
            ->when($request->has('is_driver_liable'), fn($q) => $q->where('is_driver_liable', $request->boolean('is_driver_liable')))
            ->when($request->boolean('undeducted'), fn($q) => $q->where('is_deducted', false)->where('is_driver_liable', true))
            ->orderByDesc('violation_date')
            ->paginate(50);

        return response()->json($violations);
    }

    public function resolveDriver(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'violation_date' => 'required',
        ]);

        $vehicleId = $request->vehicle_id;
        $violationDate = $request->violation_date;
        $dateOnly = date('Y-m-d', strtotime($violationDate));

        $assignment = \App\Models\VehicleAssignment::with('employee:id,name')
            ->where('vehicle_id', $vehicleId)
            ->where('assigned_date', '<=', $dateOnly)
            ->where(function($query) use ($dateOnly) {
                $query->where('unassigned_date', '>=', $dateOnly)
                      ->orWhereNull('unassigned_date');
            })
            ->first();

        if (!$assignment) {
            return response()->json([
                'success' => false,
                'message' => 'No active driver found for this vehicle on the specified date/time.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'employee' => $assignment->employee,
            'assignment' => $assignment
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');

        $request->validate([
            'vehicle_id'       => 'required|exists:vehicles,id',
            'violation_date'   => 'required',
            'violation_type'   => 'required|string|max:255',
            'reference_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('violations', 'reference_number')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'amount'           => 'required|numeric|min:0',
            'is_driver_liable' => 'boolean',
            'photo_path'       => 'nullable|string',
            'notes'            => 'nullable|string',
            
            // New Phase 16 fields
            'split_mode'       => 'nullable|in:percentage,manual',
            'driver_share'     => 'nullable|numeric|min:0',
            'contract_share'   => 'nullable|numeric|min:0',
            'charge_contract_id' => 'nullable|exists:contracts,id',
            'manual_audit_reason' => 'nullable|string',
            
            // Overrides
            'employee_id'      => 'nullable|exists:employees,id',
            'assignment_override_reason' => 'nullable|string',
        ]);

        $vehicleId = $request->vehicle_id;
        $violationDate = $request->violation_date;
        $dateOnly = date('Y-m-d', strtotime($violationDate));

        // Resolve driver automatically
        $assignment = \App\Models\VehicleAssignment::where('vehicle_id', $vehicleId)
            ->where('assigned_date', '<=', $dateOnly)
            ->where(function($query) use ($dateOnly) {
                $query->where('unassigned_date', '>=', $dateOnly)
                      ->orWhereNull('unassigned_date');
            })
            ->first();

        if (!$assignment && !$request->has('employee_id')) {
            return response()->json([
                'message' => 'No active driver was assigned to this vehicle at the specified date/time.'
            ], 422);
        }

        $autoResolvedEmployeeId = $assignment ? $assignment->employee_id : null;
        $employeeId = $request->employee_id ?? $autoResolvedEmployeeId;

        // Auto-resolve contract
        $autoResolvedContractId = null;
        if ($autoResolvedEmployeeId) {
            $contractAssign = \App\Models\ContractAssignment::where('employee_id', $autoResolvedEmployeeId)
                ->where('start_date', '<=', $dateOnly)
                ->where(function($query) use ($dateOnly) {
                    $query->where('end_date', '>=', $dateOnly)
                          ->orWhereNull('end_date');
                })
                ->first();
            if ($contractAssign) {
                $autoResolvedContractId = $contractAssign->contract_id;
            }
        }

        $chargeContractId = $request->has('charge_contract_id') ? $request->charge_contract_id : $autoResolvedContractId;

        // Check overrides and validate assignment_override_reason
        $isDriverOverride = false;
        $isContractOverride = false;

        if ($request->has('employee_id') && $request->employee_id != $autoResolvedEmployeeId) {
            $isDriverOverride = true;
            if (empty($request->assignment_override_reason)) {
                return response()->json([
                    'message' => 'سبب التعديل يدوياً مطلوب لحفظ تجاوز السائق.',
                    'errors' => ['assignment_override_reason' => ['يجب إدخال سبب التعديل يدوياً لحفظ التجاوز.']]
                ], 422);
            }
        }

        if ($request->has('charge_contract_id') && $request->charge_contract_id != $autoResolvedContractId) {
            $isContractOverride = true;
            if (empty($request->assignment_override_reason)) {
                return response()->json([
                    'message' => 'سبب التعديل يدوياً مطلوب لحفظ تجاوز العقد.',
                    'errors' => ['assignment_override_reason' => ['يجب إدخال سبب التعديل يدوياً لحفظ التجاوز.']]
                ], 422);
            }
        }

        // Validate splits
        $driverShare = $request->has('driver_share') ? (float)$request->driver_share : ($request->boolean('is_driver_liable', true) ? (float)$request->amount : 0.0);
        $contractShare = $request->has('contract_share') ? (float)$request->contract_share : ($request->boolean('is_driver_liable', true) ? 0.0 : (float)$request->amount);

        if (abs(($driverShare + $contractShare) - (float)$request->amount) > 0.0001) {
            return response()->json([
                'message' => 'مجموع حصة السائق وحصة الشركة يجب أن يساوي القيمة الإجمالية للمخالفة.',
                'errors' => ['driver_share' => ['مجموع الحصص غير مطابق لإجمالي المخالفة.']]
            ], 422);
        }

        $violationData = [
            'employee_id'      => $employeeId,
            'vehicle_id'       => $vehicleId,
            'violation_date'   => $violationDate,
            'violation_type'   => $request->violation_type,
            'reference_number' => $request->reference_number,
            'amount'           => $request->amount,
            'is_driver_liable' => $request->boolean('is_driver_liable', true),
            'photo_path'       => $request->photo_path,
            'notes'            => $request->notes,
            'created_by'       => $request->user()->id,

            // New fields
            'split_mode'       => $request->split_mode ?? 'percentage',
            'driver_share'     => $driverShare,
            'contract_share'   => $contractShare,
            'charge_contract_id' => $chargeContractId,
            'manual_audit_reason' => $request->manual_audit_reason,
            'is_driver_override' => $isDriverOverride,
            'is_contract_override' => $isContractOverride,
            'assignment_override_reason' => $request->assignment_override_reason,
            'driver_deduction' => $driverShare,
        ];

        $violation = Violation::create($violationData);

        // ── WhatsApp auto-notify driver ──
        $employee = $violation->employee;
        if ($employee?->has_whatsapp && $employee?->phone) {
            try {
                app(\App\Services\WhatsAppService::class)->sendMessage(
                    $employee->phone,
                    "⚠️ مخالفة مرورية\nالتاريخ: {$violation->violation_date}\n"
                    . "النوع: {$violation->violation_type}\n"
                    . "المبلغ: {$violation->amount} د.ك"
                );
            } catch (\Throwable $e) {
                \Log::warning('WhatsApp send failed for violation', [
                    'violation_id' => $violation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($violation->load(['employee:id,name', 'vehicle:id,plate_number', 'chargeContract:id,name']), 201);
    }

    public function show(Violation $violation): JsonResponse
    {
        return response()->json($violation->load(['employee', 'vehicle', 'createdBy:id,name']));
    }

    public function update(Request $request, Violation $violation): JsonResponse
    {
        if ($violation->is_deducted) {
            return response()->json(['message' => 'Cannot edit a violation that has been deducted from payroll.'], 403);
        }

        $validated = $request->validate([
            'violation_type'   => 'sometimes|string|max:255',
            'amount'           => 'sometimes|numeric|min:0',
            'is_driver_liable' => 'sometimes|boolean',
            'photo_path'       => 'nullable|string',
            'notes'            => 'nullable|string',

            // New Phase 16 fields
            'split_mode'       => 'nullable|in:percentage,manual',
            'driver_share'     => 'nullable|numeric|min:0',
            'contract_share'   => 'nullable|numeric|min:0',
            'charge_contract_id' => 'nullable|exists:contracts,id',
            'manual_audit_reason' => 'nullable|string',

            // Overrides
            'employee_id'      => 'nullable|exists:employees,id',
            'assignment_override_reason' => 'nullable|string',
        ]);

        // Auto-resolve check for overrides
        $dateOnly = date('Y-m-d', strtotime($violation->violation_date));
        $assignment = \App\Models\VehicleAssignment::where('vehicle_id', $violation->vehicle_id)
            ->where('assigned_date', '<=', $dateOnly)
            ->where(function($query) use ($dateOnly) {
                $query->where('unassigned_date', '>=', $dateOnly)
                      ->orWhereNull('unassigned_date');
            })
            ->first();
        $autoResolvedEmployeeId = $assignment ? $assignment->employee_id : null;

        $autoResolvedContractId = null;
        if ($autoResolvedEmployeeId) {
            $contractAssign = \App\Models\ContractAssignment::where('employee_id', $autoResolvedEmployeeId)
                ->where('start_date', '<=', $dateOnly)
                ->where(function($query) use ($dateOnly) {
                    $query->where('end_date', '>=', $dateOnly)
                          ->orWhereNull('end_date');
                })
                ->first();
            if ($contractAssign) {
                $autoResolvedContractId = $contractAssign->contract_id;
            }
        }

        if ($request->has('employee_id') && $request->employee_id != $autoResolvedEmployeeId) {
            $validated['is_driver_override'] = true;
            if (empty($request->assignment_override_reason)) {
                return response()->json([
                    'message' => 'سبب التعديل يدوياً مطلوب لحفظ تجاوز السائق.',
                    'errors' => ['assignment_override_reason' => ['يجب إدخال سبب التعديل يدوياً لحفظ التجاوز.']]
                ], 422);
            }
        }

        if ($request->has('charge_contract_id') && $request->charge_contract_id != $autoResolvedContractId) {
            $validated['is_contract_override'] = true;
            if (empty($request->assignment_override_reason)) {
                return response()->json([
                    'message' => 'سبب التعديل يدوياً مطلوب لحفظ تجاوز العقد.',
                    'errors' => ['assignment_override_reason' => ['يجب إدخال سبب التعديل يدوياً لحفظ التجاوز.']]
                ], 422);
            }
        }

        // Validate splits
        $newAmount = $request->has('amount') ? (float)$request->amount : (float)$violation->amount;
        $driverShare = $request->has('driver_share') ? (float)$request->driver_share : (float)$violation->driver_share;
        $contractShare = $request->has('contract_share') ? (float)$request->contract_share : (float)$violation->contract_share;

        if ($request->has('driver_share') || $request->has('contract_share') || $request->has('amount')) {
            if (abs(($driverShare + $contractShare) - $newAmount) > 0.0001) {
                return response()->json([
                    'message' => 'مجموع حصة السائق وحصة الشركة يجب أن يساوي القيمة الإجمالية للمخالفة.',
                    'errors' => ['driver_share' => ['مجموع الحصص غير مطابق لإجمالي المخالفة.']]
                ], 422);
            }
            $validated['driver_share'] = $driverShare;
            $validated['contract_share'] = $contractShare;
            $validated['driver_deduction'] = $driverShare; // Backwards compatibility
        }

        $violation->update($validated);

        return response()->json($violation->fresh(['employee', 'vehicle', 'chargeContract']));
    }

    public function destroy(Violation $violation): JsonResponse
    {
        if ($violation->is_deducted) {
            return response()->json(['message' => 'Cannot delete a deducted violation.'], 403);
        }
        $violation->delete();
        return response()->json(['message' => 'Violation deleted.']);
    }
}
