<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ErpSync;
use App\Http\Controllers\Controller;
use App\Models\AdvanceDeduction;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractMandatoryDay;
use App\Models\ContractMonthlyParameter;
use App\Models\ContractPayrollRun;
use App\Models\CurrencyExchangeRate;
use App\Models\CustodyItem;
use App\Models\DailyLog;
use App\Models\DriverContractOverride;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\MaintenanceRecord;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use App\Models\SalaryAdvance;
use App\Models\VehicleAssignment;
use App\Models\Violation;
use App\Services\Contracts\SmartValueFallbackService;
use App\Services\ErpNext\Jobs\SyncFuelExpenseJob;
use App\Services\ErpNext\Jobs\SyncPayrollDeductionsJob;
use App\Services\ErpNext\Jobs\SyncPayrollJob;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    /**
     * POST /api/payroll/run
     * Generate monthly payroll for all active employees.
     * System auto-calculates — prevents manual tampering from meeting.
     */
    public function run(Request $request): JsonResponse
    {
        if (!$request->user()->can('payroll.create')) {
            return response()->json(['message' => 'غير مصرح لك باحتساب الرواتب.'], 403);
        }

        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|integer|min:1|max:12',
            'notes' => 'nullable|string',
        ]);

        $year = $validated['year'];
        $month = $validated['month'];

        \App\Services\Contracts\SmartValueFallbackService::clearCache();

        // Prevent duplicate runs OR auto-recalculate if draft
        $existingRun = PayrollRun::where('year', $year)->where('month', $month)->first();
        if ($existingRun) {
            if ($existingRun->status === 'draft') {
                self::recalculateRun($existingRun);

                return response()->json([
                    'message' => 'تم تحديث وإعادة احتساب رواتب هذا الشهر بنجاح.',
                    'run_id' => $existingRun->id,
                    'employees' => Employee::where('role_category', 'driver')->whereIn('status', ['active', 'probation'])->count(),
                    'total_official' => $existingRun->total_official,
                    'total_actual' => $existingRun->total_actual,
                    'total_cash' => $existingRun->total_cash_diff,
                ], 200);
            }

            return response()->json(['message' => 'تم احتساب واعتماد رواتب هذا الشهر مسبقاً.'], 422);
        }

        $startDate = "{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01';
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        DB::beginTransaction();
        try {
            $run = PayrollRun::create([
                'year' => $year,
                'month' => $month,
                'created_by' => $request->user()->id,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
            ]);

            $employees = Employee::where('role_category', 'driver')->whereIn('status', ['active', 'probation'])->get();
            $employeeIds = $employees->pluck('id');
            $totalOfficial = 0;
            $totalActual = 0;

            // 1. Daily logs pre-fetched
            $allDailyLogs = DailyLog::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('log_date', [$startDate, $endDate])
                ->orderBy('log_date')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'vehicle_id', 'shift_valid', 'online_hours', 'ontime_rate', 'avg_delivery_time', 'is_valid', 'late_login', 'early_logout', 'zone'])
                ->groupBy('employee_id');

            $violationSums = Violation::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('is_driver_liable', true)
                ->where('is_deducted', false)
                ->whereDate('violation_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(CASE WHEN driver_deduction > 0 THEN driver_deduction ELSE amount END) as total')
                ->pluck('total', 'employee_id');

            // 3. Maintenance deductions
            $maintenanceSums = MaintenanceRecord::withoutGlobalScopes()
                ->whereIn('liable_employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('maintenance_date', '<=', $endDate)
                ->groupBy('liable_employee_id')
                ->selectRaw('liable_employee_id, SUM(driver_deduction) as total')
                ->pluck('total', 'liable_employee_id');

            // 4. Custody deductions
            $custodySums = CustodyItem::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'returned')
                ->whereIn('return_condition', ['damaged', 'lost'])
                ->where('deduction_amount', '>', 0)
                ->whereDate('returned_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(deduction_amount) as total')
                ->pluck('total', 'employee_id');

            // 5. Leave deductions
            $leaveData = EmployeeLeave::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->where('is_paid', false)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])
                        ->orWhereBetween('end_date', [$startDate, $endDate])
                        ->orWhere(function ($q2) use ($startDate, $endDate) {
                            $q2->where('start_date', '<=', $startDate)
                                ->where('end_date', '>=', $endDate);
                        });
                })
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(total_deduction) as total_deduction, SUM(days_count) as total_days')
                ->get()
                ->keyBy('employee_id');

            // 6. Active salary advances
            $allAdvances = SalaryAdvance::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
                ->whereDate('advance_date', '<=', $endDate)
                ->get()
                ->groupBy('employee_id');

            // 7. Vehicle assignments for fuel allowance
            $allFuelAssignments = \DB::table('vehicle_assignments')
                ->join('vehicles', 'vehicles.id', '=', 'vehicle_assignments.vehicle_id')
                ->whereIn('vehicle_assignments.employee_id', $employeeIds)
                ->where('vehicle_assignments.assigned_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('vehicle_assignments.unassigned_date')
                        ->orWhere('vehicle_assignments.unassigned_date', '>=', $startDate);
                })
                ->select('vehicle_assignments.employee_id', 'vehicles.monthly_fuel_allowance')
                ->get()
                ->groupBy('employee_id');

            // 8. Contract assignments for drivers
            $allContractAssignments = ContractAssignment::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('start_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('end_date')
                        ->orWhere('end_date', '>=', $startDate);
                })
                ->get()
                ->groupBy('employee_id');

            foreach ($employees as $employee) {
                $empId = $employee->id;

                $data = self::calculateDriverSlipData(
                    $employee,
                    $year,
                    $month,
                    $startDate,
                    $endDate,
                    $allDailyLogs,
                    $violationSums,
                    $maintenanceSums,
                    $custodySums,
                    $leaveData,
                    $allAdvances,
                    $allContractAssignments
                );

                $totalBonuses = $data['orders_bonus'] + $data['fuel_allowance'] + $data['total_contract_bonuses'];
                $totalGrossActual = $data['base_actual'] + $totalBonuses;
                $totalDeductions = $data['violations_deduction'] + $data['maintenance_deduction'] + $data['custody_deduction'] + $data['leave_deduction'] + $data['advance_deduction'];

                $netActual = $totalGrossActual - $totalDeductions;

                if ($netActual < 0) {
                    $netBank = (float) $employee->official_salary;
                    $netCash = 0.0;

                    SalaryAdvance::where('employee_id', $employee->id)
                        ->where('company_id', $employee->company_id)
                        ->where('reason', 'like', '%ترصيد عجز مالي وسالفة راتب سالب لشهر '.$month.'/'.$year.'%')
                        ->delete();

                    // Create SalaryAdvance to be deducted next month
                    $debitAmount = $netBank - $netActual;
                    $nextMonthDate = Carbon::parse($startDate)->addMonth();

                    SalaryAdvance::create([
                        'employee_id' => $employee->id,
                        'company_id' => $employee->company_id,
                        'amount' => $debitAmount,
                        'advance_date' => $nextMonthDate->startOfMonth()->toDateString(),
                        'monthly_installment' => $debitAmount,
                        'total_installments' => 1,
                        'remaining_balance' => $debitAmount,
                        'approved_by' => auth()->id() ?? 1,
                        'status' => 'active',
                        'reason' => 'ترصيد عجز مالي وسالفة راتب سالب لشهر '.$month.'/'.$year,
                    ]);
                } else {
                    $netBank = min($netActual, (float) $employee->official_salary);
                    $netCash = max(0.0, $netActual - $netBank);
                }

                $slip = PayrollSlip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'base_official' => $employee->official_salary,
                    'base_actual' => $data['base_actual'],
                    'orders_bonus' => $data['orders_bonus'],
                    'fuel_allowance' => $data['fuel_allowance'],
                    'total_orders' => $data['total_orders'],
                    'violations_deduction' => $data['violations_deduction'],
                    'maintenance_deduction' => $data['maintenance_deduction'],
                    'custody_deduction' => $data['custody_deduction'],
                    'driver_expense_deduction' => $data['driver_expense_deduction'] ?? 0,
                    'advance_deduction' => $data['advance_deduction'],
                    'leave_deduction' => $data['leave_deduction'],
                    'unpaid_leave_days' => $data['unpaid_leave_days'],
                    'gross_official' => $netBank,
                    'gross_actual' => $netActual,
                    'cash_portion' => $netCash,
                    'final_monthly_status' => $data['final_monthly_status'],
                    'total_capacity_incentive' => $data['total_capacity_incentive'],
                    'total_experience_incentive' => $data['total_experience_incentive'],
                    'total_contract_bonuses' => $data['total_contract_bonuses'],
                ]);

                // Mark violations as deducted
                if ($data['violations_deduction'] > 0) {
                    Violation::where('employee_id', $employee->id)
                        ->where('is_driver_liable', true)
                        ->where('is_deducted', false)
                        ->whereDate('violation_date', '<=', $endDate)
                        ->update(['is_deducted' => true, 'payroll_slip_id' => $slip->id]);
                }

                // Mark driver expenses as deducted
                if (($data['driver_expense_deduction'] ?? 0) > 0) {
                    \App\Models\DriverExpense::where('employee_id', $employee->id)
                        ->where('driver_amount', '>', 0)
                        ->where('is_deducted', false)
                        ->whereDate('expense_date', '<=', $endDate)
                        ->update(['is_deducted' => true, 'payroll_slip_id' => $slip->id]);
                }

                // Create AdvanceDeduction records & update advances
                $activeAdvances = $allAdvances->get($employee->id, collect());
                foreach ($activeAdvances as $advance) {
                    $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
                    if ($installment <= 0) {
                        continue;
                    }

                    AdvanceDeduction::create([
                        'salary_advance_id' => $advance->id,
                        'payroll_slip_id' => $slip->id,
                        'amount' => $installment,
                        'deduction_date' => $endDate,
                        'company_id' => $advance->company_id,
                    ]);

                    $advance->paid_installments += 1;
                    $advance->remaining_balance = max(0, (float) $advance->remaining_balance - $installment);
                    if ($advance->remaining_balance <= 0) {
                        $advance->status = 'completed';
                    }
                    $advance->saveQuietly();
                }

                $totalOfficial += $netBank;
                $totalActual += $netActual;
            }

            $run->update([
                'total_official' => $totalOfficial,
                'total_actual' => $totalActual,
                'total_cash_diff' => $totalActual - $totalOfficial,
            ]);

            DB::commit();

            // Sync official payroll to ERPNext
            ErpSync::dispatch(SyncPayrollJob::class,
                $employees->pluck('id')->toArray(),
                (string) $year,
                str_pad((string) $month, 2, '0', STR_PAD_LEFT)
            );

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'فشل في احتساب الرواتب: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'تم احتساب رواتب الشهر بنجاح.',
            'run_id' => $run->id,
            'employees' => $employees->count(),
            'total_official' => $run->total_official,
            'total_actual' => $run->total_actual,
            'total_cash' => $run->total_cash_diff,
        ], 201);
    }

    /**
     * GET /api/payroll/{year}/{month}
     */
    public function show(Request $request, int $year, int $month): JsonResponse
    {
        $run = PayrollRun::where('year', $year)->where('month', $month)
            ->with(['createdBy:id,name', 'approvedBy:id,name'])
            ->firstOrFail();

        if ($request->has('page') || $request->has('paginate')) {
            $perPage = min(max($request->integer('per_page', 50), 5), 100);

            $slips = PayrollSlip::where('payroll_run_id', $run->id)
                ->with(['employee:id,name,official_salary,actual_salary'])
                ->paginate($perPage);

            $slips->getCollection()->each(function ($slip) {
                if ($slip->employee) {
                    $slip->employee->setAppends([]);
                    $slip->employee->unsetRelation('vehicleAssignments');
                }
            });

            $run->setRelation('slips', $slips);
        } else {
            $run->load(['slips.employee:id,name,official_salary,actual_salary']);

            $run->slips->each(function ($slip) {
                if ($slip->employee) {
                    $slip->employee->setAppends([]);
                    $slip->employee->unsetRelation('vehicleAssignments');
                }
            });
        }

        return response()->json($run);
    }

    /**
     * GET /api/payroll/{year}/{month}/{employee}
     */
    public function slip(int $year, int $month, Employee $employee): JsonResponse
    {
        $run = PayrollRun::where('year', $year)->where('month', $month)->firstOrFail();
        $slip = PayrollSlip::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        return response()->json([
            'employee' => $employee->only(['id', 'name', 'official_salary', 'actual_salary', 'pay_type']),
            'period' => "{$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT),
            'official_sheet' => [
                'base' => $slip->base_official,
                'fuel' => $slip->fuel_allowance,
                'deductions' => $slip->violations_deduction + $slip->maintenance_deduction + $slip->custody_deduction + $slip->advance_deduction + $slip->leave_deduction,
                'net' => $slip->gross_official,
            ],
            'internal_sheet' => [
                'base' => $slip->base_actual,
                'orders_bonus' => $slip->orders_bonus,
                'total_orders' => $slip->total_orders,
                'fuel_allowance' => $slip->fuel_allowance,
                'violations_deduction' => $slip->violations_deduction,
                'maintenance_deduction' => $slip->maintenance_deduction,
                'custody_deduction' => $slip->custody_deduction,
                'advance_deduction' => $slip->advance_deduction,
                'leave_deduction' => $slip->leave_deduction,
                'unpaid_leave_days' => $slip->unpaid_leave_days,
                'gross' => $slip->gross_actual,
                'bank_portion' => $slip->gross_official,
                'cash_portion' => $slip->cash_portion,
                'final_monthly_status' => $slip->final_monthly_status ?? 'valid',
                'total_capacity_incentive' => $slip->total_capacity_incentive ?? 0,
                'total_experience_incentive' => $slip->total_experience_incentive ?? 0,
                'total_contract_bonuses' => $slip->total_contract_bonuses ?? 0,
            ],
        ]);
    }

    /**
     * POST /api/payroll/{year}/{month}/approve
     */
    public function approve(Request $request, int $year, int $month): JsonResponse
    {
        if (!$request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك باكتفاء أو اعتماد مسير الرواتب.'], 403);
        }

        $run = PayrollRun::where('year', $year)->where('month', $month)->firstOrFail();

        if ($run->status !== 'draft') {
            return response()->json(['message' => 'لا يمكن اعتماد الرواتب إلا في حالة المسودة.'], 422);
        }

        $run->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $slips = PayrollSlip::where('payroll_run_id', $run->id)->get();

        $totalViolations = round($slips->sum('violations_deduction'), 3);
        $totalMaintenance = round($slips->sum('maintenance_deduction'), 3);
        $totalCustody = round($slips->sum('custody_deduction'), 3);
        $totalAdvances = round($slips->sum('advance_deduction'), 3);
        $totalDeductions = $totalViolations + $totalMaintenance + $totalCustody + $totalAdvances;
        $totalFuel = round($slips->sum('fuel_allowance'), 3);

        $paddedMonth = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        if ($totalDeductions > 0) {
            ErpSync::dispatch(
                SyncPayrollDeductionsJob::class,
                (string) $year,
                $paddedMonth,
                $totalViolations,
                $totalMaintenance,
                $totalCustody,
                $totalAdvances
            );
        }

        if ($totalFuel > 0) {
            ErpSync::dispatch(
                SyncFuelExpenseJob::class,
                (string) $year,
                $paddedMonth,
                $totalFuel
            );
        }

        return response()->json([
            'message' => 'تم اعتماد رواتب الشهر بنجاح.',
            'erp_sync' => [
                'deductions_synced' => $totalDeductions > 0,
                'fuel_synced' => $totalFuel > 0,
            ],
            'summary' => [
                'violations' => $totalViolations,
                'maintenance' => $totalMaintenance,
                'custody' => $totalCustody,
                'advances' => $totalAdvances,
                'total_deducted' => $totalDeductions,
                'fuel_allowance' => $totalFuel,
            ],
        ]);
    }

    /**
     * Recalculate draft payroll run.
     */
    public static function recalculateRun(PayrollRun $run): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock("payroll_recalc_{$run->id}", 30);
        if (!$lock->get()) {
            return;
        }

        try {
            \App\Services\Contracts\SmartValueFallbackService::clearCache();
            $year = $run->year;
            $month = $run->month;
            $startDate = "{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01';
            $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

            $slipIds = PayrollSlip::where('payroll_run_id', $run->id)->pluck('id')->toArray();

            // Revert previous advance deductions first to avoid double counting
            $previousDeductions = AdvanceDeduction::whereIn('payroll_slip_id', $slipIds)->get();
            foreach ($previousDeductions as $ded) {
                $advance = $ded->salaryAdvance;
                if ($advance) {
                    $advance->remaining_balance += $ded->amount;
                    $advance->paid_installments = max(0, $advance->paid_installments - 1);
                    if ($advance->status === 'completed') {
                        $advance->status = 'active';
                    }
                    $advance->saveQuietly();
                }
                $ded->delete();
            }

            // Uncheck violations previously deducted in this run by primary key (autocommit)
            $violationIdsToUncheck = \DB::table('violations')
                ->whereIn('payroll_slip_id', $slipIds)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->toArray();

            if (!empty($violationIdsToUncheck)) {
                \DB::table('violations')
                    ->whereIn('id', $violationIdsToUncheck)
                    ->update([
                        'is_deducted' => false,
                        'payroll_slip_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            // Uncheck driver expenses previously deducted in this run by primary key (autocommit)
            $expenseIdsToUncheck = \DB::table('driver_expenses')
                ->whereIn('payroll_slip_id', $slipIds)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->toArray();

            if (!empty($expenseIdsToUncheck)) {
                \DB::table('driver_expenses')
                    ->whereIn('id', $expenseIdsToUncheck)
                    ->update([
                        'is_deducted' => false,
                        'payroll_slip_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            $employeeIds = PayrollSlip::withoutGlobalScopes()->where('payroll_run_id', $run->id)->pluck('employee_id')->toArray();
            if (empty($employeeIds)) {
                $employeeIds = Employee::withoutGlobalScopes()->where('role_category', 'driver')->whereIn('status', ['active', 'probation'])->pluck('id')->toArray();
            }

            $employees = Employee::withoutGlobalScopes()->whereIn('id', $employeeIds)->get();
            $totalOfficial = 0;
            $totalActual = 0;

            // 1. Daily logs
            $allDailyLogs = DailyLog::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('log_date', [$startDate, $endDate])
                ->orderBy('log_date')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'vehicle_id', 'shift_valid', 'online_hours', 'ontime_rate', 'avg_delivery_time', 'is_valid', 'late_login', 'early_logout', 'zone'])
                ->groupBy('employee_id');

            $violationSums = Violation::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('is_driver_liable', true)
                ->where(function ($q) use ($slipIds) {
                    $q->where('is_deducted', false)
                        ->orWhereIn('payroll_slip_id', $slipIds);
                })
                ->whereDate('violation_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(CASE WHEN driver_deduction > 0 THEN driver_deduction ELSE amount END) as total')
                ->pluck('total', 'employee_id');

            // 3. Maintenance deductions
            $maintenanceSums = MaintenanceRecord::withoutGlobalScopes()
                ->whereIn('liable_employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('maintenance_date', '<=', $endDate)
                ->groupBy('liable_employee_id')
                ->selectRaw('liable_employee_id, SUM(driver_deduction) as total')
                ->pluck('total', 'liable_employee_id');

            // 4. Custody deductions
            $custodySums = CustodyItem::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'returned')
                ->whereIn('return_condition', ['damaged', 'lost'])
                ->where('deduction_amount', '>', 0)
                ->whereDate('returned_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(deduction_amount) as total')
                ->pluck('total', 'employee_id');

            // 4b. Driver expense deductions
            $driverExpenseSums = \App\Models\DriverExpense::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('driver_amount', '>', 0)
                ->where(function ($q) use ($slipIds) {
                    $q->where('is_deducted', false)
                      ->orWhereIn('payroll_slip_id', $slipIds);
                })
                ->whereDate('expense_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(driver_amount) as total')
                ->pluck('total', 'employee_id');

            // 5. Leave deductions
            $leaveData = EmployeeLeave::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->where('is_paid', false)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])
                        ->orWhereBetween('end_date', [$startDate, $endDate])
                        ->orWhere(function ($q2) use ($startDate, $endDate) {
                            $q2->where('start_date', '<=', $startDate)
                                ->where('end_date', '>=', $endDate);
                        });
                })
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(total_deduction) as total_deduction, SUM(days_count) as total_days')
                ->get()
                ->keyBy('employee_id');

            // 6. Active salary advances
            $allAdvances = SalaryAdvance::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
                ->whereDate('advance_date', '<=', $endDate)
                ->get()
                ->groupBy('employee_id');

            // 7. Vehicle assignments for fuel allowance
            $allFuelAssignments = \DB::table('vehicle_assignments')
                ->join('vehicles', 'vehicles.id', '=', 'vehicle_assignments.vehicle_id')
                ->whereIn('vehicle_assignments.employee_id', $employeeIds)
                ->where('vehicle_assignments.assigned_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('vehicle_assignments.unassigned_date')
                        ->orWhere('vehicle_assignments.unassigned_date', '>=', $startDate);
                })
                ->select('vehicle_assignments.employee_id', 'vehicles.monthly_fuel_allowance')
                ->get()
                ->groupBy('employee_id');

            // 8. Contract assignments for drivers
            $allContractAssignments = ContractAssignment::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)
                ->where('start_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('end_date')
                        ->orWhere('end_date', '>=', $startDate);
                })
                ->with('contract')
                ->get()
                ->groupBy('employee_id');

            foreach ($employees as $employee) {
                $empId = $employee->id;

                $existingSlip = PayrollSlip::where('payroll_run_id', $run->id)->where('employee_id', $empId)->first();

                $data = self::calculateDriverSlipData(
                    $employee,
                    $year,
                    $month,
                    $startDate,
                    $endDate,
                    $allDailyLogs,
                    $violationSums,
                    $maintenanceSums,
                    $custodySums,
                    $leaveData,
                    $allAdvances,
                    $allContractAssignments,
                    $existingSlip,
                    $driverExpenseSums
                );
                $totalBonuses = $data['orders_bonus'] + $data['fuel_allowance'] + $data['total_contract_bonuses'];
                $totalGrossActual = $data['base_actual'] + $totalBonuses;
                $totalDeductions = $data['violations_deduction'] + $data['maintenance_deduction'] + $data['custody_deduction'] + ($data['driver_expense_deduction'] ?? 0) + $data['leave_deduction'] + $data['advance_deduction'];

                $netActual = $totalGrossActual - $totalDeductions;

                if ($netActual < 0) {
                    $netBank = (float) $employee->official_salary;
                    $netCash = 0.0;
                } else {
                    $netBank = min($netActual, (float) $employee->official_salary);
                    $netCash = max(0.0, $netActual - $netBank);
                }

                $slip = PayrollSlip::updateOrCreate([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                ], [
                    'base_official' => $employee->official_salary,
                    'base_actual' => $data['base_actual'],
                    'orders_bonus' => $data['orders_bonus'],
                    'fuel_allowance' => $data['fuel_allowance'],
                    'total_orders' => $data['total_orders'],
                    'violations_deduction' => $data['violations_deduction'],
                    'maintenance_deduction' => $data['maintenance_deduction'],
                    'custody_deduction' => $data['custody_deduction'],
                    'driver_expense_deduction' => $data['driver_expense_deduction'] ?? 0,
                    'advance_deduction' => $data['advance_deduction'],
                    'leave_deduction' => $data['leave_deduction'],
                    'unpaid_leave_days' => $data['unpaid_leave_days'],
                    'gross_official' => $netBank,
                    'gross_actual' => $netActual,
                    'cash_portion' => $netCash,
                    'final_monthly_status' => $data['final_monthly_status'],
                    'total_capacity_incentive' => $data['total_capacity_incentive'],
                    'total_experience_incentive' => $data['total_experience_incentive'],
                    'total_contract_bonuses' => $data['total_contract_bonuses'],
                ]);

                // Re-mark violations as deducted by primary key
                if ($data['violations_deduction'] > 0) {
                    $vIds = Violation::withoutGlobalScopes()
                        ->where('employee_id', $employee->id)
                        ->where('is_driver_liable', true)
                        ->where('is_deducted', false)
                        ->whereDate('violation_date', '<=', $endDate)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($vIds)) {
                        Violation::withoutGlobalScopes()
                            ->whereIn('id', $vIds)
                            ->update([
                                'is_deducted' => true,
                                'payroll_slip_id' => $slip->id,
                            ]);
                    }
                }

                // Re-mark driver expenses as deducted by primary key
                if (($data['driver_expense_deduction'] ?? 0) > 0) {
                    $expIds = \App\Models\DriverExpense::withoutGlobalScopes()
                        ->where('employee_id', $employee->id)
                        ->where('driver_amount', '>', 0)
                        ->where('is_deducted', false)
                        ->whereDate('expense_date', '<=', $endDate)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($expIds)) {
                        \App\Models\DriverExpense::withoutGlobalScopes()
                            ->whereIn('id', $expIds)
                            ->update([
                                'is_deducted' => true,
                                'payroll_slip_id' => $slip->id,
                            ]);
                    }
                }

                // Re-create advance deductions
                $activeAdvances = $allAdvances->get($employee->id, collect());
                foreach ($activeAdvances as $advance) {
                    $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
                    if ($installment <= 0) {
                        continue;
                    }

                    AdvanceDeduction::create([
                        'salary_advance_id' => $advance->id,
                        'payroll_slip_id' => $slip->id,
                        'amount' => $installment,
                        'deduction_date' => $endDate,
                        'company_id' => $advance->company_id,
                    ]);

                    $advance->paid_installments += 1;
                    $advance->remaining_balance = max(0, (float) $advance->remaining_balance - $installment);
                    if ($advance->remaining_balance <= 0) {
                        $advance->status = 'completed';
                    }
                    $advance->saveQuietly();
                }

                $totalOfficial += $netBank;
                $totalActual += $netActual;
            }

            PayrollSlip::where('payroll_run_id', $run->id)->whereNotIn('employee_id', $employeeIds)->delete();

            $run->update([
                'total_official' => $totalOfficial,
                'total_actual' => $totalActual,
                'total_cash_diff' => $totalActual - $totalOfficial,
            ]);

        } catch (\Throwable $e) {
            \Log::error('Recalculate Run failed: '.$e->getMessage());
        } finally {
            optional($lock)->release();
        }
    }

    public static function recalculateEmployeeCommissions($employeeId, $yearOrContract, $monthOrYear = null, $preFetchedLogsOrMonth = null, $extraLogs = null)
    {
        if ($employeeId instanceof Employee) {
            $employee = $employeeId;
            $employeeId = $employee->id;
        } else {
            $employee = Employee::find($employeeId);
        }

        if (! $employee) {
            return collect();
        }

        if ($yearOrContract instanceof Contract || (is_object($yearOrContract) && isset($yearOrContract->id))) {
            // Called with ($employee, $contract, $year, $month, $logs)
            $year = (int) $monthOrYear;
            $month = (int) $preFetchedLogsOrMonth;
            $preFetchedLogs = $extraLogs;
        } else {
            // Called with ($employee, $year, $month, $logs)
            $year = (int) $yearOrContract;
            $month = (int) $monthOrYear;
            $preFetchedLogs = $preFetchedLogsOrMonth;
        }

        $startDate = "{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01';
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        $logs = $preFetchedLogs ?? DailyLog::where('employee_id', $employeeId)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->orderBy('log_date')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'vehicle_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'zone', 'shift_valid', 'is_valid', 'online_hours', 'created_by']);

        $assignments = ContractAssignment::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->get();

        $contractIds = $logs->pluck('contract_id')->concat($assignments->pluck('contract_id'))->filter()->unique();
        $contracts = Contract::withoutGlobalScopes()->whereIn('id', $contractIds)->get()->keyBy('id');

        $vehicleIds = $logs->pluck('vehicle_id')->filter()->unique();
        $vehicles = \App\Models\Vehicle::withoutGlobalScopes()->whereIn('id', $vehicleIds)->get()->keyBy('id');

        $target = (int) ($employee->target_orders_monthly ?? 0);
        $baseRate = (float) (($target > 0 && $employee->base_commission_rate !== null) ? $employee->base_commission_rate : ($employee->rate_per_order ?? 0.000));
        $premiumRate = (float) ($employee->premium_commission_rate ?? 0.000);

        $runningOrders = 0;

        foreach ($logs as $log) {
            $cOrders = (int) $log->orders_count;
            $logCommission = 0;
            $logDate = $log->log_date instanceof Carbon ? $log->log_date->toDateString() : substr((string)$log->log_date, 0, 10);

            // Check if log has contract or driver has active assignment on log_date
            $contractId = $log->contract_id;
            if (! $contractId) {
                $activeAssign = $assignments->first(function ($a) use ($logDate) {
                    $st = $a->start_date instanceof Carbon ? $a->start_date->toDateString() : substr((string)$a->start_date, 0, 10);
                    $et = $a->end_date ? ($a->end_date instanceof Carbon ? $a->end_date->toDateString() : substr((string)$a->end_date, 0, 10)) : null;
                    return $st <= $logDate && (!$et || $et >= $logDate);
                });
                if ($activeAssign) {
                    $contractId = $activeAssign->contract_id;
                }
            }

            $rate = null;
            if ($contractId) {
                $contractObj = $contracts->get($contractId);
                if (!$contractObj) {
                    $contractObj = Contract::withoutGlobalScopes()->find($contractId);
                }

                $driverPaymentMethod = $contractObj ? $contractObj->driver_payment_method : null;
                $pricingRules = $contractObj ? (is_string($contractObj->driver_pricing_rules) ? json_decode($contractObj->driver_pricing_rules, true) : $contractObj->driver_pricing_rules) : [];

                $vehicleTypeId = null;
                if ($log->vehicle_id && isset($vehicles[$log->vehicle_id])) {
                    $vehicleTypeId = $vehicles[$log->vehicle_id]->vehicle_type_id;
                }
                if (!$vehicleTypeId && $employee->vehicle_type_id) {
                    $vehicleTypeId = $employee->vehicle_type_id;
                }

                if (is_array($pricingRules)) {
                    if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                        $vtRules = $pricingRules[$vehicleTypeId];
                        if (isset($vtRules['payment_method'])) {
                            $driverPaymentMethod = $vtRules['payment_method'];
                        }
                        $pricingRules = $vtRules;
                    } else {
                        $firstKey = array_key_first($pricingRules);
                        if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey])) {
                            if (isset($pricingRules[$firstKey]['payment_method'])) {
                                $driverPaymentMethod = $pricingRules[$firstKey]['payment_method'];
                            }
                            $pricingRules = $pricingRules[$firstKey];
                        }
                    }
                }

                if (!$driverPaymentMethod) {
                    $driverPaymentMethod = $contractObj ? $contractObj->payment_type : 'per_order';
                }

                if ($driverPaymentMethod === 'zones' || $driverPaymentMethod === 'zones_tiers') {
                    $zoneRules = is_array($pricingRules) && isset($pricingRules['zones']) ? $pricingRules['zones'] : (is_array($pricingRules) && isset($pricingRules['zones_tiers']) ? $pricingRules['zones_tiers'] : $pricingRules);
                    $logComm = 0.0;
                    $notesData = $log->notes ? json_decode($log->notes, true) : null;
                    $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders']))
                        ? $notesData['zone_orders']
                        : [];

                    if (!empty($zoneOrdersMap)) {
                        foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                            $zCount = (int)$zCount;
                            if ($zCount <= 0) continue;
                            $zRate = 0.0;
                            if (is_array($zoneRules)) {
                                foreach ($zoneRules as $rule) {
                                    if (is_array($rule) && (
                                        (isset($rule['id']) && (string)$rule['id'] === (string)$zIdOrName) ||
                                        (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                                        (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                                    )) {
                                        $zRate = (float)($rule['price'] ?? $rule['rate'] ?? 0.0);
                                        break;
                                    }
                                }
                            }
                            $logComm += $zCount * $zRate;
                        }
                    } else {
                        $zoneName = $log->zone;
                        $zRate = 0.0;
                        if (is_array($zoneRules)) {
                            foreach ($zoneRules as $rule) {
                                if (is_array($rule) && (
                                    (isset($rule['id']) && (string)$rule['id'] === (string)$zoneName) ||
                                    (isset($rule['name']) && $rule['name'] === $zoneName) ||
                                    (isset($rule['zone']) && $rule['zone'] === $zoneName)
                                )) {
                                    $zRate = (float)($rule['price'] ?? $rule['rate'] ?? 0.0);
                                    break;
                                }
                            }
                        }
                        $logComm = $cOrders * $zRate;
                    }
                    $rate = ($cOrders > 0) ? ($logComm / $cOrders) : 0.0;
                } else {
                    $activeAssignForContract = $assignments->first(function ($a) use ($contractId, $logDate) {
                        $st = $a->start_date instanceof Carbon ? $a->start_date->toDateString() : substr((string)$a->start_date, 0, 10);
                        $et = $a->end_date ? ($a->end_date instanceof Carbon ? $a->end_date->toDateString() : substr((string)$a->end_date, 0, 10)) : null;
                        return $a->contract_id == $contractId && $st <= $logDate && (!$et || $et >= $logDate);
                    });

                    if ($activeAssignForContract) {
                        $rate = SmartValueFallbackService::resolve($employeeId, $contractId, $logDate, 'order_commission');
                    }
                }
            }

            if ($rate !== null) {
                // Convert currency if contract currency is different from KWD
                $contract = Contract::find($contractId);
                if ($contract && $contract->currency && $contract->currency !== 'KWD') {
                    $rateModel = CurrencyExchangeRate::where('company_id', $employee->company_id)
                        ->where('from_currency', $contract->currency)
                        ->where('to_currency', 'KWD')
                        ->where('year', $year)
                        ->where('month', $month)
                        ->first();
                    if ($rateModel) {
                        $rate = (float) $rate * (float) $rateModel->exchange_rate;
                    }
                }

                $logCommission = $cOrders * (float) $rate;
            } else {
                if ($target > 0) {
                    $start = $runningOrders + 1;
                    $end = $runningOrders + $cOrders;

                    if ($end <= $target) {
                        $logCommission = $cOrders * $baseRate;
                    } elseif ($start > $target) {
                        $logCommission = $cOrders * $premiumRate;
                    } else {
                        $baseOrders = $target - $start + 1;
                        $premiumOrders = $end - $target;
                        $logCommission = ($baseOrders * $baseRate) + ($premiumOrders * $premiumRate);
                    }
                } else {
                    $logCommission = $cOrders * $baseRate;
                }
            }

            $newCommission = round($logCommission, 3);
            if ((float) $log->driver_commission !== (float) $newCommission) {
                $log->driver_commission = $newCommission;

                \DB::table('daily_logs')
                    ->where('id', $log->id)
                    ->update(['driver_commission' => $newCommission]);
            }

            $runningOrders += $cOrders;
        }

        return $logs;
    }

    public static function getVehicleType($vehicle)
    {
        if (! $vehicle) {
            return 'car';
        }
        $text = strtolower($vehicle->make.' '.$vehicle->model);
        if (str_contains($text, 'bike') || str_contains($text, 'motorcycle') || str_contains($text, 'scooter') || str_contains($text, 'دراجة')) {
            return 'bike';
        }

        return 'car';
    }

    public static function calculateDriverSlipData(
        Employee $employee,
        int $year,
        int $month,
        string $startDate,
        string $endDate,
        $allDailyLogs,
        $violationSums,
        $maintenanceSums,
        $custodySums,
        $leaveData,
        $allAdvances,
        $allAssignments,
        $existingSlip = null,
        $driverExpenseSums = null
    ): array {
        $employeeId = $employee->id;
        $empLogs = $allDailyLogs->get($employeeId, collect());

        // 1. Recalculate daily log commissions
        $empLogs = self::recalculateEmployeeCommissions($employee, $year, $month, $empLogs);

        $daysInMonth = Carbon::parse($startDate)->daysInMonth;

        // Fetch contract assignments for this employee
        $empContractAssignments = null;
        if ($allAssignments instanceof \Illuminate\Support\Collection) {
            $first = $allAssignments->first();
            if ($first instanceof ContractAssignment) {
                $empContractAssignments = $allAssignments->where('employee_id', $employeeId);
            } else {
                $empContractAssignments = $allAssignments->get($employeeId);
            }
        }

        if (!$empContractAssignments || ($empContractAssignments instanceof \Illuminate\Support\Collection && $empContractAssignments->isEmpty())) {
            $empContractAssignments = ContractAssignment::withoutGlobalScopes()
                ->where('employee_id', $employeeId)
                ->where('start_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('end_date')
                        ->orWhere('end_date', '>=', $startDate);
                })
                ->with('contract')
                ->get();
        }

        if (!($empContractAssignments instanceof \Illuminate\Support\Collection)) {
            $empContractAssignments = collect($empContractAssignments ? [$empContractAssignments] : []);
        }

        $singleLogContractId = null;
        if ($empContractAssignments->isEmpty()) {
            $uniqueLogContracts = $empLogs->pluck('contract_id')->filter()->unique();
            if ($uniqueLogContracts->count() === 1) {
                $singleLogContractId = $uniqueLogContracts->first();
            }
        }

        // Fetch vehicle assignments active in this month
        $empVehicleAssignments = VehicleAssignment::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('assigned_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('unassigned_date')
                    ->orWhere('unassigned_date', '>=', $startDate);
            })
            ->with('vehicle')
            ->get();

        // Pre-fetch vehicles in bulk to eliminate N+1 queries inside 31-day loop
        $logVehicleIds = $empLogs->pluck('vehicle_id')->filter();
        $assignVehicleIds = $empVehicleAssignments->map(function ($va) {
            return is_array($va) ? ($va['vehicle_id'] ?? null) : ($va->vehicle_id ?? null);
        })->filter();
        $allVehIds = $logVehicleIds->concat($assignVehicleIds)->unique();
        $vehiclesMap = $allVehIds->isNotEmpty() 
            ? \App\Models\Vehicle::withoutGlobalScopes()->whereIn('id', $allVehIds)->get()->keyBy('id')
            : collect();

        // Map each day of the month to its active contract and vehicle type
        $dayMap = [];
        $hasAnyContractAssignment = false;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);

            if ($employee->date_of_joining) {
                $joiningDateStr = $employee->date_of_joining instanceof Carbon
                    ? $employee->date_of_joining->toDateString()
                    : substr($employee->date_of_joining, 0, 10);
                if ($date < $joiningDateStr) {
                    continue;
                }
            }

            // Find contract assignment active on this day
            $activeContractAssign = $empContractAssignments->first(function ($a) use ($date) {
                $rawStart = is_array($a) ? ($a['start_date'] ?? $a['assigned_date'] ?? null) : ($a->start_date ?? $a->assigned_date ?? null);
                $rawEnd = is_array($a) ? ($a['end_date'] ?? $a['unassigned_date'] ?? null) : ($a->end_date ?? $a->unassigned_date ?? null);
                if (!$rawStart) return false;

                $sDate = $rawStart instanceof Carbon ? $rawStart->toDateString() : substr((string)$rawStart, 0, 10);
                $eDate = $rawEnd ? ($rawEnd instanceof Carbon ? $rawEnd->toDateString() : substr((string)$rawEnd, 0, 10)) : null;

                return $sDate <= $date
                    && ($eDate === null || $eDate >= $date);
            });

            // Find vehicle assignment active on this day
            $activeVehicleAssign = $empVehicleAssignments->first(function ($va) use ($date) {
                $rawStart = is_array($va) ? ($va['assigned_date'] ?? $va['start_date'] ?? null) : ($va->assigned_date ?? $va->start_date ?? null);
                $rawEnd = is_array($va) ? ($va['unassigned_date'] ?? $va['end_date'] ?? null) : ($va->unassigned_date ?? $va->end_date ?? null);
                if (!$rawStart) return false;

                $sDate = $rawStart instanceof Carbon ? $rawStart->toDateString() : substr((string)$rawStart, 0, 10);
                $eDate = $rawEnd ? ($rawEnd instanceof Carbon ? $rawEnd->toDateString() : substr((string)$rawEnd, 0, 10)) : null;

                return $sDate <= $date
                    && ($eDate === null || $eDate >= $date);
            });

            $dayLog = $empLogs->firstWhere('log_date', $date);

            $contractIdVal = ($dayLog && $dayLog->contract_id) 
                ? $dayLog->contract_id 
                : ($activeContractAssign ? $activeContractAssign->contract_id : null);

            // Find vehicle type id (in-memory lookup)
            $vehicleTypeIdVal = null;
            if ($dayLog && $dayLog->vehicle_id && isset($vehiclesMap[$dayLog->vehicle_id])) {
                $vehicleTypeIdVal = $vehiclesMap[$dayLog->vehicle_id]->vehicle_type_id;
            }
            if (!$vehicleTypeIdVal && $activeVehicleAssign) {
                $vId = is_array($activeVehicleAssign) ? ($activeVehicleAssign['vehicle_id'] ?? null) : ($activeVehicleAssign->vehicle_id ?? null);
                if ($vId && isset($vehiclesMap[$vId])) {
                    $vehicleTypeIdVal = $vehiclesMap[$vId]->vehicle_type_id;
                }
            }
            if (!$vehicleTypeIdVal && $employee->vehicle_type_id) {
                $vehicleTypeIdVal = $employee->vehicle_type_id;
            }

            $dayMap[$date] = [
                'contract_id' => $contractIdVal,
                'contract_assignment' => $activeContractAssign,
                'vehicle_type_id' => $vehicleTypeIdVal,
            ];
        }

        $hasAnyContractAssignment = $empContractAssignments->isNotEmpty();

        // Group consecutive days into segments
        $segments = [];
        $currentSegment = null;

        foreach ($dayMap as $date => $info) {
            if ($currentSegment === null) {
                $currentSegment = [
                    'contract_id' => $info['contract_id'],
                    'contract_assignment' => $info['contract_assignment'],
                    'vehicle_type_id' => $info['vehicle_type_id'],
                    'start_date' => $date,
                    'end_date' => $date,
                    'days' => 1,
                ];
            } else {
                if ($currentSegment['contract_id'] === $info['contract_id']
                    && $currentSegment['vehicle_type_id'] === $info['vehicle_type_id']) {
                    $currentSegment['end_date'] = $date;
                    $currentSegment['days']++;
                } else {
                    $segments[] = $currentSegment;
                    $currentSegment = [
                        'contract_id' => $info['contract_id'],
                        'contract_assignment' => $info['contract_assignment'],
                        'vehicle_type_id' => $info['vehicle_type_id'],
                        'start_date' => $date,
                        'end_date' => $date,
                        'days' => 1,
                    ];
                }
            }
        }
        if ($currentSegment !== null) {
            $segments[] = $currentSegment;
        }

        if (empty($dayMap)) {
            $segments = [];
        } elseif (! $hasAnyContractAssignment) {
            $segments = [[
                'contract_id' => null,
                'contract_assignment' => null,
                'vehicle_type_id' => null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $daysInMonth,
            ]];
        }

        // Get primary assignment info for overall slip info
        $primarySegment = null;
        foreach ($segments as $seg) {
            if ($seg['contract_id'] !== null && $primarySegment === null) {
                $primarySegment = $seg;
            }
        }
        if ($primarySegment === null || $primarySegment['contract_id'] === null) {
            $firstAssign = $empContractAssignments->first();
            $fallbackId = is_object($firstAssign) ? ($firstAssign->contract_id ?? null) : null;
            if (! $fallbackId) {
                $fallbackId = $empLogs->pluck('contract_id')->filter()->first();
            }
            if (is_object($fallbackId)) {
                $fallbackId = $fallbackId->id ?? null;
            }
            $fallbackContractId = $fallbackId ? (int) $fallbackId : null;

            if ($primarySegment === null) {
                $primarySegment = $segments[0] ?? [
                    'contract_id' => $fallbackContractId,
                    'contract_assignment' => null,
                    'vehicle_type_id' => null,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days' => $daysInMonth,
                ];
            } else {
                $primarySegment['contract_id'] = $fallbackContractId;
            }
        }

        $contractId = $primarySegment['contract_id'] ?? null;
        $contractAssignment = $primarySegment['contract_assignment'] ?? null;
        $contract = null;
        if (is_object($contractAssignment) && isset($contractAssignment->contract)) {
            $contract = $contractAssignment->contract;
        }
        if (!$contract && $contractId) {
            $contract = Contract::withoutGlobalScopes()->find($contractId);
        }
        $paymentType = $employee->pay_type;
        if ($contract) {
            $paymentType = $contract->payment_type;
        }

        $exchangeRate = 1.0;
        if ($contract && $contract->currency && $contract->currency !== 'KWD') {
            $rateModel = CurrencyExchangeRate::where('company_id', $employee->company_id)
                ->where('from_currency', $contract->currency)
                ->where('to_currency', 'KWD')
                ->where('year', $year)
                ->where('month', $month)
                ->first();
            if ($rateModel) {
                $exchangeRate = (float) $rateModel->exchange_rate;
            }
        }

        $totalOrders = 0;
        $ordersBonus = 0.0;
        $baseActual = 0.0;
        $absenceDeduction = 0.0;
        $totalMonthlyTarget = 0;
        $totalRequiredValidDays = 0;

        foreach ($segments as $segment) {
            $segContractId = $segment['contract_id'];
            $segRatio = $segment['days'] / $daysInMonth;
            $segLogs = $empLogs->whereBetween('log_date', [$segment['start_date'], $segment['end_date']]);
            $segOrders = $segLogs->sum('orders_count');
            $totalOrders += $segOrders;

            if ($segContractId === null) {
                // Legacy segment
                $segCommissions = 0;
                $hasRecalculated = false;
                foreach ($segLogs as $l) {
                    if ($l->contract_id && ! $hasRecalculated) {
                        $lContract = Contract::find($l->contract_id);
                        if ($lContract) {
                            $recalc = self::recalculateEmployeeCommissions($employee, $year, $month, $segLogs);
                            $segCommissions = is_array($recalc) ? ($recalc['orders_bonus'] ?? 0) : (float) $recalc->sum('driver_commission');
                            $hasRecalculated = true;
                        }
                    }
                }
                if (! $hasRecalculated) {
                    $segCommissions = $segLogs->sum('driver_commission');
                }

                if ($employee->pay_type === 'per_order') {
                    $baseActual += $segCommissions;
                } else {
                    $baseActual += ((float) $employee->actual_salary) * $segRatio;
                    if ($employee->pay_type === 'hybrid') {
                        $ordersBonus += $segCommissions;
                    }
                }

                continue;
            }

            $segContract = Contract::withoutGlobalScopes()->find($segContractId);
            if (! $segContract) {
                continue;
            }

            $segExchangeRate = 1.0;
            if ($segContract->currency && $segContract->currency !== 'KWD') {
                $rateModel = CurrencyExchangeRate::where('company_id', $employee->company_id)
                    ->where('from_currency', $segContract->currency)
                    ->where('to_currency', 'KWD')
                    ->where('year', $year)
                    ->where('month', $month)
                    ->first();
                if ($rateModel) {
                    $segExchangeRate = (float) $rateModel->exchange_rate;
                }
            }

            $activeOverride = null;
            $assignObj = $segment['contract_assignment'] ?? null;
            $assignId = is_array($assignObj) ? ($assignObj['id'] ?? null) : ($assignObj->id ?? null);
            if ($assignId) {
                $activeOverride = DriverContractOverride::where('contract_assignment_id', $assignId)
                    ->whereDate('effective_from', '<=', $segment['end_date'])
                    ->where(function ($q) use ($segment) {
                        $q->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', $segment['start_date']);
                    })
                    ->first();
            }

            $vehicleTypeId = $segment['vehicle_type_id'] ?? null;
            $segPaymentType = $segContract->payment_type;
            $driverPaymentMethod = $segContract->driver_payment_method;

            if ($activeOverride && $activeOverride->override_type !== null) {
                $driverPaymentMethod = $activeOverride->override_type;
            } elseif ($vehicleTypeId !== null && is_array($segContract->driver_pricing_rules) && isset($segContract->driver_pricing_rules[$vehicleTypeId]['payment_method'])) {
                $driverPaymentMethod = $segContract->driver_pricing_rules[$vehicleTypeId]['payment_method'];
            } elseif (!$driverPaymentMethod && is_array($segContract->driver_pricing_rules)) {
                $firstKey = array_key_first($segContract->driver_pricing_rules);
                if ($firstKey !== null && isset($segContract->driver_pricing_rules[$firstKey]['payment_method'])) {
                    $driverPaymentMethod = $segContract->driver_pricing_rules[$firstKey]['payment_method'];
                }
            }

            if (!$driverPaymentMethod) {
                $driverPaymentMethod = $segPaymentType ?? 'per_order';
            }

            // Daily log commissions are already calculated in step 1
            $segLogsRecalculated = $segLogs;

            // Resolve fixed salary and absence divisor
            $fixedSalary = SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'fixed_salary');
            if ($fixedSalary === null) {
                $fixedSalary = (float) $employee->actual_salary;
            } else {
                $fixedSalary = (float) $fixedSalary * $segExchangeRate;
            }
            $proratedFixedSalary = $fixedSalary * $segRatio;

            $divisor = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'absence_divisor') ?? 26);

            $requiredWorkDays = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'required_work_days') ?? 26);
            $proratedRequiredWorkDays = (int) round($requiredWorkDays * $segRatio);

            $requiredValidDays = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'valid_days') ?? 26);
            $proratedRequiredValidDays = (int) round($requiredValidDays * $segRatio);

            $monthlyTarget = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'monthly_target') ?? 0);
            $proratedMonthlyTarget = (int) round($monthlyTarget * $segRatio);

            $totalMonthlyTarget += $proratedMonthlyTarget;
            $totalRequiredValidDays += $proratedRequiredValidDays;

            // Determine if there is a flat commission override for this driver
            $flatCommissionRate = SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'order_commission');
            if ($flatCommissionRate !== null) {
                $flatCommissionRate = (float) $flatCommissionRate * $segExchangeRate;
            }

            $segOrdersBonus = 0.0;
            $segBaseActual = 0.0;
            $segAbsenceDeduction = 0.0;

            if ($flatCommissionRate !== null && in_array($driverPaymentMethod, ['fixed', 'hybrid', 'per_order'])) {
                // Flat rate override
                $segOrdersBonus = $segOrders * $flatCommissionRate;
                if ($driverPaymentMethod === 'fixed') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $baseSalary = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);

                    // Deficit/Surplus Calculation
                    $deficitDeduction = 0.0;
                    $surplusBonusAmt = 0.0;
                    if ($proratedMonthlyTarget > 0) {
                        $deficitRate = $flatCommissionRate;
                        if ($segOrders < $proratedMonthlyTarget) {
                            $deficitDeduction = ($proratedMonthlyTarget - $segOrders) * $deficitRate;
                        } else {
                            $surplusBonus = (float) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'custom_monthly_bonus') ?? 0.0);
                            $surplusRate = (float) ($employee->premium_commission_rate ?? $segContract->premium_commission_rate ?? $deficitRate);
                            if ($surplusBonus > 0) {
                                $surplusBonusAmt = $surplusBonus;
                            } else {
                                $surplusBonusAmt = ($segOrders - $proratedMonthlyTarget) * $surplusRate;
                            }
                        }
                    }
                    $segBaseActual = $baseSalary - $deficitDeduction + $surplusBonusAmt;
                    $segOrdersBonus = 0.0;
                } elseif ($driverPaymentMethod === 'hybrid') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $segBaseActual = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);
                } elseif ($driverPaymentMethod === 'per_order') {
                    $segBaseActual = 0.0;
                    // segOrdersBonus is already set to $segOrders * $flatCommissionRate
                }
            } else {
                if ($driverPaymentMethod === 'fixed') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $baseSalary = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);

                    // Deficit/Surplus Calculation
                    $deficitDeduction = 0.0;
                    $surplusBonusAmt = 0.0;
                    if ($proratedMonthlyTarget > 0) {
                        $deficitRate = (float) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'order_commission') ?? 0.0);
                        if ($segOrders < $proratedMonthlyTarget) {
                            $deficitDeduction = ($proratedMonthlyTarget - $segOrders) * $deficitRate;
                        } else {
                            $surplusBonus = (float) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'custom_monthly_bonus') ?? 0.0);
                            $surplusRate = (float) ($employee->premium_commission_rate ?? $segContract->premium_commission_rate ?? $deficitRate);
                            if ($surplusBonus > 0) {
                                $surplusBonusAmt = $surplusBonus;
                            } else {
                                $surplusBonusAmt = ($segOrders - $proratedMonthlyTarget) * $surplusRate;
                            }
                        }
                    }
                    $segBaseActual = $baseSalary - $deficitDeduction + $surplusBonusAmt;
                } elseif ($driverPaymentMethod === 'per_order') {
                    $segOrdersBonus = $segLogsRecalculated->sum('driver_commission');
                    $segBaseActual = 0.0;
                } elseif ($driverPaymentMethod === 'hourly') {
                    $hourlyRate = SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'hourly_rate');
                    if ($hourlyRate === null) {
                        $hourlyRate = 0.0;
                    } else {
                        $hourlyRate = (float) $hourlyRate * $segExchangeRate;
                    }
                    $totalHours = (float) $segLogs->sum('online_hours');
                    $segBaseActual = $totalHours * $hourlyRate;
                } elseif ($driverPaymentMethod === 'hybrid') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $segBaseActual = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);
                    $segOrdersBonus = $segLogsRecalculated->sum('driver_commission');
                } elseif ($driverPaymentMethod === 'zones') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if (is_array($pricingRules)) {
                            if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                                $pricingRules = $pricingRules[$vehicleTypeId];
                            } else {
                                $firstKey = array_key_first($pricingRules);
                                if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey]) && (isset($pricingRules[$firstKey]['payment_method']) || isset($pricingRules[$firstKey]['vehicle_type_id']))) {
                                    $pricingRules = $pricingRules[$firstKey];
                                }
                            }
                        }
                    }
                    if (is_array($pricingRules) && isset($pricingRules['zones'])) {
                        $pricingRules = $pricingRules['zones'];
                    }

                    $payout = 0.0;
                    foreach ($segLogs as $l) {
                        $cOrders = (int) $l->orders_count;
                        if ($cOrders <= 0) continue;

                        $notesData = $l->notes ? json_decode($l->notes, true) : null;
                        $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders']))
                            ? $notesData['zone_orders']
                            : [];

                        if (!empty($zoneOrdersMap)) {
                            foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                                $zCount = (int)$zCount;
                                if ($zCount <= 0) continue;
                                $zRate = 0.0;
                                if (is_array($pricingRules)) {
                                    foreach ($pricingRules as $rule) {
                                        if (is_array($rule) && (
                                            (isset($rule['id']) && (string)$rule['id'] === (string)$zIdOrName) ||
                                            (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                                            (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                                        )) {
                                            $zRate = (float)($rule['price'] ?? $rule['rate'] ?? 0.0);
                                            break;
                                        }
                                    }
                                }
                                $payout += $zCount * $zRate * $segExchangeRate;
                            }
                        } else {
                            $zoneName = $l->zone;
                            $zRate = 0.0;
                            if (is_array($pricingRules)) {
                                foreach ($pricingRules as $rule) {
                                    if (is_array($rule) && (
                                        (isset($rule['id']) && (string)$rule['id'] === (string)$zoneName) ||
                                        (isset($rule['name']) && $rule['name'] === $zoneName) ||
                                        (isset($rule['zone']) && $rule['zone'] === $zoneName)
                                    )) {
                                        $zRate = (float)($rule['price'] ?? $rule['rate'] ?? 0.0);
                                        break;
                                    }
                                }
                            }
                            $payout += $cOrders * $zRate * $segExchangeRate;
                        }
                    }

                    $segOrdersBonus = round($payout, 3);
                    $segBaseActual = 0.0;
                } elseif ($driverPaymentMethod === 'zones_tiers') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if (is_array($pricingRules)) {
                            if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                                $pricingRules = $pricingRules[$vehicleTypeId];
                            } else {
                                $firstKey = array_key_first($pricingRules);
                                if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey]) && (isset($pricingRules[$firstKey]['payment_method']) || isset($pricingRules[$firstKey]['vehicle_type_id']))) {
                                    $pricingRules = $pricingRules[$firstKey];
                                }
                            }
                        }
                    }
                    if (is_array($pricingRules) && isset($pricingRules['zones_tiers'])) {
                        $pricingRules = $pricingRules['zones_tiers'];
                    }

                    $payout = 0.0;
                    $groupedLogs = $segLogs->groupBy('zone');
                    foreach ($groupedLogs as $zoneName => $zoneLogs) {
                        $zoneOrders = $zoneLogs->sum('orders_count');
                        $zoneTiers = null;
                        if (is_array($pricingRules)) {
                            if (isset($pricingRules[$zoneName]) && is_array($pricingRules[$zoneName])) {
                                $zoneTiers = $pricingRules[$zoneName];
                            } else {
                                foreach ($pricingRules as $rule) {
                                    if (is_array($rule) && (isset($rule['zone']) || isset($rule['name'])) && ($rule['zone'] ?? $rule['name']) == $zoneName) {
                                        $zoneTiers = $rule['tiers'] ?? null;
                                        break;
                                    }
                                }
                            }
                        }

                        $selectedPrice = 0.0;
                        if (is_array($zoneTiers)) {
                            foreach ($zoneTiers as $tier) {
                                $min = (int) round(($tier['min_orders'] ?? $tier['min'] ?? 0) * $segRatio);
                                $price = (float) ($tier['price'] ?? $tier['rate'] ?? $tier['bonus'] ?? 0.0);
                                if ($zoneOrders >= $min) {
                                    $selectedPrice = $price;
                                }
                            }
                        }

                        if ($selectedPrice === 0.0) {
                            $selectedPrice = (float) ($segContract->default_order_commission ?? 0.0);
                        }
                        $payout += $zoneOrders * $selectedPrice * $segExchangeRate;
                    }
                    $segOrdersBonus = $payout;
                    $segBaseActual = 0.0;
                } elseif ($driverPaymentMethod === 'tiers') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if (is_array($pricingRules)) {
                            if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                                $pricingRules = $pricingRules[$vehicleTypeId];
                            } else {
                                $firstKey = array_key_first($pricingRules);
                                if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey]) && (isset($pricingRules[$firstKey]['payment_method']) || isset($pricingRules[$firstKey]['vehicle_type_id']))) {
                                    $pricingRules = $pricingRules[$firstKey];
                                }
                            }
                        }
                    }
                    if (is_array($pricingRules) && isset($pricingRules['tiers'])) {
                        $pricingRules = $pricingRules['tiers'];
                    }

                    $selectedPrice = 0.0;
                    if (is_array($pricingRules)) {
                        foreach ($pricingRules as $tier) {
                            $min = (int) round(($tier['min_orders'] ?? $tier['min'] ?? 0) * $segRatio);
                            $price = (float) ($tier['price'] ?? $tier['rate'] ?? $tier['bonus'] ?? 0.0);
                            if ($segOrders >= $min) {
                                $selectedPrice = $price;
                            }
                        }
                    }
                    if ($selectedPrice === 0.0) {
                        $selectedPrice = (float) ($segContract->default_order_commission ?? 0.0);
                    }
                    $segOrdersBonus = $segOrders * $selectedPrice * $segExchangeRate;
                    $segBaseActual = 0.0;
                }
            }

            $baseActual += $segBaseActual;
            $ordersBonus += $segOrdersBonus;
            $absenceDeduction += $segAbsenceDeduction;
        }

        // 4. Calculate Auto-Validity (Final Monthly Status)
        $status = 'Valid';
        if ($contractId && $contract) {
            $workedValidDays = $empLogs->where('shift_valid', 1)->count();

            $meetsOrdersTarget = ($totalOrders >= $totalMonthlyTarget);

            if ($contract->is_validity_enabled) {
                $daysInMonthVal = Carbon::parse($startDate)->daysInMonth;
                $validAttendanceDays = $empLogs->where('is_valid', true)->count();
                $attendanceRate = $daysInMonthVal > 4 ? ($validAttendanceDays / ($daysInMonthVal - 4)) : 1.0;
                $attendanceRate = min(1.0, $attendanceRate);
                $meetsValidDays = ($attendanceRate >= 0.90);
            } else {
                $meetsValidDays = ($workedValidDays >= $totalRequiredValidDays);
            }

            // Check mandatory periods
            $meetsMandatoryPeriods = true;
            $monthlyParam = ContractMonthlyParameter::where('contract_id', $contractId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if ($monthlyParam) {
                $mandatoryPeriods = ContractMandatoryDay::where('contract_monthly_parameter_id', $monthlyParam->id)->get();
                foreach ($mandatoryPeriods as $period) {
                    $periodStart = Carbon::parse($period->start_date)->toDateString();
                    $periodEnd = Carbon::parse($period->end_date)->toDateString();
                    $periodLogsCount = $empLogs->whereBetween('log_date', [$periodStart, $periodEnd])
                        ->where('shift_valid', 1)
                        ->count();
                    if ($periodLogsCount < $period->min_required_days) {
                        $meetsMandatoryPeriods = false;
                        break;
                    }
                }
            }

            if (! $meetsOrdersTarget || ! $meetsValidDays || ! $meetsMandatoryPeriods) {
                $status = 'Invalid';
            }
        }

        if ($existingSlip && $existingSlip->final_monthly_status === 'protected') {
            $status = 'Protected';
        }

        // 5. Calculate Capacity and Experience Incentives
        $totalCapacityIncentive = 0.0;
        $totalExperienceIncentive = 0.0;

        $attendanceRate = 0.0;
        $isValidDA = false;
        $shouldCalculateIncentives = false;

        if ($contractId && $contract) {
            if ($contract->is_validity_enabled) {
                $daysInMonth = Carbon::parse($startDate)->daysInMonth;
                $validAttendanceDays = $empLogs->where('is_valid', true)->count();
                $attendanceRate = $daysInMonth > 4 ? ($validAttendanceDays / ($daysInMonth - 4)) : 1.0;
                $attendanceRate = min(1.0, $attendanceRate);
                $isValidDA = ($attendanceRate >= 0.90);
                $shouldCalculateIncentives = $isValidDA;
            } else {
                $shouldCalculateIncentives = ($status !== 'Invalid');
            }
        }

        if ($shouldCalculateIncentives) {
            $monthlyParam = ContractMonthlyParameter::where('contract_id', $contractId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if ($monthlyParam) {
                // Capacity Incentive
                if ($monthlyParam->capacity_incentive_rules) {
                    $rules = is_string($monthlyParam->capacity_incentive_rules)
                        ? json_decode($monthlyParam->capacity_incentive_rules, true)
                        : $monthlyParam->capacity_incentive_rules;
                    if (is_array($rules)) {
                        foreach ($rules as $tier) {
                            $min = $tier['min_orders'] ?? 0;
                            $max = $tier['max_orders'] ?? INF;
                            $bonus = (float) ($tier['bonus'] ?? 0);
                            if ($totalOrders >= $min && $totalOrders <= $max) {
                                $totalCapacityIncentive = $bonus * $exchangeRate;
                            }
                        }
                    }
                }

                // Experience Incentive
                if ($monthlyParam->experience_incentive_rules) {
                    $rules = is_string($monthlyParam->experience_incentive_rules)
                        ? json_decode($monthlyParam->experience_incentive_rules, true)
                        : $monthlyParam->experience_incentive_rules;
                    $monthsTenure = Carbon::parse($employee->date_of_joining)->diffInMonths(Carbon::parse($startDate));
                    if (is_array($rules)) {
                        foreach ($rules as $tier) {
                            $minMonths = $tier['min_months'] ?? 0;
                            $bonus = (float) ($tier['bonus'] ?? 0);
                            $bonusPerOrder = (float) ($tier['bonus_per_order'] ?? 0);
                            if ($monthsTenure >= $minMonths) {
                                $factor = ($contract && $contract->is_validity_enabled) ? $attendanceRate : 1.0;
                                $totalExperienceIncentive = ($bonus + ($bonusPerOrder * $totalOrders)) * $exchangeRate * $factor;
                            }
                        }
                    }
                }
            }
        }

        // 6. Calculate Contract Bonuses
        $totalContractBonuses = 0.0;
        if ($contractId) {
            $bonuses = \DB::table('contract_bonuses')
                ->where('contract_id', $contractId)
                ->get();
            foreach ($bonuses as $b) {
                if ($b->is_valid_drivers_only && $status === 'Invalid') {
                    continue;
                }
                $totalContractBonuses += (float) $b->amount * $exchangeRate;
            }
        }

        // 7. Deductions and Allowances
        $violationsDeduction = (float) ($violationSums[$employeeId] ?? 0);
        $maintenanceDeduction = (float) ($maintenanceSums[$employeeId] ?? 0);
        $custodyDeduction = (float) ($custodySums[$employeeId] ?? 0);
        $driverExpenseDeduction = (float) ($driverExpenseSums[$employeeId] ?? 0);

        $leaveInfo = $leaveData[$employeeId] ?? null;
        $leaveDeduction = (float) ($leaveInfo?->total_deduction ?? 0);
        $unpaidLeaveDays = (int) ($leaveInfo?->total_days ?? 0);

        $advanceDeduction = 0.0;
        $activeAdvances = $allAdvances->get($employeeId, collect());
        foreach ($activeAdvances as $advance) {
            $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
            if ($installment <= 0) {
                continue;
            }
            $advanceDeduction += $installment;
        }

        $fuelAllowance = 0.0;
        $empAssignments = $allAssignments[$employeeId] ?? collect();
        foreach ($empAssignments as $a) {
            $fuelAllowance += (float) ($a->monthly_fuel_allowance ?? 0);
        }

        return [
            'base_actual' => $baseActual,
            'orders_bonus' => $ordersBonus,
            'fuel_allowance' => $fuelAllowance,
            'total_orders' => $totalOrders,
            'violations_deduction' => $violationsDeduction,
            'maintenance_deduction' => $maintenanceDeduction,
            'custody_deduction' => $custodyDeduction,
            'driver_expense_deduction' => $driverExpenseDeduction,
            'advance_deduction' => $advanceDeduction,
            'leave_deduction' => $leaveDeduction,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'final_monthly_status' => $status,
            'total_capacity_incentive' => $totalCapacityIncentive,
            'total_experience_incentive' => $totalExperienceIncentive,
            'total_contract_bonuses' => $totalContractBonuses,
            'base_actual_salary' => $baseActual,
            'total_absence_deduction' => $absenceDeduction,
        ];
    }

    /**
     * GET /api/payroll/contract-sheet/{contract}
     * Contract-Centric Payroll Sheet API
     */
    public function contractSheet(Request $request, $contractId): JsonResponse
    {
        if (!$request->user()->can('contract_payroll.view') && !$request->user()->can('payroll.view') && !$request->user()->can('contracts.view')) {
            return response()->json(['message' => 'غير مصرح لك باستعراض كشف رواتب العقود.'], 403);
        }

        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = Carbon::parse($startDate)->daysInMonth;
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

        $contract = Contract::withoutGlobalScopes()->find($contractId);
        if (!$contract) {
            return response()->json(['message' => 'العقد غير موجود.'], 404);
        }

        // Get contract assignments active during this month
        $assignments = ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contractId)
            ->whereDate('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $startDate);
            })
            ->with(['employee' => function($q){ $q->withoutGlobalScopes(); }, 'overrides'])
            ->get();

        $employeeIds = $assignments->pluck('employee_id')->filter()->unique()->values();

        // Include any extra drivers with daily logs under this contract
        $extraLogDriverIds = DailyLog::withoutGlobalScopes()
            ->where('contract_id', $contractId)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->pluck('employee_id')
            ->filter()
            ->unique()
            ->diff($employeeIds);

        if ($extraLogDriverIds->isNotEmpty()) {
            $extraEmployees = Employee::withoutGlobalScopes()->whereIn('id', $extraLogDriverIds)->get();
            foreach ($extraEmployees as $extraEmp) {
                $dummyAssign = new ContractAssignment([
                    'id' => null,
                    'contract_id' => $contractId,
                    'employee_id' => $extraEmp->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'active'
                ]);
                $dummyAssign->setRelation('employee', $extraEmp);
                $dummyAssign->setRelation('overrides', collect());
                $assignments->push($dummyAssign);
                $employeeIds->push($extraEmp->id);
            }
        }

        // Fetch logs for all drivers in this contract
        $allLogs = DailyLog::withoutGlobalScopes()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->get()
            ->groupBy('employee_id');

        // Fetch global deductions (advances & violations)
        $allAdvances = \App\Models\SalaryAdvance::withoutGlobalScopes()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->get()
            ->groupBy('employee_id');

        $allViolations = \App\Models\Violation::withoutGlobalScopes()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('violation_date', [$startDate, $endDate])
            ->get()
            ->groupBy('employee_id');

        $driversResult = [];
        $totalOrdersSum = 0;
        $totalEarningsSum = 0.0;
        $totalDeductionsSum = 0.0;
        $totalNetSum = 0.0;

        foreach ($assignments as $assignment) {
            $employee = $assignment->employee;
            if (!$employee) continue;
            $empId = $employee->id;

            // Determine effective start and end dates for proration
            $assignStart = $assignment->start_date ? substr((string)$assignment->start_date, 0, 10) : $startDate;
            $assignEnd = $assignment->end_date ? substr((string)$assignment->end_date, 0, 10) : $endDate;

            $effStart = max($startDate, $assignStart);
            $effEnd = min($endDate, $assignEnd);

            $effStartCarbon = Carbon::parse($effStart);
            $effEndCarbon = Carbon::parse($effEnd);
            if ($effStartCarbon->gt($effEndCarbon)) {
                $assignedDays = 0;
            } else {
                $assignedDays = $effStartCarbon->diffInDays($effEndCarbon) + 1;
            }
            $segRatio = $daysInMonth > 0 ? min(1.0, max(0.0, $assignedDays / $daysInMonth)) : 1.0;

            // Driver's logs for this contract
            $empLogs = $allLogs->get($empId, collect())->where('contract_id', $contractId);

            // Active override for this driver
            $activeOverride = $assignment->overrides ? $assignment->overrides->first(function($ov) use ($startDate, $endDate) {
                $ovStart = $ov->effective_from ? substr((string)$ov->effective_from, 0, 10) : $startDate;
                $ovEnd = $ov->effective_to ? substr((string)$ov->effective_to, 0, 10) : null;
                return $ovStart <= $endDate && (!$ovEnd || $ovEnd >= $startDate);
            }) : null;

            // Vehicle type resolution
            $vtId = $employee->vehicle_type_id;
            if (!$vtId) {
                $firstLogWithVeh = $empLogs->firstWhere('vehicle_id', '!=', null);
                if ($firstLogWithVeh) {
                    $veh = \App\Models\Vehicle::withoutGlobalScopes()->find($firstLogWithVeh->vehicle_id);
                    if ($veh) $vtId = $veh->vehicle_type_id;
                }
            }

            // Determine driver payment method
            $driverPaymentMethod = null;
            if ($activeOverride && $activeOverride->override_type) {
                $driverPaymentMethod = $activeOverride->override_type;
            } elseif ($vtId && is_array($contract->driver_pricing_rules) && isset($contract->driver_pricing_rules[$vtId]['payment_method'])) {
                $driverPaymentMethod = $contract->driver_pricing_rules[$vtId]['payment_method'];
            } elseif (is_array($contract->driver_pricing_rules)) {
                $firstKey = array_key_first($contract->driver_pricing_rules);
                if ($firstKey !== null && isset($contract->driver_pricing_rules[$firstKey]['payment_method'])) {
                    $driverPaymentMethod = $contract->driver_pricing_rules[$firstKey]['payment_method'];
                }
            }

            if (!$driverPaymentMethod) {
                $driverPaymentMethod = $contract->driver_payment_method ?: ($contract->payment_type ?: 'per_order');
            }

            // Route to specialized calculation function
            $calcResult = null;
            switch ($driverPaymentMethod) {
                case 'fixed':
                    $calcResult = self::calculateFixedDriverPayroll($employee, $contract, $assignment, $activeOverride, $empLogs, $segRatio, $assignedDays, $vtId);
                    break;
                case 'hybrid':
                    $calcResult = self::calculateHybridDriverPayroll($employee, $contract, $assignment, $activeOverride, $empLogs, $segRatio, $assignedDays, $vtId);
                    break;
                case 'zones':
                    $calcResult = self::calculateZonesDriverPayroll($employee, $contract, $assignment, $activeOverride, $empLogs, $vtId);
                    break;
                case 'zones_tiers':
                    $calcResult = self::calculateZonesTiersDriverPayroll($employee, $contract, $assignment, $activeOverride, $empLogs, $vtId);
                    break;
                case 'tiers':
                    $calcResult = self::calculateTiersDriverPayroll($employee, $contract, $assignment, $activeOverride, $empLogs, $vtId);
                    break;
                case 'per_order':
                default:
                    $calcResult = self::calculatePerOrderDriverPayroll($employee, $contract, $assignment, $activeOverride, $empLogs, $vtId);
                    break;
            }

            // Deductions calculation for contract level (Traffic Violations ONLY - Salary Advances are deducted in main monthly payroll)
            $violSum = (float) $allViolations->get($empId, collect())->sum('driver_deduction_amount');
            $totalContractDeductions = $violSum;

            $grossEarnings = (float) ($calcResult['gross_contract_earnings'] ?? 0.0);
            $netPayout = round($grossEarnings - $totalContractDeductions, 3);

            $actualWorkDays = $empLogs->filter(function($log) {
                return ($log->orders_count > 0) || ($log->cash_collected > 0) || ($log->rejected_orders_count > 0) || ($log->driver_status === 'working');
            })->count();

            $driversResult[] = [
                'employee_id' => $empId,
                'employee_name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'payment_method' => $driverPaymentMethod,
                'payment_method_label' => self::getPaymentMethodLabel($driverPaymentMethod),
                'has_override' => !!$activeOverride,
                'assigned_days' => $assignedDays,
                'actual_work_days' => $actualWorkDays,
                'days_ratio' => round($segRatio, 4),
                'orders_count' => $calcResult['orders_count'] ?? $empLogs->sum('orders_count'),
                'base_salary' => $calcResult['base_salary'] ?? 0.0,
                'orders_bonus' => $calcResult['orders_bonus'] ?? 0.0,
                'deficit_deduction' => $calcResult['deficit_deduction'] ?? 0.0,
                'surplus_bonus' => $calcResult['surplus_bonus'] ?? 0.0,
                'absence_deduction' => $calcResult['absence_deduction'] ?? 0.0,
                'gross_contract_earnings' => $grossEarnings,
                'violations_deduction' => $violSum,
                'global_deductions' => [
                    'advances' => 0.0,
                    'violations' => $violSum,
                    'total' => $totalContractDeductions
                ],
                'net_payout' => $netPayout,
                'calculation_details' => $calcResult['calculation_details'] ?? []
            ];

            $totalOrdersSum += ($calcResult['orders_count'] ?? $empLogs->sum('orders_count'));
            $totalEarningsSum += $grossEarnings;
            $totalDeductionsSum += $totalContractDeductions;
            $totalNetSum += $netPayout;
        }

        $approvedRun = ContractPayrollRun::with('approvedBy:id,name')
            ->where('company_id', $companyId)
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->first();

        return response()->json([
            'contract' => [
                'id' => $contract->id,
                'name' => $contract->name,
                'contract_number' => $contract->contract_number,
                'currency' => $contract->currency ?: 'KWD',
                'payment_type' => $contract->payment_type,
                'driver_payment_method' => $contract->driver_payment_method
            ],
            'period' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth
            ],
            'is_approved' => !!$approvedRun,
            'approved_run' => $approvedRun,
            'summary' => [
                'total_drivers' => count($driversResult),
                'total_orders' => $totalOrdersSum,
                'total_gross_earnings' => round($totalEarningsSum, 3),
                'total_violations_deductions' => round($totalDeductionsSum, 3),
                'total_global_deductions' => round($totalDeductionsSum, 3),
                'total_net_payout' => round($totalNetSum, 3)
            ],
            'drivers' => $driversResult
        ]);
    }

    /**
     * POST /api/payroll/contract-sheet/{contract}/approve
     * Approve and freeze contract payroll sheet for a month.
     */
    public function approveContractSheet(Request $request, $contractId): JsonResponse
    {
        if (!$request->user()->can('contract_payroll.approve') && !$request->user()->can('contract_payroll.edit') && !$request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك باعتماد كشف رواتب العقد.'], 403);
        }

        $contract = Contract::findOrFail($contractId);
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $notes = $request->input('notes');

        // Run live calculation of contract sheet to create fresh snapshot
        $sheetRes = $this->contractSheet($request, $contract->id);
        $data = json_decode($sheetRes->getContent(), true);

        $summary = $data['summary'] ?? [];
        $drivers = $data['drivers'] ?? [];

        $run = ContractPayrollRun::updateOrCreate(
            [
                'company_id'  => $companyId,
                'contract_id' => $contract->id,
                'year'        => $year,
                'month'       => $month,
            ],
            [
                'status'                      => 'approved',
                'total_drivers'               => count($drivers),
                'total_orders'                => (int) ($summary['total_orders'] ?? 0),
                'total_gross_earnings'        => (float) ($summary['total_gross_earnings'] ?? 0.0),
                'total_violations_deductions' => (float) ($summary['total_violations_deductions'] ?? $summary['total_global_deductions'] ?? 0.0),
                'total_net_payout'            => (float) ($summary['total_net_payout'] ?? 0.0),
                'snapshot_data'               => $data,
                'approved_by'                 => $request->user()?->id,
                'approved_at'                 => now(),
                'notes'                       => $notes,
            ]
        );

        return response()->json([
            'message' => "تم اعتماد وتجميد كشف رواتب العقد ({$contract->name}) لشهر {$month}/{$year} بنجاح 🔒",
            'run'     => $run->load('approvedBy:id,name')
        ]);
    }

    /**
     * POST /api/payroll/contract-sheet/{contract}/unapprove
     * Unapprove (un-freeze) contract payroll sheet.
     */
    public function unapproveContractSheet(Request $request, $contractId): JsonResponse
    {
        $contract = Contract::findOrFail($contractId);
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $run = ContractPayrollRun::where('company_id', $companyId)
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($run) {
            $run->delete();
        }

        return response()->json([
            'message' => "تم فك تجميد واعتماد كشف رواتب العقد ({$contract->name}) لشهر {$month}/{$year} بنجاح.",
        ]);
    }

    /**
     * GET /api/payroll/consolidated/{year}/{month}
     * Consolidated Monthly Payroll Sheet based strictly on Approved Contract Payroll Runs.
     */
    public function consolidatedSheet(Request $request, $year, $month): JsonResponse
    {
        $year = (int) $year;
        $month = (int) $month;
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = Carbon::parse($startDate)->daysInMonth;
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // Fetch all contracts for company
        $allContracts = Contract::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'name', 'contract_number']);

        // Fetch approved contract runs for this month
        $approvedRuns = ContractPayrollRun::with(['contract:id,name,contract_number', 'approvedBy:id,name'])
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->get();

        $approvedContractIds = $approvedRuns->pluck('contract_id')->toArray();

        // Separate contracts into approved vs unapproved
        $unapprovedContracts = $allContracts->filter(function ($c) use ($approvedContractIds) {
            return !in_array($c->id, $approvedContractIds);
        })->values();

        // Consolidated drivers mapping
        $consolidatedDrivers = [];

        foreach ($approvedRuns as $run) {
            $contractObj = $run->contract;
            $contractName = $contractObj?->name ?? "عقد #{$run->contract_id}";
            $snapshot = $run->snapshot_data;
            $drivers = $snapshot['drivers'] ?? [];

            foreach ($drivers as $d) {
                $empId = $d['employee_id'];
                if (!$empId) continue;

                if (!isset($consolidatedDrivers[$empId])) {
                    $consolidatedDrivers[$empId] = [
                        'employee_id' => $empId,
                        'employee_name' => $d['employee_name'],
                        'employee_number' => $d['employee_number'],
                        'assigned_days' => $d['assigned_days'] ?? 0,
                        'actual_work_days' => $d['actual_work_days'] ?? 0,
                        'orders_count' => $d['orders_count'] ?? 0,
                        'gross_contract_earnings' => (float) ($d['gross_contract_earnings'] ?? 0.0),
                        'violations_deduction' => (float) ($d['violations_deduction'] ?? 0.0),
                        'contracts_worked' => []
                    ];
                } else {
                    $consolidatedDrivers[$empId]['assigned_days'] = max($consolidatedDrivers[$empId]['assigned_days'], $d['assigned_days'] ?? 0);
                    $consolidatedDrivers[$empId]['actual_work_days'] += ($d['actual_work_days'] ?? 0);
                    $consolidatedDrivers[$empId]['orders_count'] += ($d['orders_count'] ?? 0);
                    $consolidatedDrivers[$empId]['gross_contract_earnings'] += (float) ($d['gross_contract_earnings'] ?? 0.0);
                    $consolidatedDrivers[$empId]['violations_deduction'] += (float) ($d['violations_deduction'] ?? 0.0);
                }

                $consolidatedDrivers[$empId]['contracts_worked'][] = [
                    'contract_id' => $run->contract_id,
                    'contract_name' => $contractName,
                    'payment_method' => $d['payment_method'] ?? 'fixed',
                    'payment_method_label' => $d['payment_method_label'] ?? 'ثابت',
                    'orders_count' => $d['orders_count'] ?? 0,
                    'gross' => (float) ($d['gross_contract_earnings'] ?? 0.0),
                    'violations' => (float) ($d['violations_deduction'] ?? 0.0),
                    'net' => (float) ($d['net_payout'] ?? 0.0),
                    'calculation_details' => $d['calculation_details'] ?? []
                ];
            }
        }

        // Fetch company-level Salary Advances for all active drivers in this month
        $allEmpIds = array_keys($consolidatedDrivers);
        $advancesMap = [];
        if (!empty($allEmpIds)) {
            $advances = \App\Models\SalaryAdvance::withoutGlobalScopes()
                ->whereIn('employee_id', $allEmpIds)
                ->where('status', 'approved')
                ->whereBetween('request_date', [$startDate, $endDate])
                ->get();

            foreach ($advances as $adv) {
                $advancesMap[$adv->employee_id] = ($advancesMap[$adv->employee_id] ?? 0.0) + (float)$adv->amount;
            }
        }

        $driversList = [];
        $totalOrdersSum = 0;
        $totalGrossSum = 0.0;
        $totalViolationsSum = 0.0;
        $totalAdvancesSum = 0.0;
        $totalFinalNetSum = 0.0;

        foreach ($consolidatedDrivers as $empId => $d) {
            $advDeduction = round((float)($advancesMap[$empId] ?? 0.0), 3);
            $gross = round($d['gross_contract_earnings'], 3);
            $viols = round($d['violations_deduction'], 3);
            $finalNet = round($gross - $viols - $advDeduction, 3);

            $d['advances_deduction'] = $advDeduction;
            $d['final_net_payout'] = $finalNet;
            $driversList[] = $d;

            $totalOrdersSum += $d['orders_count'];
            $totalGrossSum += $gross;
            $totalViolationsSum += $viols;
            $totalAdvancesSum += $advDeduction;
            $totalFinalNetSum += $finalNet;
        }

        return response()->json([
            'period' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth
            ],
            'summary' => [
                'total_approved_contracts' => count($approvedRuns),
                'total_unapproved_contracts' => count($unapprovedContracts),
                'total_drivers' => count($driversList),
                'total_orders' => $totalOrdersSum,
                'total_gross_earnings' => round($totalGrossSum, 3),
                'total_violations_deductions' => round($totalViolationsSum, 3),
                'total_advances_deductions' => round($totalAdvancesSum, 3),
                'total_final_net_payout' => round($totalFinalNetSum, 3)
            ],
            'approved_runs' => $approvedRuns->map(function($r) {
                return [
                    'contract_id' => $r->contract_id,
                    'contract_name' => $r->contract?->name,
                    'approved_at' => $r->approved_at,
                    'approved_by_name' => $r->approvedBy?->name,
                    'total_drivers' => $r->total_drivers,
                    'total_net_payout' => $r->total_net_payout,
                ];
            }),
            'unapproved_contracts' => $unapprovedContracts,
            'drivers' => $driversList
        ]);
    }

    private static function calculateFixedDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $segRatio, $assignedDays, $vtId)
    {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId]) ? $contract->driver_pricing_rules[$vtId] : [];
        $isOverride = !empty($override);

        $baseSalaryConfig = $isOverride ? ($override->fixed_amount ?? $override->custom_fixed_salary ?? 0) : ($vtPricing['fixed_amount'] ?? $contract->default_fixed_salary ?? $employee->salary ?? 0);
        $targetConfig = $isOverride ? ($override->fixed_target ?? $override->custom_monthly_target ?? 0) : ($vtPricing['fixed_target'] ?? 0);
        $deficitRateConfig = $isOverride ? ($override->fixed_deficit_rate ?? 0) : ($vtPricing['fixed_deficit_rate'] ?? 0);

        // Required work days and divisor
        $requiredWorkDays = (int) ($contract->default_required_work_days ?? 26);
        $absenceDivisor = (int) ($contract->default_absence_divisor ?? $requiredWorkDays);
        if ($absenceDivisor <= 0) $absenceDivisor = 26;

        $actualWorkDays = $empLogs->filter(function($log) {
            return ($log->orders_count > 0) || ($log->cash_collected > 0) || ($log->rejected_orders_count > 0) || ($log->driver_status === 'working');
        })->count();

        $paidLeaveDays = $empLogs->filter(function($log) {
            return in_array($log->driver_status, ['paid_leave', 'holiday']);
        })->count();

        $creditedDays = $actualWorkDays + $paidLeaveDays;

        if ($isOverride) {
            // Override salary is a custom explicit fixed amount; proration ratio is NOT applied to base salary, but absence is deducted
            $proratedBaseSalary = round((float)$baseSalaryConfig, 3);
            $proratedTarget = (int) $targetConfig;

            $proratedRequiredDays = (int) round($requiredWorkDays * $segRatio);
            $absenceDays = max(0, $proratedRequiredDays - $creditedDays);
            $dailyBaseRate = round((float)$baseSalaryConfig / $absenceDivisor, 3);
            $absenceDeduction = round($absenceDays * $dailyBaseRate, 3);

            $details = [
                [
                    'label' => 'الراتب الثابت المخصص (استثناء مالي)',
                    'amount' => $proratedBaseSalary,
                    'formula' => "مبلغ استثناء مالي مخصص ثنائي = {$baseSalaryConfig} د.ك"
                ]
            ];
        } else {
            $effectiveRatio = $segRatio;
            $proratedBaseSalary = round((float)$baseSalaryConfig * $effectiveRatio, 3);
            $proratedTarget = (int) round((float)$targetConfig * $effectiveRatio);

            $proratedRequiredDays = (int) round($requiredWorkDays * $segRatio);
            $absenceDays = max(0, $proratedRequiredDays - $creditedDays);
            $dailyBaseRate = round((float)$baseSalaryConfig / $absenceDivisor, 3);
            $absenceDeduction = round($absenceDays * $dailyBaseRate, 3);

            $details = [
                [
                    'label' => 'الراتب الثابت الأساسي (مجزأ)',
                    'amount' => $proratedBaseSalary,
                    'formula' => "الراتب الأساسي {$baseSalaryConfig} د.ك × نسبة التواجد " . round($segRatio * 100, 1) . "% = {$proratedBaseSalary} د.ك"
                ]
            ];
        }

        if ($paidLeaveDays > 0) {
            $details[] = [
                'label' => "إجازات مدفوعة الأجر ({$paidLeaveDays} يوم مدفوع بالكامل)",
                'count' => $paidLeaveDays,
                'unit' => 'day',
                'type' => 'paid_leave',
                'rate' => $dailyBaseRate,
                'amount' => 0.0,
                'formula' => "{$paidLeaveDays} أيام إجازة مدفوعة (محسوبة ضمن أصل الـ {$proratedRequiredDays} يوم عمل)"
            ];
        }

        if ($absenceDays > 0) {
            $details[] = [
                'label' => "خصم أيام الغياب غير المدفوعة ({$absenceDays} أيام غياب من أصل {$proratedRequiredDays} يوم عمل مطلوب)",
                'count' => $absenceDays,
                'unit' => 'day',
                'type' => 'absence',
                'rate' => $dailyBaseRate,
                'amount' => -$absenceDeduction,
                'formula' => "غياب {$absenceDays} أيام × {$dailyBaseRate} د.ك/يوم = -{$absenceDeduction} د.ك"
            ];
        }

        if ($proratedTarget > 0) {
            if ($deficitDeduction > 0) {
                $details[] = [
                    'label' => "خصم النقص في التارغت (مستهدف: {$proratedTarget} | منفذ: {$ordersCount})",
                    'count' => ($proratedTarget - $ordersCount),
                    'orders' => ($proratedTarget - $ordersCount),
                    'unit' => 'order',
                    'type' => 'deficit',
                    'rate' => (float)$deficitRateConfig,
                    'amount' => -$deficitDeduction,
                    'formula' => "نقص " . ($proratedTarget - $ordersCount) . " طلب × {$deficitRateConfig} د.ك = -{$deficitDeduction} د.ك"
                ];
            } elseif ($surplusBonus > 0) {
                $details[] = [
                    'label' => "بونص تجاوز التارغت (مستهدف: {$proratedTarget} | منفذ: {$ordersCount})",
                    'count' => ($ordersCount - $proratedTarget),
                    'orders' => ($ordersCount - $proratedTarget),
                    'unit' => 'order',
                    'type' => 'surplus',
                    'rate' => $surplusRate,
                    'amount' => $surplusBonus,
                    'formula' => "زيادة " . ($ordersCount - $proratedTarget) . " طلب × {$surplusRate} د.ك = {$surplusBonus} د.ك"
                ];
            }
        }

        return [
            'base_salary' => $proratedBaseSalary,
            'orders_count' => $ordersCount,
            'orders_bonus' => 0.0,
            'deficit_deduction' => $deficitDeduction,
            'surplus_bonus' => $surplusBonus,
            'absence_deduction' => $absenceDeduction,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details
        ];
    }

    private static function calculatePerOrderDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId)
    {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId]) ? $contract->driver_pricing_rules[$vtId] : [];
        $rate = (float) ($override ? ($override->order_commission ?? 0) : ($vtPricing['order_commission'] ?? $contract->rate_per_order ?? 0.0));
        
        $ordersCount = $empLogs->sum('orders_count');
        $ordersBonus = round($ordersCount * $rate, 3);

        $details = [
            [
                'label' => 'عمولة الطلبات المنجزة',
                'orders' => $ordersCount,
                'rate' => $rate,
                'amount' => $ordersBonus,
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$ordersBonus} د.ك"
            ]
        ];

        return [
            'base_salary' => 0.0,
            'orders_count' => $ordersCount,
            'orders_bonus' => $ordersBonus,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $ordersBonus,
            'calculation_details' => $details
        ];
    }

    private static function calculateHybridDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $segRatio, $assignedDays, $vtId)
    {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId]) ? $contract->driver_pricing_rules[$vtId] : [];
        $baseSalaryConfig = $override ? ($override->fixed_amount ?? 0) : ($vtPricing['fixed_amount'] ?? $employee->salary ?? 0);
        $rate = (float) ($override ? ($override->order_commission ?? 0) : ($vtPricing['order_commission'] ?? 0.0));

        $proratedBaseSalary = round((float)$baseSalaryConfig * $segRatio, 3);
        $ordersCount = $empLogs->sum('orders_count');
        $ordersBonus = round($ordersCount * $rate, 3);
        $gross = $proratedBaseSalary + $ordersBonus;

        $details = [
            [
                'label' => 'الراتب الثابت المخصص (مجزأ)',
                'amount' => $proratedBaseSalary,
                'formula' => "{$baseSalaryConfig} د.ك × نسبة التواجد " . round($segRatio * 100, 1) . "% = {$proratedBaseSalary} د.ك"
            ],
            [
                'label' => 'عمولة الطلبات فوق الراتب',
                'orders' => $ordersCount,
                'rate' => $rate,
                'amount' => $ordersBonus,
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$ordersBonus} د.ك"
            ]
        ];

        return [
            'base_salary' => $proratedBaseSalary,
            'orders_count' => $ordersCount,
            'orders_bonus' => $ordersBonus,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details
        ];
    }

    private static function calculateZonesDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId)
    {
        $pricingRules = null;
        if ($override && isset($override->zones) && is_array($override->zones) && count($override->zones) > 0) {
            $pricingRules = $override->zones;
        } else {
            $pricingRules = is_string($contract->driver_pricing_rules) ? json_decode($contract->driver_pricing_rules, true) : $contract->driver_pricing_rules;
            if (is_array($pricingRules)) {
                if ($vtId && isset($pricingRules[$vtId]['zones'])) {
                    $pricingRules = $pricingRules[$vtId]['zones'];
                } else {
                    $firstKey = array_key_first($pricingRules);
                    if ($firstKey !== null && isset($pricingRules[$firstKey]['zones'])) {
                        $pricingRules = $pricingRules[$firstKey]['zones'];
                    }
                }
            }
        }

        $zoneOrdersTotals = [];
        $totalOrders = 0;

        foreach ($empLogs as $l) {
            $cOrders = (int)$l->orders_count;
            if ($cOrders <= 0) continue;
            $totalOrders += $cOrders;

            $notesData = $l->notes ? json_decode($l->notes, true) : null;
            $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders'])) ? $notesData['zone_orders'] : [];

            if (!empty($zoneOrdersMap)) {
                foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                    $zCount = (int)$zCount;
                    if ($zCount <= 0) continue;
                    $zoneOrdersTotals[$zIdOrName] = ($zoneOrdersTotals[$zIdOrName] ?? 0) + $zCount;
                }
            } else {
                $zName = $l->zone ?: 'افتراضي';
                $zoneOrdersTotals[$zName] = ($zoneOrdersTotals[$zName] ?? 0) + $cOrders;
            }
        }

        $gross = 0.0;
        $details = [];

        if (is_array($pricingRules)) {
            foreach ($zoneOrdersTotals as $zIdOrName => $zCount) {
                $zRuleName = $zIdOrName;
                $zRate = 0.0;
                foreach ($pricingRules as $rule) {
                    if (is_array($rule) && (
                        (isset($rule['id']) && (string)$rule['id'] === (string)$zIdOrName) ||
                        (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                        (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                    )) {
                        $zRuleName = $rule['name'] ?? $rule['zone'] ?? $zIdOrName;
                        $zRate = (float) ($rule['price'] ?? $rule['rate'] ?? 0.0);
                        break;
                    }
                }
                $amt = round($zCount * $zRate, 3);
                $gross += $amt;
                $details[] = [
                    'label' => "فئة ({$zRuleName})",
                    'orders' => $zCount,
                    'rate' => $zRate,
                    'amount' => $amt,
                    'formula' => "{$zCount} طلب × {$zRate} د.ك = {$amt} د.ك"
                ];
            }
        }

        return [
            'base_salary' => 0.0,
            'orders_count' => $totalOrders,
            'orders_bonus' => round($gross, 3),
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => round($gross, 3),
            'calculation_details' => $details
        ];
    }

    private static function calculateZonesTiersDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId)
    {
        $pricingRules = null;
        if ($override && isset($override->zones_tiers) && is_array($override->zones_tiers) && count($override->zones_tiers) > 0) {
            $pricingRules = $override->zones_tiers;
        } else {
            $pricingRules = is_string($contract->driver_pricing_rules) ? json_decode($contract->driver_pricing_rules, true) : $contract->driver_pricing_rules;
            if (is_array($pricingRules)) {
                if ($vtId && isset($pricingRules[$vtId]['zones_tiers'])) {
                    $pricingRules = $pricingRules[$vtId]['zones_tiers'];
                } else {
                    $firstKey = array_key_first($pricingRules);
                    if ($firstKey !== null && isset($pricingRules[$firstKey]['zones_tiers'])) {
                        $pricingRules = $pricingRules[$firstKey]['zones_tiers'];
                    }
                }
            }
        }

        $zoneOrdersTotals = [];
        $totalOrders = 0;

        foreach ($empLogs as $l) {
            $cOrders = (int)$l->orders_count;
            if ($cOrders <= 0) continue;
            $totalOrders += $cOrders;

            $notesData = $l->notes ? json_decode($l->notes, true) : null;
            $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders'])) ? $notesData['zone_orders'] : [];

            if (!empty($zoneOrdersMap)) {
                foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                    $zCount = (int)$zCount;
                    if ($zCount <= 0) continue;
                    $zoneOrdersTotals[$zIdOrName] = ($zoneOrdersTotals[$zIdOrName] ?? 0) + $zCount;
                }
            } else {
                $zName = $l->zone ?: 'افتراضي';
                $zoneOrdersTotals[$zName] = ($zoneOrdersTotals[$zName] ?? 0) + $cOrders;
            }
        }

        $gross = 0.0;
        $details = [];

        if (is_array($pricingRules)) {
            foreach ($zoneOrdersTotals as $zIdOrName => $zCount) {
                $zRuleName = $zIdOrName;
                $zTiers = [];
                foreach ($pricingRules as $rule) {
                    if (is_array($rule) && (
                        (isset($rule['id']) && (string)$rule['id'] === (string)$zIdOrName) ||
                        (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                        (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                    )) {
                        $zRuleName = $rule['name'] ?? $rule['zone'] ?? $zIdOrName;
                        $zTiers = $rule['tiers'] ?? [];
                        break;
                    }
                }

                // Resolve highest tier reached for $zCount (FIXED UNCONTAINED LIMITS as directed)
                $zRate = 0.0;
                $tierLabel = "الأساسية";
                if (!empty($zTiers)) {
                    foreach ($zTiers as $t) {
                        $min = (int) ($t['min'] ?? 1);
                        $max = isset($t['max']) && $t['max'] !== null && $t['max'] !== '' ? (int)$t['max'] : INF;
                        if ($zCount >= $min && $zCount <= $max) {
                            $zRate = (float) ($t['price'] ?? 0.0);
                            $maxText = $max === INF ? 'فأكثر' : "إلى {$max}";
                            $tierLabel = "الشريحة ({$min}-{$maxText} طلب)";
                            break;
                        }
                    }
                }

                $amt = round($zCount * $zRate, 3);
                $gross += $amt;
                $details[] = [
                    'label' => "فئة ({$zRuleName}) - {$tierLabel}",
                    'orders' => $zCount,
                    'rate' => $zRate,
                    'amount' => $amt,
                    'formula' => "{$zCount} طلب × {$zRate} د.ك = {$amt} د.ك"
                ];
            }
        }

        return [
            'base_salary' => 0.0,
            'orders_count' => $totalOrders,
            'orders_bonus' => round($gross, 3),
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => round($gross, 3),
            'calculation_details' => $details
        ];
    }

    private static function calculateTiersDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId)
    {
        $tiers = [];
        if ($override) {
            $cRules = is_string($override->custom_pricing_rules) ? json_decode($override->custom_pricing_rules, true) : $override->custom_pricing_rules;
            if (is_array($cRules) && isset($cRules['tiers']) && is_array($cRules['tiers']) && count($cRules['tiers']) > 0) {
                $tiers = $cRules['tiers'];
            } elseif (is_array($override->pricing_rules) && isset($override->pricing_rules['tiers'])) {
                $tiers = $override->pricing_rules['tiers'];
            }
        }

        if (empty($tiers)) {
            $driverRules = is_string($contract->driver_pricing_rules) ? json_decode($contract->driver_pricing_rules, true) : $contract->driver_pricing_rules;
            if (is_array($driverRules)) {
                if ($vtId && isset($driverRules[$vtId]['tiers'])) {
                    $tiers = $driverRules[$vtId]['tiers'];
                } else {
                    $firstKey = array_key_first($driverRules);
                    if ($firstKey !== null && isset($driverRules[$firstKey]['tiers'])) {
                        $tiers = $driverRules[$firstKey]['tiers'];
                    }
                }
            }
        }

        $ordersCount = $empLogs->sum('orders_count');
        $rate = 0.0;
        $tierLabel = "الأساسية";

        if (!empty($tiers)) {
            foreach ($tiers as $t) {
                $min = (int) ($t['min'] ?? 1);
                $max = isset($t['max']) && $t['max'] !== null && $t['max'] !== '' ? (int)$t['max'] : INF;
                if ($ordersCount >= $min && $ordersCount <= $max) {
                    $rate = (float) ($t['price'] ?? 0.0);
                    $maxText = $max === INF ? 'فأكثر' : "إلى {$max}";
                    $tierLabel = "الشريحة ({$min}-{$maxText} طلب)";
                    break;
                }
            }
        }

        $gross = round($ordersCount * $rate, 3);

        $details = [
            [
                'label' => "عمولة الطلبات حسب الشريحة - {$tierLabel}",
                'orders' => $ordersCount,
                'rate' => $rate,
                'amount' => $gross,
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$gross} د.ك"
            ]
        ];

        return [
            'base_salary' => 0.0,
            'orders_count' => $ordersCount,
            'orders_bonus' => $gross,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details
        ];
    }

    private static function getPaymentMethodLabel($method)
    {
        return match ($method) {
            'fixed' => 'راتب ثابت (Fixed)',
            'per_order' => 'بالطلب (Per-Order)',
            'hybrid' => 'هجين (Fixed + Commission)',
            'zones' => 'فئات (Zones)',
            'zones_tiers' => 'شرائح الفئات (Zones + Tiers)',
            'tiers' => 'شرائح (Tiers)',
            default => $method
        };
    }
}
