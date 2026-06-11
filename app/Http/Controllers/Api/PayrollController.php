<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ErpSync;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\CustodyItem;
use App\Models\SalaryAdvance;
use App\Models\AdvanceDeduction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Services\Contracts\SmartValueFallbackService;

class PayrollController extends Controller
{
    /**
     * POST /api/payroll/run
     * Generate monthly payroll for all active employees.
     * System auto-calculates — prevents manual tampering from meeting.
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year'  => 'required|integer|min:2020|max:2030',
            'month' => 'required|integer|min:1|max:12',
            'notes' => 'nullable|string',
        ]);

        $year  = $validated['year'];
        $month = $validated['month'];

        // Prevent duplicate runs OR auto-recalculate if draft
        $existingRun = PayrollRun::where('year', $year)->where('month', $month)->first();
        if ($existingRun) {
            if ($existingRun->status === 'draft') {
                self::recalculateRun($existingRun);
                return response()->json([
                    'message'        => 'تم تحديث وإعادة احتساب رواتب هذا الشهر بنجاح.',
                    'run_id'         => $existingRun->id,
                    'employees'      => Employee::whereIn('status', ['active', 'probation'])->count(),
                    'total_official' => $existingRun->total_official,
                    'total_actual'   => $existingRun->total_actual,
                    'total_cash'     => $existingRun->total_cash_diff,
                ], 200);
            }
            return response()->json(['message' => 'تم احتساب واعتماد رواتب هذا الشهر مسبقاً.'], 422);
        }

        $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate   = \Carbon\Carbon::parse($startDate)->endOfMonth()->toDateString();

        DB::beginTransaction();
        try {
            $run = PayrollRun::create([
                'year'       => $year,
                'month'      => $month,
                'created_by' => $request->user()->id,
                'status'     => 'draft',
                'notes'      => $validated['notes'] ?? null,
            ]);

            $employees = Employee::whereIn('status', ['active', 'probation'])->get();
            $employeeIds = $employees->pluck('id');
            $totalOfficial = 0;
            $totalActual   = 0;

            // 1. Daily logs pre-fetched
            $allDailyLogs = DailyLog::whereIn('employee_id', $employeeIds)
                ->whereBetween('log_date', [$startDate, $endDate])
                ->orderBy('log_date')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'vehicle_id', 'shift_valid', 'online_hours', 'ontime_rate', 'avg_delivery_time'])
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

                $totalBonuses     = $data['orders_bonus'] + $data['fuel_allowance'] + $data['total_capacity_incentive'] + $data['total_experience_incentive'] + $data['total_contract_bonuses'];
                $totalGrossActual = $data['base_actual'] + $totalBonuses;
                $totalDeductions  = $data['violations_deduction'] + $data['maintenance_deduction'] + $data['custody_deduction'] + $data['leave_deduction'] + $data['advance_deduction'];
                $netActual        = max(0, $totalGrossActual - $totalDeductions);
                $netBank          = min($netActual, (float) $employee->official_salary);
                $netCash          = max(0, $netActual - $netBank);

                $slip = PayrollSlip::create([
                    'payroll_run_id'             => $run->id,
                    'employee_id'                => $employee->id,
                    'base_official'              => $employee->official_salary,
                    'base_actual'                => $data['base_actual'],
                    'orders_bonus'               => $data['orders_bonus'],
                    'fuel_allowance'             => $data['fuel_allowance'],
                    'total_orders'               => $data['total_orders'],
                    'violations_deduction'       => $data['violations_deduction'],
                    'maintenance_deduction'      => $data['maintenance_deduction'],
                    'custody_deduction'          => $data['custody_deduction'],
                    'advance_deduction'          => $data['advance_deduction'],
                    'leave_deduction'            => $data['leave_deduction'],
                    'unpaid_leave_days'          => $data['unpaid_leave_days'],
                    'gross_official'             => $netBank,
                    'gross_actual'               => $netActual,
                    'cash_portion'               => $netCash,
                    'final_monthly_status'       => $data['final_monthly_status'],
                    'total_capacity_incentive'   => $data['total_capacity_incentive'],
                    'total_experience_incentive' => $data['total_experience_incentive'],
                    'total_contract_bonuses'     => $data['total_contract_bonuses'],
                ]);

                // Mark violations as deducted
                if ($data['violations_deduction'] > 0) {
                    Violation::where('employee_id', $employee->id)
                        ->where('is_driver_liable', true)
                        ->where('is_deducted', false)
                        ->whereDate('violation_date', '<=', $endDate)
                        ->update(['is_deducted' => true, 'payroll_slip_id' => $slip->id]);
                }

                // Create AdvanceDeduction records & update advances
                $activeAdvances = $allAdvances->get($employee->id, collect());
                foreach ($activeAdvances as $advance) {
                    $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
                    if ($installment <= 0) continue;

                    AdvanceDeduction::create([
                        'salary_advance_id' => $advance->id,
                        'payroll_slip_id'   => $slip->id,
                        'amount'            => $installment,
                        'deduction_date'    => $endDate,
                        'company_id'        => $advance->company_id,
                    ]);

                    $advance->paid_installments += 1;
                    $advance->remaining_balance  = max(0, (float) $advance->remaining_balance - $installment);
                    if ($advance->remaining_balance <= 0) {
                        $advance->status = 'completed';
                    }
                    $advance->saveQuietly();
                }

                $totalOfficial += $netBank;
                $totalActual   += $netActual;
            }

            $run->update([
                'total_official' => $totalOfficial,
                'total_actual'   => $totalActual,
                'total_cash_diff'=> $totalActual - $totalOfficial,
            ]);

            DB::commit();

            // Sync official payroll to ERPNext
            ErpSync::dispatch(\App\Services\ErpNext\Jobs\SyncPayrollJob::class,
                $employees->pluck('id')->toArray(),
                (string) $year,
                str_pad((string) $month, 2, '0', STR_PAD_LEFT)
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشل في احتساب الرواتب: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message'        => 'تم احتساب رواتب الشهر بنجاح.',
            'run_id'         => $run->id,
            'employees'      => $employees->count(),
            'total_official' => $run->total_official,
            'total_actual'   => $run->total_actual,
            'total_cash'     => $run->total_cash_diff,
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

            $slips = \App\Models\PayrollSlip::where('payroll_run_id', $run->id)
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
        $run  = PayrollRun::where('year', $year)->where('month', $month)->firstOrFail();
        $slip = PayrollSlip::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        return response()->json([
            'employee'       => $employee->only(['id', 'name', 'official_salary', 'actual_salary', 'pay_type']),
            'period'         => "{$year}-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT),
            'official_sheet' => [
                'base'       => $slip->base_official,
                'fuel'       => $slip->fuel_allowance,
                'deductions' => $slip->violations_deduction + $slip->maintenance_deduction + $slip->custody_deduction + $slip->advance_deduction + $slip->leave_deduction,
                'net'        => $slip->gross_official,
            ],
            'internal_sheet' => [
                'base'                  => $slip->base_actual,
                'orders_bonus'          => $slip->orders_bonus,
                'total_orders'          => $slip->total_orders,
                'fuel_allowance'        => $slip->fuel_allowance,
                'violations_deduction'  => $slip->violations_deduction,
                'maintenance_deduction' => $slip->maintenance_deduction,
                'custody_deduction'     => $slip->custody_deduction,
                'advance_deduction'     => $slip->advance_deduction,
                'leave_deduction'       => $slip->leave_deduction,
                'unpaid_leave_days'     => $slip->unpaid_leave_days,
                'gross'                 => $slip->gross_actual,
                'bank_portion'          => $slip->gross_official,
                'cash_portion'          => $slip->cash_portion,
                'final_monthly_status'  => $slip->final_monthly_status ?? 'valid',
                'total_capacity_incentive'   => $slip->total_capacity_incentive ?? 0,
                'total_experience_incentive' => $slip->total_experience_incentive ?? 0,
                'total_contract_bonuses'     => $slip->total_contract_bonuses ?? 0,
            ],
        ]);
    }

    /**
     * POST /api/payroll/{year}/{month}/approve
     */
    public function approve(Request $request, int $year, int $month): JsonResponse
    {
        $run = PayrollRun::where('year', $year)->where('month', $month)->firstOrFail();

        if ($run->status !== 'draft') {
            return response()->json(['message' => 'لا يمكن اعتماد الرواتب إلا في حالة المسودة.'], 422);
        }

        $run->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $slips = PayrollSlip::where('payroll_run_id', $run->id)->get();

        $totalViolations  = round($slips->sum('violations_deduction'), 3);
        $totalMaintenance = round($slips->sum('maintenance_deduction'), 3);
        $totalCustody     = round($slips->sum('custody_deduction'), 3);
        $totalAdvances    = round($slips->sum('advance_deduction'), 3);
        $totalDeductions  = $totalViolations + $totalMaintenance + $totalCustody + $totalAdvances;
        $totalFuel        = round($slips->sum('fuel_allowance'), 3);

        $paddedMonth = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        if ($totalDeductions > 0) {
            ErpSync::dispatch(
                \App\Services\ErpNext\Jobs\SyncPayrollDeductionsJob::class,
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
                \App\Services\ErpNext\Jobs\SyncFuelExpenseJob::class,
                (string) $year,
                $paddedMonth,
                $totalFuel
            );
        }

        return response()->json([
            'message'           => 'تم اعتماد رواتب الشهر بنجاح.',
            'erp_sync' => [
                'deductions_synced' => $totalDeductions > 0,
                'fuel_synced'       => $totalFuel > 0,
            ],
            'summary' => [
                'violations'     => $totalViolations,
                'maintenance'    => $totalMaintenance,
                'custody'        => $totalCustody,
                'advances'       => $totalAdvances,
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
        $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate   = \Carbon\Carbon::parse($startDate)->endOfMonth()->toDateString();

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
                'payroll_slip_id' => null
            ]);

            $employeeIds = PayrollSlip::where('payroll_run_id', $run->id)->pluck('employee_id')->toArray();
            if (empty($employeeIds)) {
                $employeeIds = Employee::whereIn('status', ['active', 'probation'])->pluck('id')->toArray();
            }
            
            $employees = Employee::whereIn('id', $employeeIds)->get();
            $totalOfficial = 0;
            $totalActual   = 0;

            // 1. Daily logs
            $allDailyLogs = DailyLog::whereIn('employee_id', $employeeIds)
                ->whereBetween('log_date', [$startDate, $endDate])
                ->orderBy('log_date')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'vehicle_id', 'shift_valid', 'online_hours', 'ontime_rate', 'avg_delivery_time'])
                ->groupBy('employee_id');

