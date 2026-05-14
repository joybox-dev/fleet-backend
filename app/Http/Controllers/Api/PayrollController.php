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

        // Prevent duplicate runs
        if (PayrollRun::where('year', $year)->where('month', $month)->exists()) {
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

            // ══════════════════════════════════════════════════════════════
            // BATCH PRE-FETCH: All data in ~10 queries instead of N×8
            // ══════════════════════════════════════════════════════════════

            // 1. Daily log aggregates per employee
            $logAggregates = DailyLog::whereIn('employee_id', $employeeIds)
                ->whereBetween('log_date', [$startDate, $endDate])
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(orders_count) as total_orders, SUM(income_amount) as total_income')
                ->get()
                ->keyBy('employee_id');

            // 2. Violations (driver-liable, not yet deducted)
            $violationSums = Violation::whereIn('employee_id', $employeeIds)
                ->where('is_driver_liable', true)
                ->where('is_deducted', false)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(amount) as total')
                ->pluck('total', 'employee_id');

            // 3. Maintenance deductions
            $maintenanceSums = MaintenanceRecord::whereIn('liable_employee_id', $employeeIds)
                ->where('status', 'approved')
                ->groupBy('liable_employee_id')
                ->selectRaw('liable_employee_id, SUM(driver_deduction) as total')
                ->pluck('total', 'liable_employee_id');

            // 4. Custody deductions
            $custodySums = CustodyItem::whereIn('employee_id', $employeeIds)
                ->where('status', 'returned')
                ->whereIn('return_condition', ['damaged', 'lost'])
                ->where('deduction_amount', '>', 0)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(deduction_amount) as total')
                ->pluck('total', 'employee_id');

            // 5. Leave deductions (approved, unpaid, overlapping this period)
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

            // 6. Active salary advances (need individual records for installment tracking)
            $allAdvances = SalaryAdvance::whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
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

            // ══════════════════════════════════════════════════════════════
            // LOOP: Now uses in-memory lookups only — ZERO queries inside
            // ══════════════════════════════════════════════════════════════

            foreach ($employees as $employee) {
                $empId = $employee->id;

                // Look up pre-fetched data
                $logAgg = $logAggregates[$empId] ?? null;
                $totalOrders = (int) ($logAgg?->total_orders ?? 0);
                $companyRevenue = (float) ($logAgg?->total_income ?? 0);
                $ordersBonus = round($totalOrders * (float)($employee->rate_per_order ?? 0), 3);

                $violationsDeduction  = (float) ($violationSums[$empId] ?? 0);
                $maintenanceDeduction = (float) ($maintenanceSums[$empId] ?? 0);
                $custodyDeduction     = (float) ($custodySums[$empId] ?? 0);

                $leaveInfo = $leaveData[$empId] ?? null;
                $leaveDeduction  = (float) ($leaveInfo?->total_deduction ?? 0);
                $unpaidLeaveDays = (int)   ($leaveInfo?->total_days ?? 0);

                // Salary advance installments
                $advanceDeduction = 0;
                $activeAdvances = $allAdvances[$empId] ?? collect();
                foreach ($activeAdvances as $advance) {
                    $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
                    if ($installment <= 0) continue;
                    $advanceDeduction += $installment;
                }

                // Fuel allowance from vehicle assignments
                $fuelAllowance = 0;
                $empAssignments = $allAssignments[$empId] ?? collect();
                foreach ($empAssignments as $a) {
                    $fuelAllowance += (float) ($a->monthly_fuel_allowance ?? 0);
                }

                // ═══════════════════════════════════════════════════════════
                // DUAL-PAYROLL CALCULATION — 5-STEP PROTECTED FORMULA
                // Bank salary (official_salary) is STRICTLY PROTECTED.
                // Deductions are absorbed by the cash portion first.
                // ═══════════════════════════════════════════════════════════

                $baseActual = (float) $employee->actual_salary;

                if ($employee->pay_type === 'per_order') {
                    // Per-order: income IS orders × their personal rate
                    // actual_salary is 0, so their base is their order earnings
                    $baseActual  = $ordersBonus; // e.g. 350 × 0.500 = 175
                    $ordersBonus = 0;            // Already folded into baseActual
                }

                // ── Step 1: Total Gross Actual (internal full pay before deductions) ──
                $totalBonuses    = $ordersBonus + $fuelAllowance;
                $totalGrossActual = $baseActual + $totalBonuses;

                // ── Step 2: Total Deductions (violations + maintenance + custody + leave + advances) ──
                $totalDeductions = $violationsDeduction + $maintenanceDeduction + $custodyDeduction + $leaveDeduction + $advanceDeduction;

                // ── Step 3: Net Actual (what the employee truly deserves) ──
                $netActual = max(0, $totalGrossActual - $totalDeductions);

                // ── Step 4: Net Bank — PROTECT official salary! ──
                // Give full official salary UNLESS deductions are so extreme
                // that net_actual itself is less than the official salary.
                $netBank = min($netActual, (float) $employee->official_salary);

                // ── Step 5: Net Cash (remainder = cash envelope) ──
                // Cash absorbs the deductions. This is the internal portion.
                $netCash = max(0, $netActual - $netBank);

                $slip = PayrollSlip::create([
                    'payroll_run_id'        => $run->id,
                    'employee_id'           => $employee->id,
                    'base_official'         => $employee->official_salary,
                    'base_actual'           => $baseActual,
                    'orders_bonus'          => $ordersBonus,
                    'fuel_allowance'        => $fuelAllowance,
                    'total_orders'          => $totalOrders,
                    'violations_deduction'  => $violationsDeduction,
                    'maintenance_deduction' => $maintenanceDeduction,
                    'custody_deduction'     => $custodyDeduction,
                    'advance_deduction'     => $advanceDeduction,
                    'leave_deduction'       => $leaveDeduction,
                    'unpaid_leave_days'     => $unpaidLeaveDays,
                    'gross_official'        => $netBank,     // Bank receives this
                    'gross_actual'          => $netActual,   // True net pay
                    'cash_portion'          => $netCash,     // Cash envelope
                ]);

                // Mark violations as deducted
                Violation::where('employee_id', $employee->id)
                    ->where('is_driver_liable', true)
                    ->where('is_deducted', false)
                    ->update(['is_deducted' => true, 'payroll_slip_id' => $slip->id]);

                // ── Create AdvanceDeduction records & update advances ──
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
                    $advance->save();
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
     * Get payroll run with all slips.
     */
    public function show(int $year, int $month): JsonResponse
    {
        $run = PayrollRun::where('year', $year)->where('month', $month)
            ->with(['slips.employee:id,name,official_salary,actual_salary', 'createdBy:id,name', 'approvedBy:id,name'])
            ->firstOrFail();

        return response()->json($run);
    }

    /**
     * GET /api/payroll/{year}/{month}/{employee}
     * Individual employee payroll slip — shows both official and internal.
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
            'official_sheet' => [   // Bank / Ministry sheet
                'base'       => $slip->base_official,
                'fuel'       => $slip->fuel_allowance,
                'deductions' => $slip->violations_deduction + $slip->maintenance_deduction + $slip->custody_deduction + $slip->advance_deduction + $slip->leave_deduction,
                'net'        => $slip->gross_official,
            ],
            'internal_sheet' => [   // Full internal breakdown
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
            ],
        ]);
    }

    /**
     * POST /api/payroll/{year}/{month}/approve
     *
     * Approves the payroll batch and dispatches ERPNext Journal Entries:
     * 1. Deductions recovery (violations + maintenance + custody)
     * 2. Fuel expense (consolidated fuel allowance)
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

        // ── Aggregate from all slips in this batch ──
        $slips = PayrollSlip::where('payroll_run_id', $run->id)->get();

        $totalViolations  = round($slips->sum('violations_deduction'), 3);
        $totalMaintenance = round($slips->sum('maintenance_deduction'), 3);
        $totalCustody     = round($slips->sum('custody_deduction'), 3);
        $totalAdvances    = round($slips->sum('advance_deduction'), 3);
        $totalDeductions  = $totalViolations + $totalMaintenance + $totalCustody + $totalAdvances;
        $totalFuel        = round($slips->sum('fuel_allowance'), 3);

        $paddedMonth = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        // ── 1. Dispatch deductions recovery Journal Entry ──
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

        // ── 2. Dispatch fuel expense Journal Entry ──
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
}
