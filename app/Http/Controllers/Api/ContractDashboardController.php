<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\VehicleAssignment;
use App\Services\ContractProfitabilityService;
use App\Services\ContractRevenueService;
use App\Services\ContractScopeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractDashboardController extends Controller
{
    /**
     * GET /api/contracts/{contract}/dashboard
     *
     * The contract's month: what it billed, what it cost and what each driver is owed — every
     * figure from the same services the reports and the payroll sheet read, so this screen can no
     * longer disagree with them. The drivers' rows are the payroll sheet's own rows; the screen
     * does not price anything itself.
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

        $month_ = ContractProfitabilityService::forContractMonth($contract, $year, $month, $this->currentCompanyId(), withSheet: true);
        $sheet = $month_['sheet'];

        $activeAssignments = ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->with(['overrides' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('start_date', '<=', $endDateStr)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $startDateStr))
            ->get();

        // The vehicle type each driver held DURING THIS MONTH, from the vehicle assignment that
        // covered it — not from whatever is assigned today. The month's own dates are the right
        // question: one of the drivers this was reported for had his assignment closed since.
        $vehicleTypeByEmployee = VehicleAssignment::withoutGlobalScopes()
            ->whereIn('employee_id', $activeAssignments->pluck('employee_id')->unique())
            ->whereDate('assigned_date', '<=', $endDateStr)
            ->where(fn ($q) => $q->whereNull('unassigned_date')->orWhereDate('unassigned_date', '>=', $startDateStr))
            ->with('vehicle:id,vehicle_type_id')
            ->orderBy('assigned_date')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->pluck('vehicle.vehicle_type_id')->filter()->unique()->values()->all());

        $dailyLogs = DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->with('vehicle')
            ->get();

        // What each driver's own orders bill the client, so the row can show earned against billed.
        $logsByDriver = $dailyLogs->groupBy('employee_id');
        $drivers = array_map(function (array $row) use ($contract, $logsByDriver) {
            $own = $logsByDriver->get($row['employee_id'], collect());
            $row['client_revenue'] = $own->isEmpty()
                ? 0.0
                : round((float) ContractRevenueService::forContractMonth($contract, $own)['revenue'], 3);

            return $row;
        }, $sheet['drivers'] ?? []);

        $loggedVehicles = $dailyLogs->pluck('vehicle_id')->filter()->unique()->count();
        $activeDriversCount = $activeAssignments->unique('employee_id')->count();

        // One driver per vehicle: the contract's vehicle count is the head-count it needs.
        $required = (int) ($contract->required_vehicles_count ?? 0);
        $driverDeficit = max(0, $required - $activeDriversCount);
        $vehicleDeficit = max(0, $required - $loggedVehicles);

        $expectedRevenue = (float) ($contract->expected_monthly_revenue ?? 0);
        $expectedExpenses = (float) ($contract->expected_monthly_expenses ?? 0);
        $targetProfitMargin = (float) ($contract->target_profit_margin ?? 0);
        $expectedProfit = $expectedRevenue - $expectedExpenses;

        $actualRevenue = $month_['revenue'];
        $totalExpenses = $month_['expenses'];
        $actualProfit = $month_['profit'];
        $actualProfitMargin = $month_['margin'];

        $pendingCashTotal = (float) DailyLog::where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDateStr, $endDateStr])
            ->sum('cash_pending');

        $alerts = [];

        if ($actualRevenue > 0 && $actualProfitMargin < $targetProfitMargin) {
            $alerts[] = [
                'type' => 'low_margin',
                'severity' => 'danger',
                'message' => sprintf('هامش الربح الفعلي (%.2f%%) أقل من الهامش المستهدف (%.2f%%).', $actualProfitMargin, $targetProfitMargin),
            ];
        }

        if ($driverDeficit > 0) {
            $alerts[] = [
                'type' => 'driver_deficit',
                'severity' => 'warning',
                'message' => "عجز في عدد السائقين المعينين: مطلوب {$required}، معين حالياً {$activeDriversCount} (العجز: {$driverDeficit}).",
            ];
        }

        if ($vehicleDeficit > 0) {
            $alerts[] = [
                'type' => 'vehicle_deficit',
                'severity' => 'warning',
                'message' => "عجز في عدد السيارات النشطة: مطلوب {$required}، نشط حالياً {$loggedVehicles} (العجز: {$vehicleDeficit}).",
            ];
        }

        if ($month_['accidents_count'] > 1) {
            $alerts[] = [
                'type' => 'high_accidents',
                'severity' => 'danger',
                'message' => "تنبيه: تم تسجيل {$month_['accidents_count']} حوادث مرورية لهذا العقد خلال هذا الشهر بتكلفة للشركة بلغت ".number_format($month_['maintenance_cost'], 3).' د.ك.',
            ];
        }

        if ($month_['unpriced_orders'] > 0) {
            $alerts[] = [
                'type' => 'unpriced_orders',
                'severity' => 'warning',
                'message' => "{$month_['unpriced_orders']} طلب لا تنطبق عليه أي قاعدة تسعير للعميل — لا يُفوتر شيء عنها حتى يكتمل التسعير.",
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
                'default_required_work_days' => (int) ($contract->default_required_work_days ?? 0),
                'required_vehicles_count' => $required,
            ],
            'assignments' => $activeAssignments,
            // employee_id => [vehicle type ids held during this month]
            'vehicle_types_by_employee' => $vehicleTypeByEmployee,
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
                    'revenue' => round($actualRevenue - $expectedRevenue, 3),
                    'profit' => round($actualProfit - $expectedProfit, 3),
                ],
            ],
            'revenue' => [
                'orders' => $month_['orders'],
                'unpriced_orders' => $month_['unpriced_orders'],
                'details' => $month_['revenue_details'],
            ],
            'direct_expenses' => [
                'total' => $month_['direct_expenses'],
                'driver_commissions' => $month_['driver_commissions'],
                'driver_salaries' => $month_['driver_salaries'],
                'vehicle_expenses' => $month_['vehicle_costs'],
                'fuel_allowance' => $month_['fuel_allowance'],
                'accidents_cost' => $month_['maintenance_cost'],
                'violations_cost' => $month_['violations_cost'],
            ],
            'indirect_expenses' => [
                'total' => $month_['indirect_expenses'],
                'supervisors' => $month_['supervisors'],
            ],
            'operational' => [
                'drivers' => [
                    'required' => $required,
                    'active' => $activeDriversCount,
                    'deficit' => $driverDeficit,
                ],
                'vehicles' => [
                    'required' => $required,
                    'active' => $loggedVehicles,
                    'deficit' => $vehicleDeficit,
                ],
                'accidents_count' => $month_['accidents_count'],
                'pending_cash' => $pendingCashTotal,
            ],
            'sheet' => [
                'is_approved' => (bool) ($sheet['is_approved'] ?? false),
                'summary' => $sheet['summary'] ?? [],
                'approval_blockers' => $sheet['approval_blockers'] ?? [],
            ],
            'drivers' => array_values($drivers),
            'alerts' => $alerts,
        ]);
    }
}