            $violationSums = Violation::whereIn('employee_id', $employeeIds)
                ->where('is_driver_liable', true)
                ->where(function($q) use ($slipIds) {
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
                    $existingSlip
                );

                $totalBonuses     = $data['orders_bonus'] + $data['fuel_allowance'] + $data['total_capacity_incentive'] + $data['total_experience_incentive'] + $data['total_contract_bonuses'];
                $totalGrossActual = $data['base_actual'] + $totalBonuses;
                $totalDeductions  = $data['violations_deduction'] + $data['maintenance_deduction'] + $data['custody_deduction'] + $data['leave_deduction'] + $data['advance_deduction'];
                $netActual        = max(0, $totalGrossActual - $totalDeductions);
                $netBank          = min($netActual, (float) $employee->official_salary);
                $netCash          = max(0, $netActual - $netBank);

                $slip = PayrollSlip::updateOrCreate([
                    'payroll_run_id' => $run->id,
                    'employee_id'    => $employee->id,
                ], [
                    'base_official'              => $employee->official_salary,
                    'base_actual'                => $data['base_actual'],
                    'orders_bonus'               => $data['orders_bonus'],
                    'fuel_allowance'             => $data['fuel_allowance'],
                    'total_orders'               => $data['total_orders'],
                    'violations_deduction'       => $data['violations_deduction'],
                    'maintenance_deduction'      => $data['maintenance_deduction'],
                    'custody_deduction'          => $data['custody_deduction'],
                    'advance_deduction'          => $data['advance_deduction'],
                    'leave_deduction'            => $data['leave_deduction'],
                    'unpaid_leave_days'          => $data['unpaid_leave_days'],
                    'gross_official'             => $netBank,
                    'gross_actual'               => $netActual,
                    'cash_portion'               => $netCash,
                    'final_monthly_status'       => $data['final_monthly_status'],
                    'total_capacity_incentive'   => $data['total_capacity_incentive'],
                    'total_experience_incentive' => $data['total_experience_incentive'],
                    'total_contract_bonuses'     => $data['total_contract_bonuses'],
                ]);

                // Re-mark violations as deducted
                if ($data['violations_deduction'] > 0) {
                    Violation::where('employee_id', $employee->id)
                        ->where('is_driver_liable', true)
                        ->where('is_deducted', false)
                        ->whereDate('violation_date', '<=', $endDate)
                        ->update([
                            'is_deducted' => true,
                            'payroll_slip_id' => $slip->id
                        ]);
                }

                // Re-create advance deductions
                $activeAdvances = $allAdvances->get($employee->id, collect());
                foreach ($activeAdvances as $advance) {
                    $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
                    if ($installment <= 0) continue;

                    AdvanceDeduction::create([
                        'salary_advance_id' => $advance->id,
                        'payroll_slip_id'   => $slip->id,
                        'amount'            => $installment,
                        'deduction_date'    => $endDate,
                        'company_id'        => $advance->company_id,
                    ]);

                    $advance->paid_installments += 1;
                    $advance->remaining_balance  = max(0, (float) $advance->remaining_balance - $installment);
                    if ($advance->remaining_balance <= 0) {
                        $advance->status = 'completed';
                    }
                    $advance->saveQuietly();
                }

                $totalOfficial += $netBank;
                $totalActual   += $netActual;
            }

