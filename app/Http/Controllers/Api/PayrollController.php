<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ErpSync;
use App\Http\Controllers\Controller;
use App\Models\AdvanceDeduction;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractMandatoryDay;
use App\Models\ContractMonthlyParameter;
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
            $allDailyLogs = DailyLog::whereIn('employee_id', $employeeIds)
                ->whereBetween('log_date', [$startDate, $endDate])
                ->orderBy('log_date')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'vehicle_id', 'shift_valid', 'online_hours', 'ontime_rate', 'avg_delivery_time', 'is_valid', 'late_login', 'early_logout', 'zone'])
                ->groupBy('employee_id');

            $violationSums = Violation::whereIn('employee_id', $employeeIds)
                ->where('is_driver_liable', true)
                ->where('is_deducted', false)
                ->whereDate('violation_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(CASE WHEN driver_deduction > 0 THEN driver_deduction ELSE amount END) as total')
                ->pluck('total', 'employee_id');

            // 3. Maintenance deductions
            $maintenanceSums = MaintenanceRecord::whereIn('liable_employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('maintenance_date', '<=', $endDate)
                ->groupBy('liable_employee_id')
                ->selectRaw('liable_employee_id, SUM(driver_deduction) as total')
                ->pluck('total', 'liable_employee_id');

            // 4. Custody deductions
            $custodySums = CustodyItem::whereIn('employee_id', $employeeIds)
                ->where('status', 'returned')
                ->whereIn('return_condition', ['damaged', 'lost'])
                ->where('deduction_amount', '>', 0)
                ->whereDate('returned_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(deduction_amount) as total')
                ->pluck('total', 'employee_id');

            // 5. Leave deductions
            $leaveData = EmployeeLeave::whereIn('employee_id', $employeeIds)
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
            $allAdvances = SalaryAdvance::whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
                ->whereDate('advance_date', '<=', $endDate)
                ->get()
                ->groupBy('employee_id');

            // 7. Vehicle assignments for fuel allowance
            $allAssignments = \DB::table('vehicle_assignments')
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
                    $allAssignments
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
        $year = $run->year;
        $month = $run->month;
        $startDate = "{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01';
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        DB::beginTransaction();
        try {
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

            // Uncheck violations previously deducted in this run
            Violation::whereIn('payroll_slip_id', $slipIds)->update([
                'is_deducted' => false,
                'payroll_slip_id' => null,
            ]);

            // Uncheck driver expenses previously deducted in this run
            \App\Models\DriverExpense::whereIn('payroll_slip_id', $slipIds)->update([
                'is_deducted' => false,
                'payroll_slip_id' => null,
            ]);

            $employeeIds = PayrollSlip::where('payroll_run_id', $run->id)->pluck('employee_id')->toArray();
            if (empty($employeeIds)) {
                $employeeIds = Employee::where('role_category', 'driver')->whereIn('status', ['active', 'probation'])->pluck('id')->toArray();
            }

            $employees = Employee::whereIn('id', $employeeIds)->get();
            $totalOfficial = 0;
            $totalActual = 0;

            // 1. Daily logs
            $allDailyLogs = DailyLog::whereIn('employee_id', $employeeIds)
                ->whereBetween('log_date', [$startDate, $endDate])
                ->orderBy('log_date')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'vehicle_id', 'shift_valid', 'online_hours', 'ontime_rate', 'avg_delivery_time', 'is_valid', 'late_login', 'early_logout', 'zone'])
                ->groupBy('employee_id');

            $violationSums = Violation::whereIn('employee_id', $employeeIds)
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
            $maintenanceSums = MaintenanceRecord::whereIn('liable_employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('maintenance_date', '<=', $endDate)
                ->groupBy('liable_employee_id')
                ->selectRaw('liable_employee_id, SUM(driver_deduction) as total')
                ->pluck('total', 'liable_employee_id');

            // 4. Custody deductions
            $custodySums = CustodyItem::whereIn('employee_id', $employeeIds)
                ->where('status', 'returned')
                ->whereIn('return_condition', ['damaged', 'lost'])
                ->where('deduction_amount', '>', 0)
                ->whereDate('returned_date', '<=', $endDate)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(deduction_amount) as total')
                ->pluck('total', 'employee_id');

            // 4b. Driver expense deductions
            $driverExpenseSums = \App\Models\DriverExpense::whereIn('employee_id', $employeeIds)
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
            $leaveData = EmployeeLeave::whereIn('employee_id', $employeeIds)
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
            $allAdvances = SalaryAdvance::whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
                ->whereDate('advance_date', '<=', $endDate)
                ->get()
                ->groupBy('employee_id');

            // 7. Vehicle assignments for fuel allowance
            $allAssignments = \DB::table('vehicle_assignments')
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
                    $allAssignments,
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

                    // Clean up any existing auto-advance for this employee from this run
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

                // Re-mark violations as deducted
                if ($data['violations_deduction'] > 0) {
                    Violation::where('employee_id', $employee->id)
                        ->where('is_driver_liable', true)
                        ->where('is_deducted', false)
                        ->whereDate('violation_date', '<=', $endDate)
                        ->update([
                            'is_deducted' => true,
                            'payroll_slip_id' => $slip->id,
                        ]);
                }

                // Re-mark driver expenses as deducted
                if (($data['driver_expense_deduction'] ?? 0) > 0) {
                    \App\Models\DriverExpense::where('employee_id', $employee->id)
                        ->where('driver_amount', '>', 0)
                        ->where('is_deducted', false)
                        ->whereDate('expense_date', '<=', $endDate)
                        ->update([
                            'is_deducted' => true,
                            'payroll_slip_id' => $slip->id,
                        ]);
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

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Recalculate Run failed: '.$e->getMessage());
        }
    }

    public static function recalculateEmployeeCommissions($employeeId, $year, $month, $preFetchedLogs = null)
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

        $startDate = "{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01';
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        $logs = $preFetchedLogs ?? DailyLog::where('employee_id', $employeeId)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->orderBy('log_date')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'vehicle_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'zone', 'shift_valid', 'is_valid', 'online_hours', 'created_by']);

        $target = (int) ($employee->target_orders_monthly ?? 0);
        $baseRate = (float) (($target > 0 && $employee->base_commission_rate !== null) ? $employee->base_commission_rate : ($employee->rate_per_order ?? 0.000));
        $premiumRate = (float) ($employee->premium_commission_rate ?? 0.000);

        $runningOrders = 0;

        foreach ($logs as $log) {
            $cOrders = (int) $log->orders_count;
            $logCommission = 0;

            // Check if log has contract or driver has active assignment on log_date
            $contractId = $log->contract_id;
            if (! $contractId) {
                $assignment = ContractAssignment::where('employee_id', $employeeId)
                    ->whereDate('start_date', '<=', $log->log_date)
                    ->where(function ($q) use ($log) {
                        $q->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $log->log_date);
                    })
                    ->first();
                if ($assignment) {
                    $contractId = $assignment->contract_id;
                }
            }

            $rate = null;
            if ($contractId) {
                // Check if there is an active ContractAssignment
                $assignment = ContractAssignment::where('employee_id', $employeeId)
                    ->where('contract_id', $contractId)
                    ->whereDate('start_date', '<=', $log->log_date)
                    ->where(function ($q) use ($log) {
                        $q->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $log->log_date);
                    })
                    ->first();
                if ($assignment) {
                    $rate = SmartValueFallbackService::resolve($employeeId, $contractId, $log->log_date, 'order_commission');
                    if ($rate === null) {
                        $contractObj = Contract::find($contractId);
                        if ($contractObj && ($contractObj->driver_payment_method === 'zones' || $contractObj->payment_type === 'zones')) {
                            $pricingRules = is_string($contractObj->driver_pricing_rules)
                                ? json_decode($contractObj->driver_pricing_rules, true)
                                : $contractObj->driver_pricing_rules;
                            $zoneName = $log->zone;
                            if (is_array($pricingRules)) {
                                if (isset($pricingRules[$zoneName])) {
                                    $rate = (float) $pricingRules[$zoneName];
                                } else {
                                    foreach ($pricingRules as $rule) {
                                        if (is_array($rule) && isset($rule['zone']) && $rule['zone'] == $zoneName) {
                                            $rate = (float) ($rule['rate'] ?? 0.0);
                                            break;
                                        }
                                    }
                                }
                            }
                        }
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
        $empContractAssignments = ContractAssignment::where('employee_id', $employeeId)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->with('contract')
            ->get();

        $singleLogContractId = null;
        if ($empContractAssignments->isEmpty()) {
            $uniqueLogContracts = $empLogs->pluck('contract_id')->filter()->unique();
            if ($uniqueLogContracts->count() === 1) {
                $singleLogContractId = $uniqueLogContracts->first();
            }
        }

        // Fetch vehicle assignments active in this month
        $empVehicleAssignments = VehicleAssignment::where('employee_id', $employeeId)
            ->where('assigned_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('unassigned_date')
                    ->orWhere('unassigned_date', '>=', $startDate);
            })
            ->with('vehicle')
            ->get();

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
                $sDate = $a->start_date instanceof Carbon ? $a->start_date->toDateString() : substr($a->start_date, 0, 10);
                $eDate = $a->end_date ? ($a->end_date instanceof Carbon ? $a->end_date->toDateString() : substr($a->end_date, 0, 10)) : null;

                return $sDate <= $date
                    && ($eDate === null || $eDate >= $date);
            });

            // Find vehicle assignment active on this day
            $activeVehicleAssign = $empVehicleAssignments->first(function ($va) use ($date) {
                $sDate = $va->assigned_date instanceof Carbon ? $va->assigned_date->toDateString() : substr($va->assigned_date, 0, 10);
                $eDate = $va->unassigned_date ? ($va->unassigned_date instanceof Carbon ? $va->unassigned_date->toDateString() : substr($va->unassigned_date, 0, 10)) : null;

                return $sDate <= $date
                    && ($eDate === null || $eDate >= $date);
            });

            $dayLog = $empLogs->firstWhere('log_date', $date);

            $contractIdVal = ($dayLog && $dayLog->contract_id) 
                ? $dayLog->contract_id 
                : ($activeContractAssign ? $activeContractAssign->contract_id : null);

            // Find vehicle type id
            $vehicleTypeIdVal = null;
            if ($dayLog && $dayLog->vehicle_id) {
                $v = \App\Models\Vehicle::find($dayLog->vehicle_id);
                $vehicleTypeIdVal = $v?->vehicle_type_id;
            }
            if (!$vehicleTypeIdVal && $activeVehicleAssign && $activeVehicleAssign->vehicle) {
                $vehicleTypeIdVal = $activeVehicleAssign->vehicle->vehicle_type_id;
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

        $contractId = $primarySegment['contract_id'];
        $contract = $primarySegment['contract_assignment'] ? $primarySegment['contract_assignment']->contract : null;
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
                            $recalc = self::recalculateEmployeeCommissions($employee, $lContract, $year, $month, $segLogs);
                            $segCommissions = $recalc['orders_bonus'];
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

            $segContract = Contract::find($segContractId);
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
            if (isset($segment['contract_assignment']) && $segment['contract_assignment']) {
                $activeOverride = DriverContractOverride::where('contract_assignment_id', $segment['contract_assignment']->id)
                    ->whereDate('effective_from', '<=', $segment['end_date'])
                    ->where(function ($q) use ($segment) {
                        $q->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', $segment['start_date']);
                    })
                    ->first();
            }

            $vehicleTypeId = $segment['vehicle_type_id'] ?? null;
            $segPaymentType = $segContract->payment_type;
            $driverPaymentMethod = $segContract->driver_payment_method ?? $segPaymentType;

            if ($activeOverride && $activeOverride->override_type !== null) {
                $driverPaymentMethod = $activeOverride->override_type;
            } elseif ($vehicleTypeId !== null && is_array($segContract->driver_pricing_rules) && isset($segContract->driver_pricing_rules[$vehicleTypeId]['payment_method'])) {
                $driverPaymentMethod = $segContract->driver_pricing_rules[$vehicleTypeId]['payment_method'];
            }

            // Recalculate daily log commissions for this segment logs
            $segLogsRecalculated = self::recalculateEmployeeCommissions($employee, $segContract, $year, $month, $segLogs);

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
                    $segBaseActual = $segOrdersBonus;
                    $segOrdersBonus = 0.0;
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
                    $segBaseActual = $segLogsRecalculated->sum('driver_commission');
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
                        if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                            $pricingRules = $pricingRules[$vehicleTypeId];
                        }
                    }
                    if (is_array($pricingRules) && isset($pricingRules['zones'])) {
                        $pricingRules = $pricingRules['zones'];
                    }

                    $payout = 0.0;
                    $groupedLogs = $segLogs->groupBy('zone');
                    foreach ($groupedLogs as $zoneName => $zoneLogs) {
                        $zoneOrders = $zoneLogs->sum('orders_count');
                        $rate = null;
                        if (is_array($pricingRules)) {
                            if (isset($pricingRules[$zoneName])) {
                                $rate = (float) $pricingRules[$zoneName];
                            } else {
                                foreach ($pricingRules as $rule) {
                                    if (is_array($rule) && isset($rule['zone']) && $rule['zone'] == $zoneName) {
                                        $rate = (float) ($rule['rate'] ?? 0.0);
                                        break;
                                    }
                                }
                            }
                        }
                        if ($rate === null) {
                            $rate = (float) ($segContract->default_order_commission ?? 0.0);
                        }
                        $payout += $zoneOrders * $rate * $segExchangeRate;
                    }

                    // Deficit/Surplus Calculation for Zones
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
                    $segBaseActual = $payout - $deficitDeduction + $surplusBonusAmt;
                } elseif ($driverPaymentMethod === 'zones_tiers') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                            $pricingRules = $pricingRules[$vehicleTypeId];
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
                    $segBaseActual = $payout;
                } elseif ($driverPaymentMethod === 'tiers') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                            $pricingRules = $pricingRules[$vehicleTypeId];
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
                    $segBaseActual = $segOrders * $selectedPrice * $segExchangeRate;
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
}
