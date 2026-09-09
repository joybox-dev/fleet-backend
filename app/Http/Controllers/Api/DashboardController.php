<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Services\ContractProfitabilityService;
use App\Services\MoneyAtRiskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/expiry-alerts
     * Detailed near-expiry items for dashboard notification center.
     * Returns per-item details with severity levels.
     */
    public function expiryAlerts(): JsonResponse
    {
        $today = Carbon::today();
        $alertDate = $today->copy()->addDays(60)->toDateString();
        $todayStr = $today->toDateString();

        // ── Vehicle document alerts ────────────────────────────────────
        $vehicleDocFields = [
            'insurance_expiry' => 'تأمين السيارة',
            'comprehensive_insurance_expiry' => 'تأمين شامل',
            'food_authority_license_expiry' => 'رخصة هيئة الغذاء',
            'next_service_due' => 'صيانة دورية',
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
                if (! $v->$field) {
                    $vehicleAlerts[] = [
                        'doc_label' => "وثيقة مفقودة: {$label}",
                        'expiry_date' => 'غير مدخلة',
                        'days_remaining' => -999, // Sorts missing docs at the top
                        'severity' => 'missing',
                        'entity_type' => 'vehicle',
                        'entity_id' => $v->id,
                        'entity_name' => $v->plate_number,
                        'entity_label' => trim("{$v->make} {$v->model}"),
                    ];
                } else {
                    $alert = $this->buildAlert($label, $v->$field, $todayStr);
                    if ($alert) {
                        $alert['entity_type'] = 'vehicle';
                        $alert['entity_id'] = $v->id;
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
                    'doc_label' => 'تحذير غيار الزيت (مستحق)',
                    'expiry_date' => 'متجاوز بـ '.number_format($over).' كم',
                    'days_remaining' => -1,
                    'severity' => 'expired',
                    'entity_type' => 'vehicle',
                    'entity_id' => $v->id,
                    'entity_name' => $v->plate_number,
                    'entity_label' => trim("{$v->make} {$v->model}"),
                ];
            }
        }

        // ── Employee document alerts ───────────────────────────────────
        $employeeDocFields = [
            'health_card_expiry' => 'كرت صحي',
            'residence_expiry' => 'إقامة',
            'driving_license_expiry' => 'رخصة قيادة',
            'work_permit_expiry' => 'إذن عمل',
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
                if (! $e->$field) {
                    $employeeAlerts[] = [
                        'doc_label' => "وثيقة مفقودة: {$label}",
                        'expiry_date' => 'غير مدخلة',
                        'days_remaining' => -999, // Sorts missing docs at the top
                        'severity' => 'missing',
                        'entity_type' => 'employee',
                        'entity_id' => $e->id,
                        'entity_name' => $e->name_ar ?: $e->name,
                        'entity_label' => $e->name,
                    ];
                } else {
                    $alert = $this->buildAlert($label, $e->$field, $todayStr);
                    if ($alert) {
                        $alert['entity_type'] = 'employee';
                        $alert['entity_id'] = $e->id;
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
                $alert['entity_id'] = $c->id;
                $alert['entity_name'] = $c->name ?: $c->contract_number;
                $alert['entity_label'] = $c->client?->name ?? '—';
                $contractAlerts[] = $alert;
            }
        }

        // Sort each group by severity (expired first, then critical, then warning)
        // A lapsed document is more urgent than one that was never entered.
        $severityOrder = ['expired' => 0, 'critical' => 1, 'warning' => 2, 'missing' => 3];
        $sortBySeverity = function ($a, $b) use ($severityOrder) {
            return ($severityOrder[$a['severity']] ?? 3) - ($severityOrder[$b['severity']] ?? 3);
        };

        usort($vehicleAlerts, $sortBySeverity);
        usort($employeeAlerts, $sortBySeverity);
        usort($contractAlerts, $sortBySeverity);

        // A document that was never entered is a gap in the records, not a lapsed permit, and each
        // needs a different action; they are counted apart.
        $allAlerts = array_merge($vehicleAlerts, $employeeAlerts, $contractAlerts);
        $count = fn (string $severity) => count(array_filter($allAlerts, fn ($a) => $a['severity'] === $severity));

        return response()->json([
            'summary' => [
                'total' => count($allAlerts),
                'expired' => $count('expired'),
                'critical' => $count('critical'),
                'warning' => $count('warning'),
                'missing' => $count('missing'),
            ],
            'vehicles' => $vehicleAlerts,
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
        $now = Carbon::parse($today);
        $days = $now->diffInDays($expiry, false); // negative = expired

        if ($days > 60) {
            return null;
        } // outside alert window

        $severity = 'warning';
        if ($days < 0) {
            $severity = 'expired';
        } elseif ($days <= 14) {
            $severity = 'critical';
        }

        return [
            'doc_label' => $docLabel,
            'expiry_date' => $expiryDate,
            'days_remaining' => $days,
            'severity' => $severity,
        ];
    }

    /**
     * GET /api/dashboard/money-at-risk
     * What the operation is losing or has not collected this month.
     */
    public function moneyAtRisk(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $month = (int) ($request->query('month') ?: now()->month);

        return response()->json(
            MoneyAtRiskService::forMonth($this->currentCompanyId(), $year, $month)
        );
    }

    /**
     * GET /api/dashboard/summary
     * Main screen: fleet status, pending cash, today's orders.
     */
    public function summary(): JsonResponse
    {
        // Fleet status breakdown — from meeting: available/working/maintenance/idle
        $fleetStatus = Vehicle::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $fleetStatus = array_merge([
            'available' => 0,
            'working' => 0,
            'maintenance' => 0,
            'idle' => 0,
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
            ->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'employee_name' => $row->employee_name,
                'total_pending' => (float) $row->total_pending,
            ]);

        $totalPendingCash = $pendingCash->sum('total_pending');

        // Today's activity
        $today = now()->toDateString();
        $todayStats = DailyLog::whereDate('log_date', $today)
            ->selectRaw('COUNT(*) as logs, SUM(orders_count) as total_orders')
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
            'fleet_status' => $fleetStatus,
            'fleet_total' => array_sum($fleetStatus),
            'employee_status' => $employeeStatus,
            'pending_cash' => [
                'total' => $totalPendingCash,
                'drivers' => $pendingCash,
            ],
            'today' => [
                'date' => $today,
                'logs_entered' => (int) ($todayStats->logs ?? 0),
                'total_orders' => (int) ($todayStats->total_orders ?? 0),
            ],
            'alerts' => [
                'vehicle_docs' => $vehicleAlertsCount,
                'employee_docs' => $employeeAlertsCount,
            ],
        ]);
    }

    /**
     * GET /api/dashboard/contracts-profitability
     *
     * Expected against actual profit per contract over a month, a quarter, a half or a year. The
     * month figures come from ContractProfitabilityService — the same ones the contract's own
     * dashboard and the reports show — summed over the months of the period.
     */
    public function contractsProfitability(Request $request): JsonResponse
    {
        $period = $request->input('period', 'monthly');
        $year = $request->integer('year', (int) date('Y'));
        $month = $request->integer('month', (int) date('n'));
        $quarter = $request->integer('quarter', (int) ceil(date('n') / 3));
        $half = $request->integer('half', (int) ceil(date('n') / 6));

        if ($period === 'quarterly') {
            $startDate = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfMonth();
            $endDate = $startDate->copy()->addMonths(2)->endOfMonth();
        } elseif ($period === 'semi-annually') {
            $startDate = Carbon::create($year, ($half - 1) * 6 + 1, 1)->startOfMonth();
            $endDate = $startDate->copy()->addMonths(5)->endOfMonth();
        } elseif ($period === 'annually') {
            $startDate = Carbon::create($year, 1, 1)->startOfYear();
            $endDate = $startDate->copy()->endOfYear();
        } else {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        }

        $months = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $months[] = [(int) $cursor->year, (int) $cursor->month];
            $cursor->addMonth();
        }
        $monthsCount = count($months);

        $companyId = $this->currentCompanyId();
        $contracts = Contract::with('client:id,name')->get();

        $sums = [];
        foreach ($months as [$y, $m]) {
            foreach (ContractProfitabilityService::forCompanyMonth($companyId, $y, $m, $contracts) as $contractId => $row) {
                foreach (['orders', 'revenue', 'unpriced_orders', 'driver_salaries', 'driver_commissions', 'driver_cost',
                    'vehicle_costs', 'maintenance_cost', 'violations_cost', 'supervisors_cost', 'expenses', 'profit'] as $key) {
                    $sums[$contractId][$key] = ($sums[$contractId][$key] ?? 0) + $row[$key];
                }
                $sums[$contractId]['revenue_details'] = array_merge($sums[$contractId]['revenue_details'] ?? [], $row['revenue_details']);
            }
        }

        $data = [];
        foreach ($contracts as $contract) {
            $sum = $sums[$contract->id] ?? [];
            // The form captures a total over the contract's life; the model derives the monthly
            // figure from it. Either is the expectation for one month.
            $expectedMonthly = (float) ($contract->expected_monthly_profit ?? $contract->expected_total_profit ?? 0);
            $expectedProfit = round($expectedMonthly * $monthsCount, 3);
            $actualProfit = round((float) ($sum['profit'] ?? 0), 3);

            $data[] = [
                'id' => $contract->id,
                'name' => $contract->name,
                'contract_number' => $contract->contract_number,
                'client_name' => $contract->client?->name ?? '—',
                'payment_type' => $contract->payment_type,
                'expected_monthly_profit' => $expectedMonthly,
                'expected_profit' => $expectedProfit,
                'total_orders' => (int) ($sum['orders'] ?? 0),
                'actual_revenue' => round((float) ($sum['revenue'] ?? 0), 3),
                // Orders the client rules could not price. Without this the shortfall looks like
                // a quiet month rather than a pricing rule that needs filling in.
                'unpriced_orders' => (int) ($sum['unpriced_orders'] ?? 0),
                'revenue_details' => $sum['revenue_details'] ?? [],
                'actual_expenses' => round((float) ($sum['expenses'] ?? 0), 3),
                'actual_profit' => $actualProfit,
                'variance' => round($actualProfit - $expectedProfit, 3),
                'driver_commissions' => round((float) ($sum['driver_commissions'] ?? 0), 3),
                'allocated_salaries' => round((float) ($sum['driver_salaries'] ?? 0), 3),
                'driver_cost' => round((float) ($sum['driver_cost'] ?? 0), 3),
                'vehicle_costs' => round((float) ($sum['vehicle_costs'] ?? 0), 3),
                'maintenance_cost' => round((float) ($sum['maintenance_cost'] ?? 0), 3),
                'violations_cost' => round((float) ($sum['violations_cost'] ?? 0), 3),
                'supervisors_cost' => round((float) ($sum['supervisors_cost'] ?? 0), 3),
            ];
        }

        return response()->json([
            'period' => $period,
            'year' => $year,
            'month' => $month,
            'quarter' => $quarter,
            'half' => $half,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'months_count' => $monthsCount,
            'contracts' => $data,
        ]);
    }
}