            PayrollSlip::where('payroll_run_id', $run->id)->whereNotIn('employee_id', $employeeIds)->delete();

            $run->update([
                'total_official' => $totalOfficial,
                'total_actual'   => $totalActual,
                'total_cash_diff'=> $totalActual - $totalOfficial,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Recalculate Run failed: ' . $e->getMessage());
        }
    }

    public static function recalculateEmployeeCommissions($employeeId, $year, $month, $preFetchedLogs = null)
    {
        if ($employeeId instanceof Employee) {
            $employee = $employeeId;
            $employeeId = $employee->id;
        } else {
            $employee = \App\Models\Employee::find($employeeId);
        }

        if (!$employee) return collect();

        $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate   = \Carbon\Carbon::parse($startDate)->endOfMonth()->toDateString();

        $logs = $preFetchedLogs ?? \App\Models\DailyLog::where('employee_id', $employeeId)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->orderBy('log_date')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id']);

        $target = (int) ($employee->target_orders_monthly ?? 0);
        $baseRate = (float) (($target > 0 && $employee->base_commission_rate !== null) ? $employee->base_commission_rate : ($employee->rate_per_order ?? 0.000));
        $premiumRate = (float) ($employee->premium_commission_rate ?? 0.000);

        $runningOrders = 0;

        foreach ($logs as $log) {
            $cOrders = (int) $log->orders_count;
            $logCommission = 0;

            // Check if log has contract or driver has active assignment on log_date
            $contractId = $log->contract_id;
            if (!$contractId) {
                $assignment = \App\Models\ContractAssignment::where('employee_id', $employeeId)
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
                $assignment = \App\Models\ContractAssignment::where('employee_id', $employeeId)
                    ->where('contract_id', $contractId)
                    ->whereDate('start_date', '<=', $log->log_date)
                    ->where(function ($q) use ($log) {
                        $q->whereNull('end_date')
                          ->orWhereDate('end_date', '>=', $log->log_date);
                    })
                    ->first();
                if ($assignment) {
                    $rate = SmartValueFallbackService::resolve($employeeId, $contractId, $log->log_date, 'order_commission');
                }
            }

            if ($rate !== null) {
                // Convert currency if contract currency is different from KWD
                $contract = \App\Models\Contract::find($contractId);
                if ($contract && $contract->currency && $contract->currency !== 'KWD') {
                    $rateModel = \App\Models\CurrencyExchangeRate::where('company_id', $employee->company_id)
                        ->where('from_currency', $contract->currency)
                        ->where('to_currency', 'KWD')
                        ->where('year', $year)
                        ->where('month', $month)
                        ->first();
                    if ($rateModel) {
                        $rate = (float)$rate * (float)$rateModel->exchange_rate;
                    }
                }
                
                $logCommission = $cOrders * (float)$rate;
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
        if (!$vehicle) return 'car';
        $text = strtolower($vehicle->make . ' ' . $vehicle->model);
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
        $existingSlip = null
    ): array {
        $employeeId = $employee->id;
        $empLogs = $allDailyLogs->get($employeeId, collect());

        // 1. Recalculate daily log commissions
        $empLogs = self::recalculateEmployeeCommissions($employee, $year, $month, $empLogs);

        $totalOrders = 0;
        $ordersBonus = 0;
        foreach ($empLogs as $log) {
            $totalOrders += (int) $log->orders_count;
            $ordersBonus += (float) $log->driver_commission;
        }

        // 2. Determine active contract assignment for currency and contract rates
        $assignment = \App\Models\ContractAssignment::where('employee_id', $employeeId)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $startDate);
            })
            ->first();

