<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\Employee;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\CashSettlement;
use App\Services\ContractRevenueService;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/expiry-alerts
     * Detailed near-expiry items for dashboard notification center.
     * Returns per-item details with severity levels.
     */
    public function expiryAlerts(): JsonResponse
    {
        $today     = Carbon::today();
        $alertDate = $today->copy()->addDays(60)->toDateString();
        $todayStr  = $today->toDateString();

        // ── Vehicle document alerts ────────────────────────────────────
        $vehicleDocFields = [
            'insurance_expiry'                => 'تأمين السيارة',
            'comprehensive_insurance_expiry'  => 'تأمين شامل',
            'food_authority_license_expiry'   => 'رخصة هيئة الغذاء',
            'next_service_due'                => 'صيانة دورية',
        ];

        $vehicles = Vehicle::select('id', 'plate_number', 'make', 'model',
                'insurance_expiry', 'comprehensive_insurance_expiry',
                'food_authority_license_expiry', 'next_service_due',
                'odometer_km', 'last_oil_change_km', 'oil_change_interval_km')
            ->where(function ($q) use ($alertDate) {
                $q->where('insurance_expiry', '<=', $alertDate)
                  ->orWhereNull('insurance_expiry')
                  ->orWhere('comprehensive_insurance_expiry', '<=', $alertDate)
                  ->orWhereNull('comprehensive_insurance_expiry')
                  ->orWhere('food_authority_license_expiry', '<=', $alertDate)
                  ->orWhereNull('food_authority_license_expiry')
                  ->orWhere('next_service_due', '<=', $alertDate)
                  ->orWhereNull('next_service_due')
                  ->orWhereRaw('odometer_km - COALESCE(last_oil_change_km, 0) >= COALESCE(oil_change_interval_km, 4000)');
            })
            ->get();

        $vehicleAlerts = [];
        foreach ($vehicles as $v) {
            foreach ($vehicleDocFields as $field => $label) {
                if (!$v->$field) {
                    $vehicleAlerts[] = [
                        'doc_label'      => "وثيقة مفقودة: {$label}",
                        'expiry_date'    => "غير مدخلة",
                        'days_remaining' => -999, // Sorts missing docs at the top
                        'severity'       => 'expired',
                        'entity_type'    => 'vehicle',
                        'entity_id'      => $v->id,
                        'entity_name'    => $v->plate_number,
                        'entity_label'   => trim("{$v->make} {$v->model}"),
                    ];
                } else {
                    $alert = $this->buildAlert($label, $v->$field, $todayStr);
                    if ($alert) {
                        $alert['entity_type'] = 'vehicle';
                        $alert['entity_id']   = $v->id;
                        $alert['entity_name'] = $v->plate_number;
                        $alert['entity_label'] = trim("{$v->make} {$v->model}");
                        $vehicleAlerts[] = $alert;
                    }
                }
            }

            // Check oil change warning
            $interval = $v->oil_change_interval_km ?? 4000;
            $odometer = $v->odometer_km ?? 0;
            $lastOil = $v->last_oil_change_km ?? 0;
            $diff = $odometer - $lastOil;
            if ($diff >= $interval) {
                $over = $diff - $interval;
                $vehicleAlerts[] = [
                    'doc_label'      => 'تحذير غيار الزيت (مستحق)',
                    'expiry_date'    => "متجاوز بـ " . number_format($over) . " كم",
                    'days_remaining' => -1,
                    'severity'       => 'expired',
                    'entity_type'    => 'vehicle',
                    'entity_id'      => $v->id,
                    'entity_name'    => $v->plate_number,
                    'entity_label'   => trim("{$v->make} {$v->model}"),
                ];
            }
        }

        // ── Employee document alerts ───────────────────────────────────
        $employeeDocFields = [
            'health_card_expiry'      => 'كرت صحي',
            'residence_expiry'        => 'إقامة',
            'driving_license_expiry'  => 'رخصة قيادة',
            'work_permit_expiry'      => 'إذن عمل',
        ];

        $employees = Employee::select('id', 'name', 'name_ar',
                'health_card_expiry', 'residence_expiry',
                'driving_license_expiry', 'work_permit_expiry')
            ->whereIn('status', ['active', 'probation'])
            ->where(function ($q) use ($alertDate) {
                $q->where('health_card_expiry', '<=', $alertDate)
                  ->orWhereNull('health_card_expiry')
                  ->orWhere('residence_expiry', '<=', $alertDate)
                  ->orWhereNull('residence_expiry')
                  ->orWhere('driving_license_expiry', '<=', $alertDate)
                  ->orWhereNull('driving_license_expiry')
                  ->orWhere('work_permit_expiry', '<=', $alertDate)
                  ->orWhereNull('work_permit_expiry');
            })
            ->get();

        $employeeAlerts = [];
        foreach ($employees as $e) {
            foreach ($employeeDocFields as $field => $label) {
                if (!$e->$field) {
                    $employeeAlerts[] = [
                        'doc_label'      => "وثيقة مفقودة: {$label}",
                        'expiry_date'    => "غير مدخلة",
                        'days_remaining' => -999, // Sorts missing docs at the top
                        'severity'       => 'expired',
                        'entity_type'    => 'employee',
                        'entity_id'      => $e->id,
                        'entity_name'    => $e->name_ar ?: $e->name,
                        'entity_label'   => $e->name,
                    ];
                } else {
                    $alert = $this->buildAlert($label, $e->$field, $todayStr);
                    if ($alert) {
                        $alert['entity_type'] = 'employee';
                        $alert['entity_id']   = $e->id;
                        $alert['entity_name'] = $e->name_ar ?: $e->name;
                        $alert['entity_label'] = $e->name;
                        $employeeAlerts[] = $alert;
                    }
                }
            }
        }

        // ── Contract expiry alerts ─────────────────────────────────────
        $contracts = Contract::select('id', 'name', 'contract_number', 'end_date')
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $alertDate)
            ->with('client:id,name')
            ->get();

        $contractAlerts = [];
        foreach ($contracts as $c) {
            $alert = $this->buildAlert('انتهاء العقد', $c->end_date, $todayStr);
            if ($alert) {
                $alert['entity_type'] = 'contract';
                $alert['entity_id']   = $c->id;
                $alert['entity_name'] = $c->name ?: $c->contract_number;
                $alert['entity_label'] = $c->client?->name ?? '—';
                $contractAlerts[] = $alert;
            }
        }

        // Sort each group by severity (expired first, then critical, then warning)
        $severityOrder = ['expired' => 0, 'critical' => 1, 'warning' => 2];
        $sortBySeverity = function ($a, $b) use ($severityOrder) {
            return ($severityOrder[$a['severity']] ?? 3) - ($severityOrder[$b['severity']] ?? 3);
        };

        usort($vehicleAlerts, $sortBySeverity);
        usort($employeeAlerts, $sortBySeverity);
        usort($contractAlerts, $sortBySeverity);

        // Compute summary counts per severity
        $allAlerts = array_merge($vehicleAlerts, $employeeAlerts, $contractAlerts);
        $expiredCount  = count(array_filter($allAlerts, fn($a) => $a['severity'] === 'expired'));
        $criticalCount = count(array_filter($allAlerts, fn($a) => $a['severity'] === 'critical'));
        $warningCount  = count(array_filter($allAlerts, fn($a) => $a['severity'] === 'warning'));

        return response()->json([
            'summary' => [
                'total'    => count($allAlerts),
                'expired'  => $expiredCount,
                'critical' => $criticalCount,
                'warning'  => $warningCount,
            ],
            'vehicles'  => $vehicleAlerts,
            'employees' => $employeeAlerts,
            'contracts' => $contractAlerts,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build a single alert array from a document expiry date.
     * Returns null if the date is beyond the 60-day window.
     */
    private function buildAlert(string $docLabel, string $expiryDate, string $today): ?array
    {
        $expiry = Carbon::parse($expiryDate);
        $now    = Carbon::parse($today);
        $days   = $now->diffInDays($expiry, false); // negative = expired

        if ($days > 60) return null; // outside alert window

        $severity = 'warning';
        if ($days < 0)       $severity = 'expired';
        elseif ($days <= 14) $severity = 'critical';

        return [
            'doc_label'      => $docLabel,
            'expiry_date'    => $expiryDate,
            'days_remaining' => $days,
            'severity'       => $severity,
        ];
    }

    /**
     * GET /api/dashboard/summary
     * Main screen: fleet status, pending cash, today's orders.
     */
    /**
     * GET /api/dashboard/money-at-risk
     * What the operation is losing or has not collected this month.
     */
    public function moneyAtRisk(\Illuminate\Http\Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $month = (int) ($request->query('month') ?: now()->month);

        return response()->json(
            \App\Services\MoneyAtRiskService::forMonth(app('current_company_id'), $year, $month)
        );
    }

    public function summary(): JsonResponse
    {
        // Fleet status breakdown — from meeting: available/working/maintenance/idle
        $fleetStatus = Vehicle::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $fleetStatus = array_merge([
            'available'   => 0,
            'working'     => 0,
            'maintenance' => 0,
            'idle'        => 0,
        ], $fleetStatus);

        // Employee status breakdown
        $employeeStatus = Employee::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Pending cash — from meeting: "الفلوس المعلقة خطيرة لأنها أمانة وليست ملك الشركة"
        $pendingCash = DailyLog::join('employees', 'employees.id', '=', 'daily_logs.employee_id')
            ->where('daily_logs.cash_pending', '>', 0)
            ->groupBy('daily_logs.employee_id', 'employees.name')
            ->selectRaw('daily_logs.employee_id, employees.name as employee_name, SUM(daily_logs.cash_pending) as total_pending')
            ->orderByDesc('total_pending')
            ->get()
            ->map(fn($row) => [
                'employee_id'   => $row->employee_id,
                'employee_name' => $row->employee_name,
                'total_pending' => (float) $row->total_pending,
            ]);

        $totalPendingCash = $pendingCash->sum('total_pending');

        // Today's activity
        $today = now()->toDateString();
        $todayStats = DailyLog::whereDate('log_date', $today)
            ->selectRaw('COUNT(*) as logs, SUM(orders_count) as total_orders, SUM(income_amount) as total_income')
            ->first();

        // Expiring documents alert counts (within 60 days)
        $alertDate = now()->addDays(60)->toDateString();
        $vehicleAlertsCount = Vehicle::where(function ($q) use ($alertDate) {
            $q->where('insurance_expiry', '<=', $alertDate)
              ->orWhere('comprehensive_insurance_expiry', '<=', $alertDate)
              ->orWhere('food_authority_license_expiry', '<=', $alertDate)
              ->orWhereRaw('odometer_km - COALESCE(last_oil_change_km, 0) >= COALESCE(oil_change_interval_km, 4000)');
        })->count();

        $employeeAlertsCount = Employee::whereIn('status', ['active', 'probation'])
            ->where(function ($q) use ($alertDate) {
                $q->where('health_card_expiry', '<=', $alertDate)
                  ->orWhereNull('health_card_expiry')
                  ->orWhere('residence_expiry', '<=', $alertDate)
                  ->orWhereNull('residence_expiry')
                  ->orWhere('driving_license_expiry', '<=', $alertDate)
                  ->orWhereNull('driving_license_expiry')
                  ->orWhere('work_permit_expiry', '<=', $alertDate)
                  ->orWhereNull('work_permit_expiry');
            })->count();

        return response()->json([
            'fleet_status'         => $fleetStatus,
            'fleet_total'          => array_sum($fleetStatus),
            'employee_status'      => $employeeStatus,
            'pending_cash' => [
                'total'   => $totalPendingCash,
                'drivers' => $pendingCash,
            ],
            'today' => [
                'date'         => $today,
                'logs_entered' => (int) ($todayStats->logs ?? 0),
                'total_orders' => (int) ($todayStats->total_orders ?? 0),
                'total_income' => (float) ($todayStats->total_income ?? 0),
            ],
            'alerts' => [
                'vehicle_docs'  => $vehicleAlertsCount,
                'employee_docs' => $employeeAlertsCount,
            ],
        ]);
    }

    /**
     * GET /api/dashboard/contracts-profitability
     * Calculate and return Expected vs Actual contract profitability with timeframe filters.
     */
    public function contractsProfitability(\Illuminate\Http\Request $request): JsonResponse
    {
        $period = $request->input('period', 'monthly');
        $year   = $request->integer('year', (int) date('Y'));
        $month  = $request->integer('month', (int) date('n'));
        $quarter = $request->integer('quarter', (int) ceil(date('n') / 3));
        $half    = $request->integer('half', (int) ceil(date('n') / 6));

        // Determine date range & months count
        if ($period === 'quarterly') {
            $startDate = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfMonth();
            $endDate   = $startDate->copy()->addMonths(2)->endOfMonth();
            $monthsCount = 3;
        } elseif ($period === 'semi-annually') {
            $startDate = Carbon::create($year, ($half - 1) * 6 + 1, 1)->startOfMonth();
            $endDate   = $startDate->copy()->addMonths(5)->endOfMonth();
            $monthsCount = 6;
        } elseif ($period === 'annually') {
            $startDate = Carbon::create($year, 1, 1)->startOfYear();
            $endDate   = $startDate->copy()->endOfYear();
            $monthsCount = 12;
        } else {
            // monthly
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate   = $startDate->copy()->endOfMonth();
            $monthsCount = 1;
        }

        $startDateStr = $startDate->toDateString();
        $endDateStr   = $endDate->toDateString();

        // 1. Fetch all contracts for the current company
        $contracts = Contract::with('client:id,name')->get();

        // 2. Fetch driver log sums in the period
        // Loop month-by-month to perform base salary allocation precisely
        $months = [];
        $temp = $startDate->copy();
        while ($temp->lte($endDate)) {
            $months[] = [
                'start' => $temp->copy()->startOfMonth()->toDateString(),
                'end' => $temp->copy()->endOfMonth()->toDateString(),
            ];
            $temp->addMonth();
        }

        $salaryAllocationsByMonth = [];
        foreach ($months as $m) {
            $monthStart = $m['start'];
            $monthEnd   = $m['end'];

            // Log counts per employee in this month
            $employeeTotalDays = DailyLog::whereBetween('log_date', [$monthStart, $monthEnd])
                ->selectRaw('employee_id, COUNT(*) as total_days')
                ->groupBy('employee_id')
                ->pluck('total_days', 'employee_id')
                ->toArray();

            // Logs count per employee per contract in this month
            $employeeContractDays = DailyLog::whereBetween('log_date', [$monthStart, $monthEnd])
                ->selectRaw('employee_id, contract_id, COUNT(*) as days')
                ->groupBy('employee_id', 'contract_id')
                ->get()
                ->groupBy('employee_id');

            // Employee salaries
            $employees = Employee::whereIn('id', array_keys($employeeTotalDays))
                ->pluck('actual_salary', 'id')
                ->toArray();

            $salaryAllocationsByMonth[] = [
                'total_days' => $employeeTotalDays,
                'contract_days' => $employeeContractDays,
                'salaries' => $employees,
            ];
        }

        // 3. Load all vehicle expenses in the range
        // Assign expenses based on daily logs or vehicle assignments covering the date
        $vehicleExpenses = \App\Models\VehicleExpense::whereBetween('expense_date', [$startDateStr, $endDateStr])
            ->get();

        $vehicleExpenseAllocations = [];
        foreach ($vehicleExpenses as $exp) {
            $expenseDate = $exp->expense_date->toDateString();
            $vehicleId   = $exp->vehicle_id;
            $amount      = (float)$exp->amount;

            $log = DailyLog::where('vehicle_id', $vehicleId)
                ->whereDate('log_date', $expenseDate)
                ->first();

            $contractId = null;
            if ($log) {
                $contractId = $log->contract_id;
            } else {
                $assignment = \App\Models\VehicleAssignment::where('vehicle_id', $vehicleId)
                    ->whereDate('assigned_date', '<=', $expenseDate)
                    ->where(function($q) use ($expenseDate) {
                        $q->whereNull('unassigned_date')
                          ->orWhereDate('unassigned_date', '>=', $expenseDate);
                    })
                    ->first();
                if ($assignment) {
                    $contractId = $assignment->contract_id;
                }
            }

            if ($contractId) {
                $vehicleExpenseAllocations[$contractId] = ($vehicleExpenseAllocations[$contractId] ?? 0) + $amount;
            }
        }

        // 4. Calculate profitability per contract
        $data = [];
        foreach ($contracts as $contract) {
            $expectedProfit = ((float)$contract->expected_monthly_profit) * $monthsCount;

            // The stored per-log income is still the primary figure — a contract billed at a flat
            // rate_per_order fills it and it is what was actually invoiced. But that rate is
            // 0.000 on every contract that moved to client_pricing_rules, which is all fourteen
            // live ones, so the column is 0 on all 2,209 logs and this screen showed no revenue
            // at all against 1,823.398 of driver cost. When it comes to nothing, price from the
            // rules instead — that way past months are right without touching stored data, and a
            // contract that does fill the column keeps billing exactly as before.
            $logsRevenue = (float) DailyLog::where('contract_id', $contract->id)
                ->whereBetween('log_date', [$startDateStr, $endDateStr])
                ->sum('income_amount');

            $billed = ['revenue' => $logsRevenue, 'unpriced_orders' => 0, 'details' => []];
            if ($logsRevenue == 0.0) {
                $revenueLogs = DailyLog::with('vehicle:id,vehicle_type_id')
                    ->where('contract_id', $contract->id)
                    ->whereBetween('log_date', [$startDateStr, $endDateStr])
                    ->get(['id', 'orders_count', 'zone', 'notes', 'vehicle_id']);

                $billed = ContractRevenueService::forContractMonth($contract, $revenueLogs, $monthsCount);
            }

            $fixedRevenue = 0;
            if ($contract->payment_type === 'fixed' || $contract->payment_type === 'hybrid') {
                foreach ($months as $m) {
                    $mStart = Carbon::parse($m['start']);
                    $mEnd   = Carbon::parse($m['end']);
                    $contractStart = Carbon::parse($contract->start_date);
                    $contractEnd = $contract->end_date ? Carbon::parse($contract->end_date) : null;

                    if ($contractStart->lte($mEnd) && (!$contractEnd || $contractEnd->gte($mStart))) {
                        $fixedRevenue += (float)$contract->fixed_monthly;
                    }
                }
            }
            $logsRevenue = $billed['revenue'];
            $actualRevenue = $logsRevenue + $fixedRevenue;

            $driverCommissions = (float) DailyLog::where('contract_id', $contract->id)
                ->whereBetween('log_date', [$startDateStr, $endDateStr])
                ->sum('driver_commission');

            $allocatedSalaries = 0;
            foreach ($salaryAllocationsByMonth as $alloc) {
                foreach ($alloc['total_days'] as $empId => $totalDays) {
                    if ($totalDays <= 0) continue;
                    $empContractLogs = $alloc['contract_days']->get($empId);
                    if (!$empContractLogs) continue;

                    $cLog = $empContractLogs->firstWhere('contract_id', $contract->id);
                    if ($cLog) {
                        $daysOnContract = $cLog->days;
                        $salary = (float)($alloc['salaries'][$empId] ?? 0);
                        $allocatedSalaries += $salary * ($daysOnContract / $totalDays);
                    }
                }
            }

            $vehicleCosts = (float)($vehicleExpenseAllocations[$contract->id] ?? 0);

            $actualExpenses = $driverCommissions + $allocatedSalaries + $vehicleCosts;
            $actualProfit   = $actualRevenue - $actualExpenses;
            $variance       = $actualProfit - $expectedProfit;

            $data[] = [
                'id' => $contract->id,
                'name' => $contract->name,
                'contract_number' => $contract->contract_number,
                'client_name' => $contract->client?->name ?? '—',
                'payment_type' => $contract->payment_type,
                'expected_monthly_profit' => (float)$contract->expected_monthly_profit,
                'expected_profit' => $expectedProfit,
                'actual_revenue' => $actualRevenue,
                // Orders the client rules could not price. Without this the shortfall looks like
                // a quiet month rather than a pricing rule that needs filling in.
                'unpriced_orders' => $billed['unpriced_orders'],
                'revenue_details' => $billed['details'],
                'actual_expenses' => $actualExpenses,
                'actual_profit' => $actualProfit,
                'variance' => $variance,
                'driver_commissions' => $driverCommissions,
                'allocated_salaries' => $allocatedSalaries,
                'vehicle_costs' => $vehicleCosts,
            ];
        }

        // Filter out contracts that don't have expected profit and haven't active transactions in timeframe to keep it clean (optional)
        // But for transparency we show all contracts.

        $sortedByProfit = $data;
        usort($sortedByProfit, fn($a, $b) => $b['actual_profit'] <=> $a['actual_profit']);

        $bestContracts = array_slice($sortedByProfit, 0, 5);
        $worstContracts = array_slice(array_reverse($sortedByProfit), 0, 5);

        return response()->json([
            'period' => $period,
            'year' => $year,
            'month' => $month,
            'quarter' => $quarter,
            'half' => $half,
            'start_date' => $startDateStr,
            'end_date' => $endDateStr,
            'months_count' => $monthsCount,
            'contracts' => $data,
            'best_contracts' => $bestContracts,
            'worst_contracts' => $worstContracts,
        ]);
    }
}
