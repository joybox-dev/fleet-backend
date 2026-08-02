<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\SupervisorCostAllocation;
use App\Models\VehicleExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

use App\Services\ContractScopeService;

class ContractDashboardController extends Controller
{
    /**
     * GET /api/contracts/{contract}/dashboard
     * Retrieve detailed metrics, profitability, and alerts for a single contract.
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        $allowedIds = ContractScopeService::getAllocatedContractIds();
        if ($allowedIds !== null && !in_array($contract->id, $allowedIds)) {
            return response()->json(['message' => 'عذراً، ليس لديك صلاحية للوصول لهذا العقد.'], 403);
        }
        $year = $request->integer('year', (int) date('Y'));
        $month = $request->integer('month', (int) date('n'));

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        
        $startDateStr = $startDate->toDateString();
        $endDateStr = $endDate->toDateString();

        // 1. Employee Count and Deficit
        $activeAssignments = $contract->assignments()
            ->with(['overrides'])
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
            ->get();

        // 2. Vehicles Count and Deficit
        // Get vehicles that logged activity on this contract this month
        $loggedVehicles = DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->whereNotNull('vehicle_id')
            ->distinct()
            ->pluck('vehicle_id')
            ->toArray();
            
        $activeVehiclesCount = count($loggedVehicles);
        $requiredVehiclesCount = $contract->required_vehicles_count ?? 0;
        $vehicleDeficit = max(0, $requiredVehiclesCount - $activeVehiclesCount);

        // 3. Expected Values
        $expectedRevenue = (float)($contract->expected_monthly_revenue ?? 0);
        $expectedExpenses = (float)($contract->expected_monthly_expenses ?? 0);
        $targetProfitMargin = (float)($contract->target_profit_margin ?? 0);
        $expectedProfit = $expectedRevenue - $expectedExpenses;

        // 4. Actual Revenue
        $logsRevenue = (float) DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->sum('income_amount');

        $fixedRevenue = 0;
        if ($contract->payment_type === 'fixed' || $contract->payment_type === 'hybrid') {
            $fixedRevenue = (float)($contract->fixed_monthly ?? 0);
        }
        $actualRevenue = $logsRevenue + $fixedRevenue;

        // 5. Direct Expenses
        // 5a. Driver Commissions & 5b. Allocated Driver Base Salaries
        $driverCommissions = (float) DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->sum('driver_commission');

        $driverSalariesAllocated = 0.0;
        
        $contractLogsGrouped = DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->get()
            ->groupBy('employee_id');

        $loggedDriverIds = $contractLogsGrouped->keys()->toArray();
        $assignedDriverIds = $activeAssignments->pluck('employee_id')->unique()->toArray();
        $driverIds = array_unique(array_merge($loggedDriverIds, $assignedDriverIds));

        if (!empty($driverIds)) {
            $driverLogs = DailyLog::whereBetween('log_date', [$startDateStr, $endDateStr])
                ->whereIn('employee_id', $driverIds)
                ->selectRaw('employee_id, contract_id, COUNT(*) as days')
                ->groupBy('employee_id', 'contract_id')
                ->get()
                ->groupBy('employee_id');

            $driverTotalDays = DailyLog::whereBetween('log_date', [$startDateStr, $endDateStr])
                ->whereIn('employee_id', $driverIds)
                ->selectRaw('employee_id, COUNT(*) as total_days')
                ->groupBy('employee_id')
                ->pluck('total_days', 'employee_id')
                ->toArray();

            $employees = Employee::whereIn('id', $driverIds)->get();

            $allDailyLogsForEmp = DailyLog::whereIn('employee_id', $driverIds)
                ->whereBetween('log_date', [$startDateStr, $endDateStr])
                ->get()
                ->groupBy('employee_id');

            $allAssignmentsForEmp = \DB::table('vehicle_assignments')
                ->whereIn('employee_id', $driverIds)
                ->get()
                ->groupBy('employee_id');

            $calculatedCommissions = 0.0;

            foreach ($employees as $emp) {
                $totalDays = $driverTotalDays[$emp->id] ?? 0;
                $empLogs = $driverLogs->get($emp->id);
                $contractLog = $empLogs ? $empLogs->firstWhere('contract_id', $contract->id) : null;
                $daysOnContract = $contractLog ? $contractLog->days : 0;

                if ($daysOnContract <= 0 && $totalDays > 0) continue;

                $ratio = ($totalDays > 0 && $daysOnContract > 0) ? ($daysOnContract / $totalDays) : ($daysOnContract > 0 ? 1.0 : 0.0);
                if ($ratio <= 0) continue;

                // Calculate dynamic slip data for driver
                $slipData = PayrollController::calculateDriverSlipData(
                    $emp, $year, $month, $startDateStr, $endDateStr, $allDailyLogsForEmp,
                    collect(), collect(), collect(), collect(), collect(), $allAssignmentsForEmp
                );

                $driverPaymentMethod = $contract->driver_payment_method ?: ($contract->payment_type ?: 'per_order');
                if ($driverPaymentMethod === 'fixed') {
                    $driverSalariesAllocated += (float) ($emp->actual_salary ?? $emp->official_salary ?? 0) * $ratio;
                    $calculatedCommissions += (float) $slipData['orders_bonus'] * $ratio;
                } elseif ($driverPaymentMethod === 'hybrid') {
                    $driverSalariesAllocated += (float) ($slipData['base_actual']) * $ratio;
                    $calculatedCommissions += (float) $slipData['orders_bonus'] * $ratio;
                } else {
                    // Commission-based: per_order, zones, zones_tiers, tiers
                    $calculatedCommissions += (float) ($slipData['base_actual'] + $slipData['orders_bonus']) * $ratio;
                }
            }

            if ($driverCommissions == 0) {
                $driverCommissions = $calculatedCommissions;
            }
        }

        // 5c. Vehicle Expenses
        $vehicleExpensesAllocated = 0;
        if (!empty($loggedVehicles)) {
            $vehicleExpensesAllocated += (float) \App\Models\Vehicle::whereIn('id', $loggedVehicles)
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
                    $vehicleExpensesAllocated += (float)$exp->amount;
                } else {
                    // split equally/proportionally across logged contracts on that day if any
                    $loggedContractsCount = DailyLog::where('vehicle_id', $exp->vehicle_id)
                        ->whereDate('log_date', $expDate)
                        ->distinct()
                        ->count('contract_id');
                    
                    if ($loggedContractsCount > 0) {
                        $vehicleExpensesAllocated += (float)$exp->amount / $loggedContractsCount;
                    }
                }
            }
        }

        // 5d. Accidents cost (Company share)
        $accidentsCost = 0;
        $accidentsCount = 0;
        if (!empty($loggedVehicles)) {
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
                $companySharePercent = (float)($record->company_bearing_percentage ?? 100);
                $actualCost = (float)($record->actual_cost ?? $record->estimated_cost ?? 0);
                $accidentsCost += $actualCost * ($companySharePercent / 100.0);
                $accidentsCount++;
            }
        }

        // 5e. Violations cost (Company share)
        $violationsCost = 0;
        $violationsQuery = Violation::whereBetween('violation_date', [$startDateStr, $endDateStr]);
        if (!empty($driverIds)) {
            $violationsQuery->where(function ($q) use ($driverIds, $loggedVehicles) {
                $q->whereIn('employee_id', $driverIds);
                if (!empty($loggedVehicles)) {
                    $q->orWhereIn('vehicle_id', $loggedVehicles);
                }
            });
        }
        
        $violations = $violationsQuery->get();
        foreach ($violations as $viol) {
            $totalAmount = (float)$viol->amount;
            $driverDeduction = (float)($viol->driver_deduction ?? 0);
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
                $salary = (float)($sup->actual_salary ?? 0);
                $percent = (float)$alloc->allocation_percentage;
                $allocatedAmount = $salary * ($percent / 100.0);
                $totalIndirectExpenses += $allocatedAmount;

                $supervisorsDetails[] = [
                    'id' => $sup->id,
                    'name' => $sup->name,
                    'salary' => $salary,
                    'percentage' => $percent,
                    'allocated_amount' => $allocatedAmount
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
                )
            ];
        }

        if ($driverDeficit > 0) {
            $alerts[] = [
                'type' => 'driver_deficit',
                'severity' => 'warning',
                'message' => "عجز في عدد السائقين المعينين: مطلوب {$requiredDriversCount}، معين حالياً {$activeDriversCount} (العجز: {$driverDeficit})."
            ];
        }

        if ($vehicleDeficit > 0) {
            $alerts[] = [
                'type' => 'vehicle_deficit',
                'severity' => 'warning',
                'message' => "عجز في عدد السيارات النشطة: مطلوب {$requiredVehiclesCount}، نشط حالياً {$activeVehiclesCount} (العجز: {$vehicleDeficit})."
            ];
        }

        if ($accidentsCount > 1) {
            $alerts[] = [
                'type' => 'high_accidents',
                'severity' => 'danger',
                'message' => "تنبيه: تم تسجيل {$accidentsCount} حوادث مرورية لهذا العقد خلال هذا الشهر بتكلفة للشركة بلغت " . number_format($accidentsCost, 3) . " د.ك."
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
                ]
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
            'alerts' => $alerts
        ]);
    }
}