        $contractId = null;
        $paymentType = $employee->pay_type;
        $exchangeRate = 1.0;
        $contract = null;

        if ($assignment) {
            $contractId = $assignment->contract_id;
            $contract = $assignment->contract;
            if ($contract) {
                $paymentType = $contract->payment_type;
                if ($contract->currency && $contract->currency !== 'KWD') {
                    $rateModel = \App\Models\CurrencyExchangeRate::where('company_id', $employee->company_id)
                        ->where('from_currency', $contract->currency)
                        ->where('to_currency', 'KWD')
                        ->where('year', $year)
                        ->where('month', $month)
                        ->first();
                    if ($rateModel) {
                        $exchangeRate = (float)$rateModel->exchange_rate;
                    }
                }
            }
        }

        // 3. Calculate Base Actual and Adjust Orders Bonus
        $baseActual = (float)$employee->actual_salary;
        $absenceDeduction = 0.0;

        if ($contractId && $contract) {
            if ($paymentType === 'fixed' || $paymentType === 'hybrid') {
                $fixedSalary = SmartValueFallbackService::resolve($employeeId, $contractId, $endDate, 'fixed_salary');
                if ($fixedSalary === null) {
                    $fixedSalary = (float)$employee->actual_salary;
                } else {
                    $fixedSalary = (float)$fixedSalary * $exchangeRate;
                }

                $divisor = (int)(SmartValueFallbackService::resolve($employeeId, $contractId, $endDate, 'absence_divisor') ?? 26);
                $requiredValidDays = (int)(SmartValueFallbackService::resolve($employeeId, $contractId, $endDate, 'valid_days') ?? 26);
                
                // Calculate worked days (count of logs with shift_valid = 1)
                $workedDays = $empLogs->where('shift_valid', 1)->count();
                $absentDays = max(0, $requiredValidDays - $workedDays);
                $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                $absenceDeduction = $absentDays * $dailyRate;
                
                $baseActual = max(0.0, $fixedSalary - $absenceDeduction);
                if ($paymentType === 'fixed') {
                    $ordersBonus = 0.0; // Fixed contract has no orders bonus
                }
            } elseif ($paymentType === 'per_order') {
                $baseActual = $ordersBonus;
                $ordersBonus = 0.0;
            } elseif ($paymentType === 'hourly') {
                $hourlyRate = SmartValueFallbackService::resolve($employeeId, $contractId, $endDate, 'hourly_rate');
                if ($hourlyRate === null) {
                    $hourlyRate = 0.0;
                } else {
                    $hourlyRate = (float)$hourlyRate * $exchangeRate;
                }
                $totalHours = (float)$empLogs->sum('online_hours');
                $baseActual = $totalHours * $hourlyRate;
                $ordersBonus = 0.0;
            }
        } else {
            // Legacy / No contract
            if ($employee->pay_type === 'per_order') {
                $baseActual = $ordersBonus;
                $ordersBonus = 0.0;
            }
        }

