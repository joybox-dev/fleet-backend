<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Employee;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\CustodyItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employees = Employee::with(['activeAssignment.vehicle:id,plate_number'])
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->pay_type, fn($q) => $q->where('pay_type', $request->pay_type))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'name_ar'               => 'nullable|string|max:255',
            'nationality'           => 'nullable|string|max:100',
            'civil_id'              => 'nullable|string|unique:employees,civil_id',
            'phone'                 => 'nullable|string|max:30|unique:employees,phone',
            'has_whatsapp'          => 'nullable|boolean',
            'whatsapp_company_number'=> 'nullable|string|max:30',
            'whatsapp_language'     => 'nullable|string|max:10',
            'gender'                => 'in:male,female',
            'date_of_birth'         => 'nullable|date',
            'date_of_joining'       => 'required|date',
            'employee_type'         => 'in:overseas,local_transfer',
            'pay_type'              => 'required|in:fixed,per_order,hybrid',
            'official_salary'       => 'required|numeric|min:0',
            'actual_salary'         => 'required|numeric|min:0',
            'rate_per_order'        => 'required_if:pay_type,per_order,hybrid|numeric|min:0',
            'has_end_of_service'    => 'boolean',
            'health_card_expiry'    => 'nullable|date',
            'residence_expiry'      => 'nullable|date',
            'driving_license_expiry'=> 'nullable|date',
            'work_permit_expiry'    => 'nullable|date',
            'notes'                 => 'nullable|string',
        ]);

        // ── Auto-generate employee number: EMP-0001, EMP-0002, ... ──
        // Use DB::table to bypass ALL Eloquent global scopes reliably
        $lastNum = \DB::table('employees')
            ->where('company_id', app('current_company_id'))
            ->where('employee_number', 'like', 'EMP-%')
            ->selectRaw("MAX(CAST(SUBSTR(employee_number, 5) AS INTEGER)) as max_num")
            ->value('max_num');
        $validated['employee_number'] = 'EMP-' . str_pad(($lastNum ?? 0) + 1, 4, '0', STR_PAD_LEFT);

        // Auto-set probation period: 3 months from joining
        $validated['status'] = 'probation';
        $validated['probation_end_date'] = \Carbon\Carbon::parse($validated['date_of_joining'])->addMonths(3)->toDateString();

        $employee = Employee::create($validated);



        return response()->json($employee, 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json($employee->load([
            'vehicleAssignments.vehicle:id,plate_number,make,model',
            'vehicleAssignments.contract:id,name',
        ]));
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'name_ar'                => 'nullable|string|max:255',
            'phone'                  => "nullable|string|max:30|unique:employees,phone,{$employee->id}",
            'has_whatsapp'           => 'nullable|boolean',
            'whatsapp_company_number'=> 'nullable|string|max:30',
            'whatsapp_language'      => 'nullable|string|max:10',
            'status'                 => 'sometimes|in:active,inactive,on_leave,probation',
            'status_reason'          => 'nullable|string|max:255',
            'official_salary'        => 'sometimes|numeric|min:0',
            'actual_salary'          => 'sometimes|numeric|min:0',
            'rate_per_order'         => 'sometimes|numeric|min:0',
            'health_card_expiry'     => 'nullable|date',
            'residence_expiry'       => 'nullable|date',
            'driving_license_expiry' => 'nullable|date',
            'work_permit_expiry'     => 'nullable|date',
            // Overseas onboarding stages
            'stage_arrived'          => 'sometimes|boolean',
            'stage_medical_done'     => 'sometimes|boolean',
            'stage_medical_date'     => 'nullable|date',
            'stage_work_permit_done' => 'sometimes|boolean',
            'stage_work_permit_date' => 'nullable|date',
            'stage_driving_trial_done' => 'sometimes|boolean',
            'stage_license_obtained' => 'sometimes|boolean',
            'stage_license_date'     => 'nullable|date',
            'notes'                  => 'nullable|string',
        ]);

        if (isset($validated['status'])) {
            $validated['status_changed_at'] = now()->toDateString();
        }

        $employee->update($validated);



        return response()->json($employee->fresh());
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();
        return response()->json(['message' => 'Employee deleted.']);
    }

    /**
     * GET /api/employees/{employee}/balance
     * Employee debit/credit ledger — from meeting: حساب الموظف (مدين ودائن)
     */
    public function balance(Employee $employee): JsonResponse
    {
        // Credits (earnings beyond base salary)
        $ordersBonus = DailyLog::where('employee_id', $employee->id)->sum('income_amount');

        // Debits (deductions)
        $violationsTotal = Violation::where('employee_id', $employee->id)
            ->where('is_driver_liable', true)
            ->sum('amount');

        $maintenanceTotal = MaintenanceRecord::where('liable_employee_id', $employee->id)
            ->sum('driver_deduction');

        $custodyTotal = CustodyItem::where('employee_id', $employee->id)
            ->whereIn('return_condition', ['damaged', 'lost'])
            ->sum('deduction_amount');

        // Salary advance deductions (total deducted from payroll)
        $advanceTotal = \App\Models\AdvanceDeduction::whereHas('salaryAdvance', function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
        })->sum('amount');

        // Leave deductions (approved, unpaid)
        $leaveTotal = \App\Models\EmployeeLeave::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('is_paid', false)
            ->sum('total_deduction');

        $totalDeductions = $violationsTotal + $maintenanceTotal + $custodyTotal + $advanceTotal + $leaveTotal;
        $netBalance      = $ordersBonus - $totalDeductions;

        return response()->json([
            'employee_id'   => $employee->id,
            'employee_name' => $employee->name,
            'credits' => [
                'orders_income' => (float) $ordersBonus,
            ],
            'debits' => [
                'violations'  => (float) $violationsTotal,
                'maintenance' => (float) $maintenanceTotal,
                'custody'     => (float) $custodyTotal,
                'advances'    => (float) $advanceTotal,
                'leaves'      => (float) $leaveTotal,
                'total'       => (float) $totalDeductions,
            ],
            'net_balance' => (float) $netBalance,
        ]);
    }
}
