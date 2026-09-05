<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\ContractScopeService;
use App\Services\EmployeeLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allowedDriverIds = ContractScopeService::getAllocatedDriverIds($request->user());
        $perPage = $request->boolean('all') ? 5000 : min(max($request->integer('per_page', 50), 5), 1000);
        $employees = Employee::with(['user:id,name,email', 'adminRole:id,name', 'activeAssignment.vehicle:id,plate_number,vehicle_type_id'])
            ->when($allowedDriverIds !== null, fn ($q) => $q->whereIn('id', $allowedDriverIds))
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->pay_type, fn ($q) => $q->where('pay_type', $request->pay_type))
            ->when($request->role_category, fn ($q) => $q->where('role_category', $request->role_category))
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
        if (! $request->user()->can('employees.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة موظف جديد.'], 403);
        }

        $companyId = app('current_company_id');

        if ($request->has('assigned_role_id') && ! $request->has('admin_role_id')) {
            $request->merge(['admin_role_id' => $request->input('assigned_role_id')]);
        }

        foreach (['civil_id', 'phone', 'email', 'date_of_birth', 'health_card_expiry', 'residence_expiry', 'driving_license_expiry', 'work_permit_expiry'] as $nullableField) {
            if ($request->has($nullableField) && $request->input($nullableField) === '') {
                $request->merge([$nullableField => null]);
            }
        }

        if ($request->filled('email')) {
            $emailVal = str_replace(',', '.', trim($request->input('email')));
            $request->merge(['email' => $emailVal]);

            // Release email if an orphan User record exists with this email
            $orphanUser = User::where('email', $emailVal)->first();
            if ($orphanUser) {
                $hasActiveEmp = Employee::withoutGlobalScopes()
                    ->where('user_id', $orphanUser->id)
                    ->whereNull('deleted_at')
                    ->exists();
                if (! $hasActiveEmp) {
                    $orphanUser->update(['email' => $emailVal.'_old_'.time().'@deleted.local']);
                }
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:100',
            'civil_id' => [
                'nullable',
                'string',
                Rule::unique('employees', 'civil_id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('employees', 'phone')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'has_whatsapp' => 'nullable|boolean',
            'whatsapp_company_number' => 'nullable|string|max:30',
            'whatsapp_language' => 'nullable|string|max:10',
            'gender' => 'in:male,female',
            'date_of_birth' => 'nullable|date',
            'date_of_joining' => 'required|date',
            'employee_type' => 'in:overseas,local_transfer',
            'pay_type' => 'required|in:fixed,per_order,hybrid',
            'official_salary' => 'required|numeric|min:0',
            'actual_salary' => 'required|numeric|min:0',
            'rate_per_order' => 'required_if:pay_type,per_order,hybrid|nullable|numeric|min:0',
            'has_end_of_service' => 'boolean',
            'health_card_expiry' => 'nullable|date',
            'residence_expiry' => 'nullable|date',
            'driving_license_expiry' => 'nullable|date',
            'work_permit_expiry' => 'nullable|date',
            'notes' => 'nullable|string',
            'target_orders_monthly' => 'nullable|integer|min:0',
            'premium_commission_rate' => 'nullable|numeric|min:0',

            'role_category' => 'nullable|in:driver,admin',
            'admin_role_id' => 'required_if:role_category,admin|nullable|exists:roles,id',
            'salary_allocations' => 'nullable|array',
            'email' => 'required_if:role_category,admin|nullable|email|max:255|unique:users,email',
            'password' => 'required_if:role_category,admin|nullable|string|min:6',
        ]);

        // ── Auto-generate employee number: EMP-0001, EMP-0002, ... ──
        // Use DB::table to bypass ALL Eloquent global scopes reliably
        $lastNum = \DB::table('employees')
            ->where('company_id', app('current_company_id'))
            ->where('employee_number', 'like', 'EMP-%')
            ->selectRaw('MAX(CAST(SUBSTR(employee_number, 5) AS UNSIGNED)) as max_num')
            ->value('max_num');
        $validated['employee_number'] = 'EMP-'.str_pad(($lastNum ?? 0) + 1, 4, '0', STR_PAD_LEFT);

        // Auto-set probation period: 3 months from joining
        $validated['status'] = 'probation';
        $validated['probation_end_date'] = Carbon::parse($validated['date_of_joining'])->addMonths(3)->toDateString();

        $employee = Employee::create($validated);

        if (($validated['role_category'] ?? 'driver') === 'admin' && ! empty($validated['email'])) {
            $roleId = $validated['admin_role_id'] ?? $request->input('assigned_role_id') ?? null;
            $roleName = 'admin';
            $numericAdminRoleId = is_numeric($roleId) ? (int) $roleId : null;
            if (! empty($roleId)) {
                $roleModel = Role::where('company_id', $companyId)
                    ->where(function ($q) use ($roleId) {
                        $q->where('id', $roleId)->orWhere('name', $roleId);
                    })->first();

                if ($roleModel) {
                    $roleName = $roleModel->name;
                    $numericAdminRoleId = $roleModel->id;
                } elseif (! is_numeric($roleId)) {
                    $roleName = $roleId;
                }
            }
            $user = User::create([
                'name' => $employee->name,
                'email' => $validated['email'],
                'password' => bcrypt($validated['password'] ?? 'password123'),
                'role' => mb_substr($roleName ?? 'admin', 0, 20),
                'company_id' => $companyId,
            ]);
            $employee->update([
                'user_id' => $user->id,
                'admin_role_id' => $numericAdminRoleId,
            ]);
        }

        return response()->json($employee->load(['user', 'adminRole']), 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json($employee->load([
            'user:id,name,email',
            'adminRole:id,name',
            'vehicleAssignments.vehicle:id,plate_number,make,model,vehicle_type_id',
            'vehicleAssignments.contract:id,name,target_orders_monthly,base_commission_rate,premium_commission_rate',
        ]));
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->can('employees.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل بيانات الموظفين.'], 403);
        }

        $companyId = app('current_company_id');

        if ($request->has('assigned_role_id') && ! $request->has('admin_role_id')) {
            $request->merge(['admin_role_id' => $request->input('assigned_role_id')]);
        }

        foreach (['civil_id', 'phone', 'email', 'date_of_birth', 'health_card_expiry', 'residence_expiry', 'driving_license_expiry', 'work_permit_expiry'] as $nullableField) {
            if ($request->has($nullableField) && $request->input($nullableField) === '') {
                $request->merge([$nullableField => null]);
            }
        }

        if ($request->filled('email')) {
            $emailVal = str_replace(',', '.', trim($request->input('email')));
            $request->merge(['email' => $emailVal]);

            // Release email if an orphan User record exists with this email (other than current)
            $orphanUser = User::where('email', $emailVal)
                ->where('id', '!=', $employee->user_id)
                ->first();
            if ($orphanUser) {
                $hasActiveEmp = Employee::withoutGlobalScopes()
                    ->where('user_id', $orphanUser->id)
                    ->whereNull('deleted_at')
                    ->exists();
                if (! $hasActiveEmp) {
                    $orphanUser->update(['email' => $emailVal.'_old_'.time().'@deleted.local']);
                }
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:100',
            'civil_id' => [
                'nullable',
                'string',
                Rule::unique('employees', 'civil_id')->ignore($employee->id)->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('employees', 'phone')->ignore($employee->id)->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'has_whatsapp' => 'nullable|boolean',
            'whatsapp_company_number' => 'nullable|string|max:30',
            'whatsapp_language' => 'nullable|string|max:10',
            'gender' => 'sometimes|in:male,female',
            'date_of_birth' => 'nullable|date',
            'date_of_joining' => 'sometimes|date',
            'employee_type' => 'sometimes|in:overseas,local_transfer',
            'pay_type' => 'sometimes|in:fixed,per_order,hybrid',
            'status' => 'sometimes|in:active,inactive,on_leave,probation',
            'status_reason' => 'nullable|string|max:255',
            'official_salary' => 'sometimes|numeric|min:0',
            'actual_salary' => 'sometimes|numeric|min:0',
            'rate_per_order' => 'sometimes|nullable|numeric|min:0',
            'has_end_of_service' => 'sometimes|boolean',
            'health_card_expiry' => 'nullable|date',
            'residence_expiry' => 'nullable|date',
            'driving_license_expiry' => 'nullable|date',
            'work_permit_expiry' => 'nullable|date',
            'target_orders_monthly' => 'nullable|integer|min:0',
            'premium_commission_rate' => 'nullable|numeric|min:0',
            // Overseas onboarding stages
            'stage_arrived' => 'sometimes|boolean',
            'stage_medical_done' => 'sometimes|boolean',
            'stage_medical_date' => 'nullable|date',
            'stage_work_permit_done' => 'sometimes|boolean',
            'stage_work_permit_date' => 'nullable|date',
            'stage_driving_trial_done' => 'sometimes|boolean',
            'stage_license_obtained' => 'sometimes|boolean',
            'stage_license_date' => 'nullable|date',
            'notes' => 'nullable|string',

            'role_category' => 'sometimes|nullable|in:driver,admin',
            'admin_role_id' => 'nullable|exists:roles,id',
            'salary_allocations' => 'nullable|array',
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($employee->user_id),
            ],
            'password' => 'nullable|string|min:6',
        ]);

        if (isset($validated['status'])) {
            $validated['status_changed_at'] = now()->toDateString();
        }

        $employee->update($validated);

        if (($employee->role_category) === 'admin' && ! empty($validated['email'])) {
            $roleId = $validated['admin_role_id'] ?? $request->input('assigned_role_id') ?? $employee->admin_role_id ?? null;
            $roleName = 'admin';
            $numericAdminRoleId = is_numeric($roleId) ? (int) $roleId : null;
            if (! empty($roleId)) {
                $roleModel = Role::where('company_id', $companyId)
                    ->where(function ($q) use ($roleId) {
                        $q->where('id', $roleId)->orWhere('name', $roleId);
                    })->first();

                if ($roleModel) {
                    $roleName = $roleModel->name;
                    $numericAdminRoleId = $roleModel->id;
                } elseif (! is_numeric($roleId)) {
                    $roleName = $roleId;
                }
            }

            if ($employee->user_id) {
                $user = User::find($employee->user_id);
                if ($user) {
                    $updateData = [
                        'name' => $employee->name,
                        'email' => $validated['email'],
                        'role' => mb_substr($roleName ?? 'admin', 0, 20),
                    ];
                    if (! empty($validated['password'])) {
                        $updateData['password'] = bcrypt($validated['password']);
                    }
                    $user->update($updateData);
                }
                $employee->update(['admin_role_id' => $numericAdminRoleId]);
            } else {
                $user = User::create([
                    'name' => $employee->name,
                    'email' => $validated['email'],
                    'password' => bcrypt($validated['password'] ?? 'password123'),
                    'role' => mb_substr($roleName ?? 'admin', 0, 20),
                    'company_id' => $companyId,
                ]);
                $employee->update([
                    'user_id' => $user->id,
                    'admin_role_id' => $numericAdminRoleId,
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

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->can('employees.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف الموظفين.'], 403);
        }

        $blocks = $employee->getDeletionBlocks();
        if (! empty($blocks)) {
            return response()->json([
                'message' => 'لا يمكن حذف الموظف لوجود ارتباطات نشطة.',
                'errors' => $blocks,
            ], 422);
        }

        if ($employee->user_id) {
            $u = User::find($employee->user_id);
            if ($u) {
                $u->update(['email' => $u->email.'_deleted_'.time().'@deleted.local']);
            }
        }

        $employee->delete();

        return response()->json(['message' => 'Employee deleted.']);
    }

    /**
     * GET /api/employees/{employee}/history
     * Month by month: what the driver earned, what was deducted, and what was left — with
     * approved months read from their frozen snapshot rather than recomputed.
     */
    public function history(Request $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->can('employees.view')) {
            return response()->json(['message' => 'غير مصرح لك بعرض بيانات الموظفين.'], 403);
        }

        return response()->json(EmployeeLedgerService::history(
            $employee,
            $request->query('from'),
            $request->query('to')
        ));
    }

    /**
     * A driver's running balance, read from the same ledger the month-by-month statement is built
     * from rather than computed again here.
     *
     * It used to have rules of its own, and every one of them was wrong in the same direction:
     * credits came from `daily_logs.driver_commission`, so a driver on a fixed salary showed
     * 0.000 earned however long he had worked; a fine was charged at its whole value rather than
     * the driver's share of it; driver expenses were left out of the debits altogether; advances
     * counted what had already been repaid instead of what is owed; and nothing checked whether a
     * charge had already been collected, so an approved month was charged twice. Two drivers — one
     * who worked a full month and one who never worked a day — came out with the identical figure,
     * and the profile showed both of them as owing the company money.
     */
    public function balance(Employee $employee): JsonResponse
    {
        $scope = request('scope', 'all');
        $year = (int) request('year', now()->year);
        $month = (int) request('month', now()->month);

        // A month scope asks the ledger for that one month; anything else asks for the whole record.
        $ym = $scope === 'month' ? sprintf('%04d-%02d', $year, $month) : null;
        $ledger = EmployeeLedgerService::history($employee, $ym, $ym);

        $totals = $ledger['totals'];
        $deductions = $totals['deductions'];

        $credits = round((float) $totals['gross_earnings'], 3);
        $debits = round((float) $totals['deductions_total'], 3);

        // Revenue is the company's figure, not the driver's, and has no place in his ledger.
        $revenueQuery = DailyLog::where('employee_id', $employee->id);
        if ($scope === 'month') {
            $revenueQuery->whereYear('log_date', $year)->whereMonth('log_date', $month);
        }

        return response()->json([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'total_credits' => $credits,
            'total_deductions' => $debits,
            'net_balance' => round($credits - $debits, 3),
            // Named so the screen can say which period it is showing. The old figure silently
            // covered the current calendar month, so a driver carrying months of debt could read
            // as settled on the first of a new one.
            'scope' => $scope,
            'period' => $scope === 'month' ? $ym : 'all',
            'months_counted' => (int) $totals['months'],
            'credits' => [
                'orders_earning' => $credits,
                'total_orders' => (int) $totals['orders_count'],
                'work_days' => (int) $totals['work_days'],
                'rate_per_order' => (float) ($employee->rate_per_order ?? 0.0),
                'cash_returned' => round((float) $totals['cash_collected'], 3),
                'company_revenue' => (float) $revenueQuery->sum('income_amount'),
            ],
            'debits' => [
                'violations' => round((float) ($deductions['violations'] ?? 0), 3),
                'maintenance' => round((float) ($deductions['maintenance'] ?? 0), 3),
                'custody' => round((float) ($deductions['custody'] ?? 0), 3),
                'driver_expenses' => round((float) ($deductions['driver_expenses'] ?? 0), 3),
                'advances' => round((float) ($deductions['advances'] ?? 0), 3),
                'total' => $debits,
            ],
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        if (! $request->user()->can('employees.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف الموظفين.'], 403);
        }

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
            if (! empty($blocks)) {
                $allBlocks[$employee->name] = $blocks;
            }
        }

        if (! empty($allBlocks)) {
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
            if ($employee->user_id) {
                $u = User::find($employee->user_id);
                if ($u) {
                    $u->update(['email' => $u->email.'_deleted_'.time().'@deleted.local']);
                }
            }
            $employee->delete();
            $count++;
        }

        return response()->json([
            'message' => "تم حذف $count من الموظفين بنجاح.",
            'deleted_count' => $count,
        ]);
    }
}
