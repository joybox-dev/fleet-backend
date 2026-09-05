<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\SupervisorCostAllocation;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\Violation;
use App\Services\ContractRevenueService;
use App\Services\ContractScopeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractDashboardController extends Controller
{
    /** The contract payroll sheet is the one place a driver's cost to a contract is decided. */
    public function __construct(private PayrollController $payroll) {}

    /**
     * GET /api/contracts/{contract}/dashboard
     * Retrieve detailed metrics, profitability, and alerts for a single contract.
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        $allowedIds = ContractScopeService::getAllocatedContractIds();
        if ($allowedIds !== null && ! in_array($contract->id, $allowedIds)) {
            return response()->json(['message' => 'عذراً، ليس لديك صلاحية للوصول لهذا العقد.'], 403);
        }
        $year = $request->integer('year', (int) date('Y'));
        $month = $request->integer('month', (int) date('n'));

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $startDateStr = $startDate->toDateString();
        $endDateStr = $endDate->toDateString();

        // 1. Employee Count and Deficit
        $activeAssignments = ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->with(['overrides' => function ($q) {
                $q->withoutGlobalScopes();
            }])
            ->where('start_date', '<=', $endDateStr)
            ->where(function ($q) use ($startDateStr) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDateStr);
            })
            ->get();

        $activeDriversCount = $activeAssignments->unique('employee_id')->count();
        $requiredDriversCount = 0;
        $driverDeficit = 0;

        $dailyLogs = DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->with('vehicle')
            ->get();

        // 2. Vehicles Count and Deficit
        // Get vehicles that logged activity on this contract this month
        $loggedVehicles = $dailyLogs->pluck('vehicle_id')->filter()->unique()->toArray();

        $activeVehiclesCount = count($loggedVehicles);
        $requiredVehiclesCount = $contract->required_vehicles_count ?? 0;
        $vehicleDeficit = max(0, $requiredVehiclesCount - $activeVehiclesCount);

        // 3. Expected Values
        $expectedRevenue = (float) ($contract->expected_monthly_revenue ?? 0);
        $expectedExpenses = (float) ($contract->expected_monthly_expenses ?? 0);
        $targetProfitMargin = (float) ($contract->target_profit_margin ?? 0);
        $expectedProfit = $expectedRevenue - $expectedExpenses;

        // 4. Actual Revenue
        $logsRevenue = (float) $dailyLogs->sum('income_amount');

        // Priced from client_pricing_rules through the same service the profitability screen
        // uses, so the two cannot disagree. What this replaced read the single `zone` column —
        // set on 31 of 902 August logs — and, when no zone matched, charged the client the
        // AVERAGE of the contract's zone prices: a rate nobody agreed to. It also had no tier
        // handling, so tier-priced contracts billed nothing at all.
        if ($logsRevenue == 0) {
            $billed = ContractRevenueService::forContractMonth($contract, $dailyLogs);
            $logsRevenue = $billed['revenue'];
            $unpricedOrders = $billed['unpriced_orders'];
            $revenueDetails = $billed['details'];
        }

        $fixedRevenue = 0;
        if ($contract->payment_type === 'fixed' || $contract->payment_type === 'hybrid') {
            $fixedRevenue = (float) ($contract->fixed_monthly ?? 0);
        }
        $actualRevenue = $logsRevenue + $fixedRevenue;
        $unpricedOrders = $unpricedOrders ?? 0;
        $revenueDetails = $revenueDetails ?? [];

        // 5. Direct Expenses
        // What this contract will actually pay its drivers, taken from the contract payroll sheet
        // rather than worked out a second time here.
        //
        // It used to price the month on its own: a fixed-salary driver was costed at
        // `employees.actual_salary`, which is not what the contract pays him — the contract's own
        // pricing rule is, keyed by the vehicle type he drove. A contract paying 260.000 a driver
        // through its rules therefore booked whatever happened to sit on the employee record,
        // commonly 0.000, and the profit figure was overstated by the whole payroll. The sheet is
        // the figure the drivers are paid from, so it is the figure the contract is costed at.
        $driverCommissions = 0.0;
        $driverSalariesAllocated = 0.0;

        try {
            // Built from the incoming request so the signed-in user travels with it: the sheet
            // checks permissions, and a bare Request has nobody to check.
            $sheetRequest = Request::createFrom($request);
            $sheetRequest->merge(['year' => $year, 'month' => $month]);

            $sheet = json_decode(
                $this->payroll->contractSheet($sheetRequest, $contract->id)->getContent(),
                true
            );

            foreach ($sheet['drivers'] ?? [] as $row) {
                $gross = (float) ($row['gross_contract_earnings'] ?? 0);
                $base = (float) ($row['base_salary'] ?? 0);

                // Split so the two lines on screen always add up to what is really paid: the base
                // the rule sets, and everything the month added or took off on top of it.
                $driverSalariesAllocated += $base;
                $driverCommissions += $gross - $base;
            }
        } catch (\Throwable $e) {
            \Log::warning("Contract {$contract->id} driver cost unavailable for {$month}/{$year}: ".$e->getMessage());
        }

        // 5c. Vehicle Expenses
        $vehicleExpensesAllocated = 0;
        if (! empty($loggedVehicles)) {
            $vehicleExpensesAllocated += (float) Vehicle::whereIn('id', $loggedVehicles)
                ->sum('monthly_fuel_allowance');

            $expenses = VehicleExpense::whereIn('vehicle_id', $loggedVehicles)
                ->whereBetween('expense_date', [$startDateStr, $endDateStr])
                ->get();

            foreach ($expenses as $exp) {
                $expDate = $exp->expense_date->toDateString();
                // Check if this vehicle had a log on this contract on this day
                $hasLog = DailyLog::where('vehicle_id', $exp->vehicle_id)
                    ->where('contract_id', $contract->id)
                    ->whereDate('log_date', $expDate)
                    ->exists();

                if ($hasLog) {
                    $vehicleExpensesAllocated += (float) $exp->amount;
                } else {
                    // split equally/proportionally across logged contracts on that day if any
                    $loggedContractsCount = DailyLog::where('vehicle_id', $exp->vehicle_id)
                        ->whereDate('log_date', $expDate)
                        ->distinct()
                        ->count('contract_id');

                    if ($loggedContractsCount > 0) {
                        $vehicleExpensesAllocated += (float) $exp->amount / $loggedContractsCount;
                    }
                }
            }
        }

        // 5d. Accidents cost (Company share)
        $accidentsCost = 0;
        $accidentsCount = 0;
        if (! empty($loggedVehicles)) {
            $maintenanceRecords = MaintenanceRecord::whereIn('vehicle_id', $loggedVehicles)
                ->where(function ($q) {
                    $q->where('maintenance_type', 'accident')
                        ->orWhere(function ($q2) {
                            $q2->where('maintenance_type', 'repair')
                                ->where(function ($q3) {
                                    $q3->where('notes', 'like', '%حادث%')
                                        ->orWhere('notes', 'like', '%accident%')
                                        ->orWhere('accident_description', 'like', '%حادث%')
                                        ->orWhere('accident_description', 'like', '%accident%');
                                });
                        });
                })
                ->whereBetween('maintenance_date', [$startDateStr, $endDateStr])
                ->where('status', 'approved')
                ->get();

            foreach ($maintenanceRecords as $record) {
                $companySharePercent = (float) ($record->company_bearing_percentage ?? 100);
                $actualCost = (float) ($record->actual_cost ?? $record->estimated_cost ?? 0);
                $accidentsCost += $actualCost * ($companySharePercent / 100.0);
                $accidentsCount++;
            }
        }

        // 5e. Violations cost (Company share)
        //
        // The guard here read `$driverIds`, a variable this method never defines, so it was always
        // empty and the filter was never applied: every contract charged itself the company's share
        // of EVERY fine in the month, across all contracts. Five contracts each showed 800.000 of a
        // company cost that was really 160.000 apiece.
        $driverIds = $dailyLogs->pluck('employee_id')->filter()->unique()->all();

        $violationsCost = 0;
        $violationsQuery = Violation::whereBetween('violation_date', [$startDateStr, $endDateStr])
            ->where(function ($q) use ($driverIds, $loggedVehicles) {
                $q->whereIn('employee_id', $driverIds ?: [0]);
                if (! empty($loggedVehicles)) {
                    $q->orWhereIn('vehicle_id', $loggedVehicles);
                }
            });

        $violations = $violationsQuery->get();
        foreach ($violations as $viol) {
            $totalAmount = (float) $viol->amount;
            $driverDeduction = (float) ($viol->driver_deduction ?? 0);
            $companyShare = max(0.0, $totalAmount - $driverDeduction);
            $violationsCost += $companyShare;
        }

        $totalDirectExpenses = $driverCommissions + $driverSalariesAllocated + $vehicleExpensesAllocated + $accidentsCost + $violationsCost;

        // 6. Indirect Expenses (Supervisor Cost Allocation)
        $supervisorAllocations = SupervisorCostAllocation::where('contract_id', $contract->id)
            ->where('allocation_percentage', '>', 0)
            ->with('employee')
            ->get();

        $totalIndirectExpenses = 0;
        $supervisorsDetails = [];

        foreach ($supervisorAllocations as $alloc) {
            $sup = $alloc->employee;
            if ($sup) {
                $salary = (float) ($sup->actual_salary ?? 0);
                $percent = (float) $alloc->allocation_percentage;
                $allocatedAmount = $salary * ($percent / 100.0);
                $totalIndirectExpenses += $allocatedAmount;

                $supervisorsDetails[] = [
                    'id' => $sup->id,
                    'name' => $sup->name,
                    'salary' => $salary,
                    'percentage' => $percent,
                    'allocated_amount' => $allocatedAmount,
                ];
            }
        }

        // 7. Profit and Margins
        $totalExpenses = $totalDirectExpenses + $totalIndirectExpenses;
        $actualProfit = $actualRevenue - $totalExpenses;
        $actualProfitMargin = $actualRevenue > 0 ? ($actualProfit / $actualRevenue * 100.0) : 0.0;
        $varianceRevenue = $actualRevenue - $expectedRevenue;
        $varianceProfit = $actualProfit - $expectedProfit;

        // 8. Pending Cash
        $pendingCashTotal = (float) DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->sum('cash_pending');

        // 9. Warnings and Alerts
        $alerts = [];

        if ($actualRevenue > 0 && $actualProfitMargin < $targetProfitMargin) {
            $alerts[] = [
                'type' => 'low_margin',
                'severity' => 'danger',
                'message' => sprintf(
                    'هامش الربح الفعلي (%.2f%%) أقل من الهامش المستهدف (%.2f%%).',
                    $actualProfitMargin,
                    $targetProfitMargin
                ),
            ];
        }

        if ($driverDeficit > 0) {
            $alerts[] = [
                'type' => 'driver_deficit',
                'severity' => 'warning',
                'message' => "عجز في عدد السائقين المعينين: مطلوب {$requiredDriversCount}، معين حالياً {$activeDriversCount} (العجز: {$driverDeficit}).",
            ];
        }

        if ($vehicleDeficit > 0) {
            $alerts[] = [
                'type' => 'vehicle_deficit',
                'severity' => 'warning',
                'message' => "عجز في عدد السيارات النشطة: مطلوب {$requiredVehiclesCount}، نشط حالياً {$activeVehiclesCount} (العجز: {$vehicleDeficit}).",
            ];
        }

        if ($accidentsCount > 1) {
            $alerts[] = [
                'type' => 'high_accidents',
                'severity' => 'danger',
                'message' => "تنبيه: تم تسجيل {$accidentsCount} حوادث مرورية لهذا العقد خلال هذا الشهر بتكلفة للشركة بلغت ".number_format($accidentsCost, 3).' د.ك.',
            ];
        }

        return response()->json([
            'contract' => [
                'id' => $contract->id,
                'name' => $contract->name,
                'contract_number' => $contract->contract_number,
                'client_name' => $contract->client_name,
                'currency' => $contract->currency ?? 'KWD',
                'payment_type' => $contract->payment_type,
                'driver_pricing_rules' => $contract->driver_pricing_rules,
                'client_pricing_rules' => $contract->client_pricing_rules,
                'is_validity_enabled' => $contract->is_validity_enabled,
                'driver_payment_method' => $contract->driver_payment_method,
                'client_payment_method' => $contract->client_payment_method,
                'default_required_work_days' => (int) ($contract->default_required_work_days ?? 28),
                'default_fixed_salary' => $contract->default_fixed_salary,
                'default_monthly_target' => $contract->default_monthly_target,
                'default_absence_divisor' => $contract->default_absence_divisor,
            ],
            'assignments' => $activeAssignments,
            'daily_logs' => $dailyLogs,
            'timeframe' => [
                'year' => $year,
                'month' => $month,
                'start_date' => $startDateStr,
                'end_date' => $endDateStr,
            ],
            'financials' => [
                'expected' => [
                    'revenue' => $expectedRevenue,
                    'expenses' => $expectedExpenses,
                    'profit' => $expectedProfit,
                    'margin' => $targetProfitMargin,
                ],
                'actual' => [
                    'revenue' => $actualRevenue,
                    'expenses' => $totalExpenses,
                    'profit' => $actualProfit,
                    'margin' => $actualProfitMargin,
                ],
                'variance' => [
                    'revenue' => $varianceRevenue,
                    'profit' => $varianceProfit,
                ],
            ],
            'direct_expenses' => [
                'total' => $totalDirectExpenses,
                'driver_commissions' => $driverCommissions,
                'driver_salaries' => $driverSalariesAllocated,
                'vehicle_expenses' => $vehicleExpensesAllocated,
                'accidents_cost' => $accidentsCost,
                'violations_cost' => $violationsCost,
            ],
            'indirect_expenses' => [
                'total' => $totalIndirectExpenses,
                'supervisors' => $supervisorsDetails,
            ],
            'operational' => [
                'drivers' => [
                    'required' => $requiredDriversCount,
                    'active' => $activeDriversCount,
                    'deficit' => $driverDeficit,
                ],
                'vehicles' => [
                    'required' => $requiredVehiclesCount,
                    'active' => $activeVehiclesCount,
                    'deficit' => $vehicleDeficit,
                ],
                'accidents_count' => $accidentsCount,
                'pending_cash' => $pendingCashTotal,
            ],
            'alerts' => $alerts,
        ]);
    }
}