        // 4. Calculate Auto-Validity (Final Monthly Status)
        $status = 'Valid';
        if ($contractId && $contract) {
            $monthlyTarget = (int)(SmartValueFallbackService::resolve($employeeId, $contractId, $endDate, 'monthly_target') ?? 0);
            $requiredValidDays = (int)(SmartValueFallbackService::resolve($employeeId, $contractId, $endDate, 'valid_days') ?? 0);
            $workedValidDays = $empLogs->where('shift_valid', 1)->count();

            $meetsOrdersTarget = ($totalOrders >= $monthlyTarget);
            $meetsValidDays = ($workedValidDays >= $requiredValidDays);

            // Check mandatory periods
            $meetsMandatoryPeriods = true;
            $monthlyParam = \App\Models\ContractMonthlyParameter::where('contract_id', $contractId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if ($monthlyParam) {
                $mandatoryPeriods = \App\Models\ContractMandatoryDay::where('contract_monthly_parameter_id', $monthlyParam->id)->get();
                foreach ($mandatoryPeriods as $period) {
                    $periodLogsCount = $empLogs->whereBetween('log_date', [$period->start_date, $period->end_date])
                        ->where('shift_valid', 1)
                        ->count();
                    if ($periodLogsCount < $period->min_required_days) {
                        $meetsMandatoryPeriods = false;
                        break;
                    }
                }
            }

            if (!$meetsOrdersTarget || !$meetsValidDays || !$meetsMandatoryPeriods) {
                $status = 'Invalid';
            }
        }

        if ($existingSlip && $existingSlip->final_monthly_status === 'protected') {
            $status = 'Protected';
        }

        // 5. Calculate Capacity and Experience Incentives
        $totalCapacityIncentive = 0.0;
        $totalExperienceIncentive = 0.0;

        if ($contractId && $contract && $status !== 'Invalid') {
            $monthlyParam = \App\Models\ContractMonthlyParameter::where('contract_id', $contractId)
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
                            $bonus = (float)($tier['bonus'] ?? 0);
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
                    $monthsTenure = \Carbon\Carbon::parse($employee->date_of_joining)->diffInMonths(\Carbon\Carbon::parse($startDate));
                    if (is_array($rules)) {
                        foreach ($rules as $tier) {
                            $minMonths = $tier['min_months'] ?? 0;
                            $bonus = (float)($tier['bonus'] ?? 0);
                            $bonusPerOrder = (float)($tier['bonus_per_order'] ?? 0);
                            if ($monthsTenure >= $minMonths) {
                                $totalExperienceIncentive = ($bonus + ($bonusPerOrder * $totalOrders)) * $exchangeRate;
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
                $totalContractBonuses += (float)$b->amount * $exchangeRate;
            }
        }

        // 7. Deductions and Allowances
        $violationsDeduction = (float) ($violationSums[$employeeId] ?? 0);
        $maintenanceDeduction = (float) ($maintenanceSums[$employeeId] ?? 0);
        $custodyDeduction = (float) ($custodySums[$employeeId] ?? 0);

        $leaveInfo = $leaveData[$employeeId] ?? null;
        $leaveDeduction = (float) ($leaveInfo?->total_deduction ?? 0);
        $unpaidLeaveDays = (int) ($leaveInfo?->total_days ?? 0);

        $advanceDeduction = 0.0;
        $activeAdvances = $allAdvances->get($employeeId, collect());
        foreach ($activeAdvances as $advance) {
            $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
            if ($installment <= 0) continue;
            $advanceDeduction += $installment;
        }

        $fuelAllowance = 0.0;
        $empAssignments = $allAssignments[$employeeId] ?? collect();
        foreach ($empAssignments as $a) {
            $fuelAllowance += (float) ($a->monthly_fuel_allowance ?? 0);
        }

        return [
            'base_actual'                => $baseActual,
            'orders_bonus'               => $ordersBonus,
            'fuel_allowance'             => $fuelAllowance,
            'total_orders'               => $totalOrders,
            'violations_deduction'       => $violationsDeduction,
            'maintenance_deduction'      => $maintenanceDeduction,
            'custody_deduction'          => $custodyDeduction,
            'advance_deduction'          => $advanceDeduction,
            'leave_deduction'            => $leaveDeduction,
            'unpaid_leave_days'          => $unpaidLeaveDays,
            'final_monthly_status'       => $status,
            'total_capacity_incentive'   => $totalCapacityIncentive,
            'total_experience_incentive' => $totalExperienceIncentive,
            'total_contract_bonuses'     => $totalContractBonuses,
        ];
    }
}
