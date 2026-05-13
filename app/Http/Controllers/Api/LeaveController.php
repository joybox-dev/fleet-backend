<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    /* ══════════════════════════════════════════════════════════════════
     * GET /api/leave-types
     * List all active leave types.
     * ══════════════════════════════════════════════════════════════════ */
    public function types(): JsonResponse
    {
        return response()->json(
            LeaveType::where('is_active', true)->orderBy('id')->get()
        );
    }

    /* ══════════════════════════════════════════════════════════════════
     * GET /api/leaves
     * List all leaves — filterable by employee, status, date range.
     * ══════════════════════════════════════════════════════════════════ */
    public function index(Request $request): JsonResponse
    {
        $leaves = EmployeeLeave::with(['employee:id,name,name_ar', 'leaveType:id,name,name_ar,is_paid', 'approver:id,name'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->leave_type_id, fn($q) => $q->where('leave_type_id', $request->leave_type_id))
            ->when($request->start_date, fn($q) => $q->where('start_date', '>=', $request->start_date))
            ->when($request->end_date, fn($q) => $q->where('end_date', '<=', $request->end_date))
            ->orderByDesc('start_date')
            ->paginate(50);

        return response()->json($leaves);
    }

    /* ══════════════════════════════════════════════════════════════════
     * GET /api/leaves/{leave}
     * Show a single leave record.
     * ══════════════════════════════════════════════════════════════════ */
    public function show(EmployeeLeave $leave): JsonResponse
    {
        return response()->json(
            $leave->load(['employee:id,name,name_ar', 'leaveType', 'approver:id,name'])
        );
    }

    /* ══════════════════════════════════════════════════════════════════
     * POST /api/leaves
     * Create a new leave request.
     * Snapshots the daily rate, penalty multiplier, and formula version.
     * ══════════════════════════════════════════════════════════════════ */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'   => 'required|exists:employees,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'reason'        => 'nullable|string|max:1000',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $employee  = Employee::findOrFail($validated['employee_id']);
        $leaveType = LeaveType::findOrFail($validated['leave_type_id']);

        // Calculate days count (inclusive)
        $startDate = Carbon::parse($validated['start_date']);
        $endDate   = Carbon::parse($validated['end_date']);
        $daysCount = $startDate->diffInDays($endDate) + 1;

        // Check leave balance for paid leave types with limits
        if ($leaveType->is_paid && $leaveType->max_days_per_year) {
            $usedDays = EmployeeLeave::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereYear('start_date', $startDate->year)
                ->sum('days_count');

            $remaining = $leaveType->max_days_per_year - $usedDays;

            if ($daysCount > $remaining) {
                return response()->json([
                    'message' => "رصيد الإجازات غير كافٍ. المتبقي: {$remaining} يوم، المطلوب: {$daysCount} يوم.",
                ], 422);
            }
        }

        // Check for overlapping leaves
        $overlap = EmployeeLeave::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function ($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'يوجد تداخل مع إجازة أخرى في هذه الفترة.',
            ], 422);
        }

        // Snapshot values
        $formulaVersion = $this->getFormulaVersion();
        $dailyRate      = $this->calculateDailyRate($employee, $formulaVersion);
        $isPaid         = $leaveType->is_paid;
        $multiplier     = (float) $leaveType->penalty_multiplier;

        // Calculate deduction
        $totalDeduction = $isPaid ? 0 : round($dailyRate * $daysCount * $multiplier, 3);

        $leave = EmployeeLeave::create([
            'employee_id'       => $employee->id,
            'leave_type_id'     => $leaveType->id,
            'start_date'        => $validated['start_date'],
            'end_date'          => $validated['end_date'],
            'days_count'        => $daysCount,
            'status'            => $leaveType->requires_approval ? 'pending' : 'approved',
            'is_paid'           => $isPaid,
            'daily_rate'        => $dailyRate,
            'penalty_multiplier'=> $multiplier,
            'formula_version'   => $formulaVersion,
            'total_deduction'   => $totalDeduction,
            'reason'            => $validated['reason'] ?? null,
            'notes'             => $validated['notes'] ?? null,
        ]);

        // Auto-approve if type doesn't require approval
        if (!$leaveType->requires_approval) {
            $leave->update([
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            $this->checkAutoStatusChange($employee, $leave);
        }

        return response()->json($leave->load(['employee:id,name,name_ar', 'leaveType:id,name,name_ar']), 201);
    }

    /* ══════════════════════════════════════════════════════════════════
     * PUT /api/leaves/{leave}
     * Update a pending leave.
     * ══════════════════════════════════════════════════════════════════ */
    public function update(Request $request, EmployeeLeave $leave): JsonResponse
    {
        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'لا يمكن تعديل إجازة غير معلقة.'], 422);
        }

        $validated = $request->validate([
            'start_date' => 'sometimes|date',
            'end_date'   => 'sometimes|date|after_or_equal:start_date',
            'reason'     => 'nullable|string|max:1000',
            'notes'      => 'nullable|string|max:1000',
        ]);

        if (isset($validated['start_date']) || isset($validated['end_date'])) {
            $startDate = Carbon::parse($validated['start_date'] ?? $leave->start_date);
            $endDate   = Carbon::parse($validated['end_date'] ?? $leave->end_date);
            $validated['days_count'] = $startDate->diffInDays($endDate) + 1;

            // Recalculate deduction
            if (!$leave->is_paid) {
                $validated['total_deduction'] = round(
                    (float) $leave->daily_rate * $validated['days_count'] * (float) $leave->penalty_multiplier,
                    3
                );
            }
        }

        $leave->update($validated);

        return response()->json($leave->fresh()->load(['employee:id,name,name_ar', 'leaveType:id,name,name_ar']));
    }

    /* ══════════════════════════════════════════════════════════════════
     * POST /api/leaves/{leave}/approve
     * Approve a pending leave.
     * ══════════════════════════════════════════════════════════════════ */
    public function approve(Request $request, EmployeeLeave $leave): JsonResponse
    {
        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'هذه الإجازة ليست في حالة انتظار.'], 422);
        }

        $leave->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        // Auto status change for long leaves (7+ days)
        $employee = Employee::findOrFail($leave->employee_id);
        $this->checkAutoStatusChange($employee, $leave);

        return response()->json([
            'message' => 'تم اعتماد الإجازة بنجاح.',
            'leave'   => $leave->fresh()->load(['employee:id,name,name_ar', 'leaveType:id,name,name_ar']),
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * POST /api/leaves/{leave}/reject
     * Reject a pending leave.
     * ══════════════════════════════════════════════════════════════════ */
    public function reject(Request $request, EmployeeLeave $leave): JsonResponse
    {
        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'هذه الإجازة ليست في حالة انتظار.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $leave->update([
            'status'           => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        return response()->json([
            'message' => 'تم رفض الإجازة.',
            'leave'   => $leave->fresh()->load(['employee:id,name,name_ar', 'leaveType:id,name,name_ar']),
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * DELETE /api/leaves/{leave}
     * Cancel/delete a pending leave.
     * ══════════════════════════════════════════════════════════════════ */
    public function destroy(EmployeeLeave $leave): JsonResponse
    {
        if (!in_array($leave->status, ['pending', 'approved'])) {
            return response()->json(['message' => 'لا يمكن إلغاء هذه الإجازة.'], 422);
        }

        $leave->update(['status' => 'cancelled']);
        $leave->delete(); // Soft delete

        return response()->json(['message' => 'تم إلغاء الإجازة بنجاح.']);
    }

    /* ══════════════════════════════════════════════════════════════════
     * GET /api/leaves/balance/{employee}
     * Get employee's leave balance summary.
     * ══════════════════════════════════════════════════════════════════ */
    public function balance(Employee $employee): JsonResponse
    {
        $year = now()->year;

        $types = LeaveType::where('is_active', true)->get();

        // Batch: all leave usage for this employee in one query
        $usageData = EmployeeLeave::where('employee_id', $employee->id)
            ->whereYear('start_date', $year)
            ->whereIn('status', ['approved', 'pending'])
            ->groupBy('leave_type_id', 'status')
            ->selectRaw('leave_type_id, status, SUM(days_count) as total_days')
            ->get();

        // Index by type → status
        $usageMap = [];
        foreach ($usageData as $row) {
            $usageMap[$row->leave_type_id][$row->status] = (int) $row->total_days;
        }

        $balances = $types->map(function ($type) use ($usageMap) {
            $used    = $usageMap[$type->id]['approved'] ?? 0;
            $pending = $usageMap[$type->id]['pending'] ?? 0;

            return [
                'leave_type_id'   => $type->id,
                'name'            => $type->name,
                'name_ar'         => $type->name_ar,
                'is_paid'         => $type->is_paid,
                'max_days'        => $type->max_days_per_year,
                'used'            => $used,
                'pending'         => $pending,
                'remaining'       => $type->max_days_per_year
                    ? max(0, $type->max_days_per_year - $used)
                    : null,
            ];
        });

        // Total deductions from unpaid leaves this year
        $totalDeductions = EmployeeLeave::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('is_paid', false)
            ->whereYear('start_date', $year)
            ->sum('total_deduction');

        return response()->json([
            'employee_id'     => $employee->id,
            'employee_name'   => $employee->name,
            'year'            => $year,
            'balances'        => $balances,
            'total_deductions'=> (float) $totalDeductions,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * Private Helpers
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Get the current deduction formula version from settings.
     */
    private function getFormulaVersion(): string
    {
        try {
            $setting = Setting::where('key', 'leave_formula_version')->first();
            return $setting?->value ?? 'v1_actual_div_30';
        } catch (\Throwable) {
            return 'v1_actual_div_30';
        }
    }

    /**
     * Calculate daily rate based on formula version.
     */
    private function calculateDailyRate(Employee $employee, string $formulaVersion): float
    {
        return match ($formulaVersion) {
            'v1_actual_div_30'   => round((float) $employee->actual_salary / 30, 3),
            'v2_official_div_30' => round((float) $employee->official_salary / 30, 3),
            'v3_actual_div_26'   => round((float) $employee->actual_salary / 26, 3),
            default              => round((float) $employee->actual_salary / 30, 3),
        };
    }

    /**
     * Auto-set employee status to on_leave for long leaves (7+ days).
     */
    private function checkAutoStatusChange(Employee $employee, EmployeeLeave $leave): void
    {
        if ($leave->days_count >= 7 && in_array($employee->status, ['active', 'probation'])) {
            $employee->update([
                'status'            => 'on_leave',
                'status_reason'     => $leave->leaveType->name_ar . ' — ' . $leave->days_count . ' يوم',
                'status_changed_at' => now()->toDateString(),
            ]);
        }
    }
}
