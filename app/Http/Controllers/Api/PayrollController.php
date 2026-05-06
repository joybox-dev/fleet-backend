<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ErpSync;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use App\Models\Employee;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\CustodyItem;
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
            return response()->json(['message' => "Payroll for {$year}/{$month} already exists."], 422);
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
            $totalOfficial = 0;
            $totalActual   = 0;

            foreach ($employees as $employee) {
                // Orders this month
                $logs = DailyLog::where('employee_id', $employee->id)
                    ->whereBetween('log_date', [$startDate, $endDate])
                    ->selectRaw('SUM(orders_count) as total_orders, SUM(income_amount) as total_income')
                    ->first();

                $totalOrders = (int) ($logs->total_orders ?? 0);
                // Company revenue (for reporting): orders × contract rate
                $companyRevenue = (float) ($logs->total_income ?? 0);
                // Employee bonus: orders × employee's personal rate_per_order
                $ordersBonus = round($totalOrders * (float)($employee->rate_per_order ?? 0), 3);

                // Deductions for this month
                $violationsDeduction = Violation::where('employee_id', $employee->id)
                    ->where('is_driver_liable', true)
                    ->where('is_deducted', false)
                    ->sum('amount');

                $maintenanceDeduction = MaintenanceRecord::where('liable_employee_id', $employee->id)
                    ->where('status', 'approved')
                    ->sum('driver_deduction');

                $custodyDeduction = CustodyItem::where('employee_id', $employee->id)
                    ->where('is_returned', true)
                    ->whereIn('return_condition', ['damaged', 'lost'])
                    ->where('deduction_amount', '>', 0)
                    ->sum('deduction_amount');

                $fuelAllowance = $employee->vehicleAssignments()
                    ->whereDate('assigned_date', '<=', $endDate)
                    ->where(fn($q) => $q->whereNull('unassigned_date')->orWhereDate('unassigned_date', '>=', $startDate))
                    ->with('vehicle:id,monthly_fuel_allowance')
                    ->get()
                    ->sum(fn($a) => $a->vehicle?->monthly_fuel_allowance ?? 0);

                // Calculate pay based on type
                $baseOfficial = $employee->official_salary;
                $baseActual   = $employee->actual_salary;

                if ($employee->pay_type === 'per_order') {
                    // Per-order employee: their income IS their orders × their rate
                    // official_salary is just the bank minimum (e.g. 100 KWD)
                    $baseActual  = $ordersBonus; // e.g. 350 × 0.500 = 175
                    $ordersBonus = 0;            // Already included in baseActual
                }

                $grossOfficial = $baseOfficial + $fuelAllowance - $violationsDeduction - $maintenanceDeduction - $custodyDeduction;
                $grossActual   = $baseActual + $ordersBonus + $fuelAllowance - $violationsDeduction - $maintenanceDeduction - $custodyDeduction;
                $cashPortion   = max(0, $grossActual - $grossOfficial);

                $slip = PayrollSlip::create([
                    'payroll_run_id'        => $run->id,
                    'employee_id'           => $employee->id,
                    'base_official'         => $baseOfficial,
                    'base_actual'           => $baseActual,
                    'orders_bonus'          => $ordersBonus,
                    'fuel_allowance'        => $fuelAllowance,
                    'total_orders'          => $totalOrders,
                    'violations_deduction'  => $violationsDeduction,
                    'maintenance_deduction' => $maintenanceDeduction,
                    'custody_deduction'     => $custodyDeduction,
                    'gross_official'        => max(0, $grossOfficial),
                    'gross_actual'          => max(0, $grossActual),
                    'cash_portion'          => $cashPortion,
                ]);

                // Mark violations as deducted
                Violation::where('employee_id', $employee->id)
                    ->where('is_driver_liable', true)
                    ->where('is_deducted', false)
                    ->update(['is_deducted' => true, 'payroll_slip_id' => $slip->id]);

                $totalOfficial += max(0, $grossOfficial);
                $totalActual   += max(0, $grossActual);
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
            return response()->json(['message' => 'Payroll generation failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message'        => "Payroll for {$year}/{$month} generated successfully.",
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
                'deductions' => $slip->violations_deduction + $slip->maintenance_deduction + $slip->custody_deduction,
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
                'gross'                 => $slip->gross_actual,
                'bank_portion'          => $slip->gross_official,
                'cash_portion'          => $slip->cash_portion,
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
            return response()->json(['message' => 'Only draft payroll can be approved.'], 422);
        }

        $run->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json(['message' => "Payroll {$year}/{$month} approved."]);
    }
}
