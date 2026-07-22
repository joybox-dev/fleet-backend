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
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 50), 5), 100);
        $employees = Employee::with(['activeAssignment.vehicle:id,plate_number,vehicle_type_id'])
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->pay_type, fn($q) => $q->where('pay_type', $request->pay_type))
            ->when($request->role_category, fn($q) => $q->where('role_category', $request->role_category))
            ->orderBy('name')
            ->paginate($perPage);

        // Strip the heavy and unused 'active_assignments' append from the employees in the list
        // This prevents N+1 queries completely and reduces the JSON payload size significantly!
        $employees->getCollection()->each(function ($employee) {
            $employee->makeHidden('active_assignments');
        });

        return response()->json($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');

        if ($request->filled('email')) {
            $request->merge(['email' => str_replace(',', '.', trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'name_ar'               => 'nullable|string|max:255',
            'nationality'           => 'nullable|string|max:100',
            'civil_id'              => [
                'nullable',
                'string',
                Rule::unique('employees', 'civil_id')->where('company_id', $companyId),
            ],
            'phone'                 => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('employees', 'phone')->where('company_id', $companyId),
            ],
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
            'rate_per_order'        => 'required_if:pay_type,per_order,hybrid|nullable|numeric|min:0',
            'has_end_of_service'    => 'boolean',
            'health_card_expiry'    => 'nullable|date',
            'residence_expiry'      => 'nullable|date',
            'driving_license_expiry'=> 'nullable|date',
            'work_permit_expiry'    => 'nullable|date',
            'notes'                 => 'nullable|string',
            'target_orders_monthly' => 'nullable|integer|min:0',
            'premium_commission_rate'=> 'nullable|numeric|min:0',
            
            'role_category'         => 'nullable|in:driver,admin',
            'admin_role_id'         => 'nullable|exists:roles,id',
            'salary_allocations'    => 'nullable|array',
            'email'                 => 'nullable|email|max:255|unique:users,email',
            'password'              => 'nullable|string|min:6',
        ]);

        // ── Auto-generate employee number: EMP-0001, EMP-0002, ... ──
        // Use DB::table to bypass ALL Eloquent global scopes reliably
        $lastNum = \DB::table('employees')
            ->where('company_id', app('current_company_id'))
            ->where('employee_number', 'like', 'EMP-%')
            ->selectRaw("MAX(CAST(SUBSTR(employee_number, 5) AS UNSIGNED)) as max_num")
            ->value('max_num');
        $validated['employee_number'] = 'EMP-' . str_pad(($lastNum ?? 0) + 1, 4, '0', STR_PAD_LEFT);

        // Auto-set probation period: 3 months from joining
        $validated['status'] = 'probation';
        $validated['probation_end_date'] = \Carbon\Carbon::parse($validated['date_of_joining'])->addMonths(3)->toDateString();

        $employee = Employee::create($validated);

        if (($validated['role_category'] ?? 'driver') === 'admin' && !empty($validated['email'])) {
            $roleId = $validated['admin_role_id'] ?? $request->input('assigned_role_id') ?? null;
            $roleName = 'admin';
            $numericAdminRoleId = is_numeric($roleId) ? (int)$roleId : null;
            if (!empty($roleId)) {
                $roleModel = \App\Models\Role::where('id', $roleId)->orWhere('name', $roleId)->first();
                if ($roleModel) {
                    $roleName = $roleModel->name;
                    $numericAdminRoleId = $roleModel->id;
                } else if (!is_numeric($roleId)) {
                    $roleName = $roleId;
                }
            }
            $user = \App\Models\User::create([
                'name' => $employee->name,
                'email' => $validated['email'],
                'password' => bcrypt($validated['password'] ?? 'password123'),
                'role' => $roleName,
                'company_id' => $companyId,
            ]);
            $employee->update([
                'user_id' => $user->id,
                'admin_role_id' => $numericAdminRoleId
            ]);
        }

        return response()->json($employee->load(['user', 'adminRole']), 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json($employee->load([
            'vehicleAssignments.vehicle:id,plate_number,make,model,vehicle_type_id',
            'vehicleAssignments.contract:id,name,target_orders_monthly,base_commission_rate,premium_commission_rate',
        ]));
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $companyId = app('current_company_id');

        if ($request->filled('email')) {
            $request->merge(['email' => str_replace(',', '.', trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'name_ar'                => 'nullable|string|max:255',
            'nationality'            => 'nullable|string|max:100',
            'civil_id'               => [
                'nullable',
                'string',
                Rule::unique('employees', 'civil_id')->ignore($employee->id)->where('company_id', $companyId),
            ],
            'phone'                  => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('employees', 'phone')->ignore($employee->id)->where('company_id', $companyId),
            ],
            'has_whatsapp'           => 'nullable|boolean',
            'whatsapp_company_number'=> 'nullable|string|max:30',
            'whatsapp_language'      => 'nullable|string|max:10',
            'gender'                 => 'sometimes|in:male,female',
            'date_of_birth'          => 'nullable|date',
            'date_of_joining'        => 'sometimes|date',
            'employee_type'          => 'sometimes|in:overseas,local_transfer',
            'pay_type'               => 'sometimes|in:fixed,per_order,hybrid',
            'status'                 => 'sometimes|in:active,inactive,on_leave,probation',
            'status_reason'          => 'nullable|string|max:255',
            'official_salary'        => 'sometimes|numeric|min:0',
            'actual_salary'          => 'sometimes|numeric|min:0',
            'rate_per_order'         => 'sometimes|nullable|numeric|min:0',
            'has_end_of_service'     => 'sometimes|boolean',
            'health_card_expiry'     => 'nullable|date',
            'residence_expiry'       => 'nullable|date',
            'driving_license_expiry' => 'nullable|date',
            'work_permit_expiry'     => 'nullable|date',
            'target_orders_monthly'  => 'nullable|integer|min:0',
            'premium_commission_rate'=> 'nullable|numeric|min:0',
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
            
            'role_category'         => 'sometimes|nullable|in:driver,admin',
            'admin_role_id'         => 'nullable|exists:roles,id',
            'salary_allocations'    => 'nullable|array',
            'email'                 => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($employee->user_id),
            ],
            'password'              => 'nullable|string|min:6',
        ]);

        if (isset($validated['status'])) {
            $validated['status_changed_at'] = now()->toDateString();
        }

        $employee->update($validated);

        if (($employee->role_category) === 'admin' && !empty($validated['email'])) {
            $roleId = $validated['admin_role_id'] ?? $request->input('assigned_role_id') ?? $employee->admin_role_id ?? null;
            $roleName = 'admin';
            $numericAdminRoleId = is_numeric($roleId) ? (int)$roleId : null;
            if (!empty($roleId)) {
                $roleModel = \App\Models\Role::where('id', $roleId)->orWhere('name', $roleId)->first();
                if ($roleModel) {
                    $roleName = $roleModel->name;
                    $numericAdminRoleId = $roleModel->id;
                } else if (!is_numeric($roleId)) {
                    $roleName = $roleId;
                }
            }
            
            if ($employee->user_id) {
                $user = \App\Models\User::find($employee->user_id);
                if ($user) {
                    $updateData = [
                        'name' => $employee->name,
                        'email' => $validated['email'],
                        'role' => $roleName,
                    ];
                    if (!empty($validated['password'])) {
                        $updateData['password'] = bcrypt($validated['password']);
                    }
                    $user->update($updateData);
                }
                $employee->update(['admin_role_id' => $numericAdminRoleId]);
            } else {
                $user = \App\Models\User::create([
                    'name' => $employee->name,
                    'email' => $validated['email'],
                    'password' => bcrypt($validated['password'] ?? 'password123'),
                    'role' => $roleName,
                    'company_id' => $companyId,
                ]);
                $employee->update([
                    'user_id' => $user->id,
                    'admin_role_id' => $numericAdminRoleId
                ]);
            }
        }

        return response()->json($employee->fresh(['user', 'adminRole']));
    }

    public function deletionCheck(Employee $employee): JsonResponse
    {
        $blocks = $employee->getDeletionBlocks();
        return response()->json([
            'is_deletable' => empty($blocks),
            'blocks' => $blocks,
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $blocks = $employee->getDeletionBlocks();
        if (!empty($blocks)) {
            return response()->json([
                'message' => 'لا يمكن حذف الموظف لوجود ارتباطات نشطة.',
                'errors' => $blocks,
            ], 422);
        }

        if ($employee->user_id) {
            \App\Models\User::where('id', $employee->user_id)->delete();
        }

        $employee->delete();
        return response()->json(['message' => 'Employee deleted.']);
    }

    /**
     * GET /api/employees/{employee}/balance
     * Employee debit/credit ledger — from meeting: حساب الموظف (مدين ودائن)
     */
    public function balance(Employee $employee): JsonResponse
    {
        $scope = request('scope', 'all');
        $year  = (int) request('year', now()->year);
        $month = (int) request('month', now()->month);

        $creditsQuery     = DailyLog::where('employee_id', $employee->id);
        $violationsQuery  = Violation::where('employee_id', $employee->id)->where('is_driver_liable', true);
        $maintenanceQuery = MaintenanceRecord::where('liable_employee_id', $employee->id);
        $custodyQuery     = CustodyItem::where('employee_id', $employee->id)->whereIn('return_condition', ['damaged', 'lost']);
        $advanceQuery     = \App\Models\AdvanceDeduction::whereHas('salaryAdvance', function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
        });
        $leaveQuery       = \App\Models\EmployeeLeave::where('employee_id', $employee->id)->where('status', 'approved')->where('is_paid', false);
        $cashSettlementQuery = \App\Models\CashSettlement::where('employee_id', $employee->id);

        if ($scope === 'month') {
            $creditsQuery->whereYear('log_date', $year)->whereMonth('log_date', $month);
            $violationsQuery->whereYear('violation_date', $year)->whereMonth('violation_date', $month);
            $maintenanceQuery->whereYear('maintenance_date', $year)->whereMonth('maintenance_date', $month);
            
            $custodyQuery->where(function ($q) use ($year, $month) {
                $q->whereYear('returned_date', $year)->whereMonth('returned_date', $month)
                  ->orWhere(function ($sub) use ($year, $month) {
                      $sub->whereNull('returned_date')->whereYear('created_at', $year)->whereMonth('created_at', $month);
                  });
            });
            
            $advanceQuery->whereYear('deduction_date', $year)->whereMonth('deduction_date', $month);
            $leaveQuery->whereYear('start_date', $year)->whereMonth('start_date', $month);
            $cashSettlementQuery->whereYear('settlement_date', $year)->whereMonth('settlement_date', $month);
        }

        // Calculate credits
        $totalOrders    = (int) $creditsQuery->sum('orders_count');
        $ratePerOrder   = (float) ($employee->rate_per_order ?? 0.0);
        $ordersEarning  = (float) round($creditsQuery->sum('driver_commission'), 3);
        
        $cashReturned   = (float) $cashSettlementQuery->sum('amount');
        if ($cashReturned === 0.0) {
            $cashReturned = (float) $creditsQuery->clone()->sum('cash_settled');
        }
        
        $companyRevenue = (float) $creditsQuery->sum('income_amount');

        // Calculate debits
        $violationsTotal  = (float) $violationsQuery->sum('amount');
        $maintenanceTotal = (float) $maintenanceQuery->sum('driver_deduction');
        $custodyTotal     = (float) $custodyQuery->sum('deduction_amount');
        $advanceTotal     = (float) $advanceQuery->sum('amount');
        $leaveTotal       = (float) $leaveQuery->sum('total_deduction');

        $totalCredits    = $ordersEarning;
        $totalDeductions = $violationsTotal + $maintenanceTotal + $custodyTotal + $advanceTotal + $leaveTotal;
        $netBalance      = $totalCredits - $totalDeductions;

        return response()->json([
            'employee_id'      => $employee->id,
            'employee_name'    => $employee->name,
            'total_credits'    => (float) $totalCredits,
            'total_deductions' => (float) $totalDeductions,
            'net_balance'      => (float) $netBalance,
            'credits' => [
                'orders_earning'  => (float) $ordersEarning,
                'total_orders'    => (int)   $totalOrders,
                'rate_per_order'  => (float) $ratePerOrder,
                'cash_returned'   => (float) $cashReturned,
                'company_revenue' => (float) $companyRevenue,
            ],
            'debits' => [
                'violations'  => (float) $violationsTotal,
                'maintenance' => (float) $maintenanceTotal,
                'custody'     => (float) $custodyTotal,
                'advances'    => (float) $advanceTotal,
                'leaves'      => (float) $leaveTotal,
                'total'       => (float) $totalDeductions,
            ],
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:employees,id',
        ]);

        $companyId = app('current_company_id');
        $employees = Employee::whereIn('id', $validated['ids'])
            ->where('company_id', $companyId)
            ->get();

        $allBlocks = [];
        foreach ($employees as $employee) {
            $blocks = $employee->getDeletionBlocks();
            if (!empty($blocks)) {
                $allBlocks[$employee->name] = $blocks;
            }
        }

        if (!empty($allBlocks)) {
            // Flatten or format error message
            $flatErrors = [];
            foreach ($allBlocks as $name => $reasons) {
                foreach ($reasons as $reason) {
                    $flatErrors[] = "{$name}: {$reason}";
                }
            }
            return response()->json([
                'message' => 'لا يمكن حذف بعض الموظفين المحددين لوجود ارتباطات نشطة.',
                'errors' => $flatErrors,
            ], 422);
        }

        $count = 0;
        foreach ($employees as $employee) {
            $employee->delete();
            $count++;
        }

        return response()->json([
            'message' => "تم حذف $count من الموظفين بنجاح.",
            'deleted_count' => $count
        ]);
    }
}
