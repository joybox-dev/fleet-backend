<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    /**
     * GET /api/reports/expiring-docs
     * Vehicles + employees with documents expiring within 60 days.
     * From meeting: red = expired, warning = approaching.
     */
    public function expiringDocs(Request $request): JsonResponse
    {
        $days      = (int) $request->get('days', 60);
        $alertDate = now()->addDays($days)->toDateString();
        $today     = now()->toDateString();

        $vehicles = Vehicle::select('id', 'plate_number', 'make', 'model',
            'insurance_expiry', 'comprehensive_insurance_expiry',
            'food_authority_license_expiry', 'next_service_due')
            ->where(function ($q) use ($alertDate) {
                $q->where('insurance_expiry', '<=', $alertDate)
                  ->orWhere('comprehensive_insurance_expiry', '<=', $alertDate)
                  ->orWhere('food_authority_license_expiry', '<=', $alertDate)
                  ->orWhere('next_service_due', '<=', $alertDate);
            })
            ->get()
            ->map(fn($v) => [
                'id'           => $v->id,
                'plate_number' => $v->plate_number,
                'label'        => "{$v->make} {$v->model}",
                'alerts'       => array_values(array_filter([
                    $this->docStatus('تأمين السيارة', $v->insurance_expiry, $today, $alertDate),
                    $this->docStatus('تأمين شامل', $v->comprehensive_insurance_expiry, $today, $alertDate),
                    $this->docStatus('رخصة هيئة الغذاء', $v->food_authority_license_expiry, $today, $alertDate),
                    $this->docStatus('صيانة دورية', $v->next_service_due, $today, $alertDate),
                ])),
            ]);

        $employees = Employee::select('id', 'name',
            'health_card_expiry', 'residence_expiry',
            'driving_license_expiry', 'work_permit_expiry')
            ->whereIn('status', ['active', 'probation'])
            ->where(function ($q) use ($alertDate) {
                $q->where('health_card_expiry', '<=', $alertDate)
                  ->orWhere('residence_expiry', '<=', $alertDate)
                  ->orWhere('driving_license_expiry', '<=', $alertDate)
                  ->orWhere('work_permit_expiry', '<=', $alertDate);
            })
            ->get()
            ->map(fn($e) => [
                'id'     => $e->id,
                'name'   => $e->name,
                'alerts' => array_values(array_filter([
                    $this->docStatus('كرت صحي', $e->health_card_expiry, $today, $alertDate),
                    $this->docStatus('إقامة', $e->residence_expiry, $today, $alertDate),
                    $this->docStatus('رخصة قيادة', $e->driving_license_expiry, $today, $alertDate),
                    $this->docStatus('إذن عمل', $e->work_permit_expiry, $today, $alertDate),
                ])),
            ]);

        return response()->json([
            'alert_window_days' => $days,
            'vehicles'          => array_values($vehicles->toArray()),
            'employees'         => array_values($employees->toArray()),
        ]);
    }

    /**
     * 3-tier doc status: expired | warning | valid
     */
    private function docStatus(string $label, ?string $expiry, string $today, string $alertDate): ?array
    {
        if (!$expiry) return null;

        if ($expiry < $today) {
            $status = 'expired';
        } elseif ($expiry <= $alertDate) {
            $status = 'warning';
        } else {
            $status = 'valid';
        }

        return ['label' => $label, 'expiry' => $expiry, 'status' => $status];
    }

    /**
     * GET /api/reports/violations?year=&month=
     */
    public function violations(Request $request): JsonResponse
    {
        $violations = Violation::with(['employee:id,name', 'vehicle:id,plate_number'])
            ->when($request->year, fn($q) => $q->whereYear('violation_date', $request->year))
            ->when($request->month, fn($q) => $q->whereMonth('violation_date', $request->month))
            ->when($request->boolean('driver_liable'), fn($q) => $q->where('is_driver_liable', true))
            ->orderByDesc('violation_date')
            ->get();

        return response()->json([
            'count'       => $violations->count(),
            'total_kwd'   => $violations->sum('amount'),
            'violations'  => $violations,
        ]);
    }

    /**
     * GET /api/reports/pending-cash
     */
    public function pendingCash(): JsonResponse
    {
        $pending = DailyLog::where('cash_pending', '>', 0)
            ->with(['employee:id,name,phone', 'vehicle:id,plate_number'])
            ->selectRaw('employee_id, vehicle_id, SUM(cash_pending) as total, MIN(log_date) as oldest_date')
            ->groupBy('employee_id', 'vehicle_id')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'grand_total' => $pending->sum('total'),
            'entries'     => $pending,
        ]);
    }

    /**
     * GET /api/reports/weekly-orders?from=&to=
     * Top 5 drivers + per-driver order count from meeting.
     */
    public function weeklyOrders(Request $request): JsonResponse
    {
        $from = $request->get('from', now()->startOfWeek()->toDateString());
        $to   = $request->get('to', now()->endOfWeek()->toDateString());

        $byDriver = DailyLog::with('employee:id,name')
            ->whereBetween('log_date', [$from, $to])
            ->selectRaw('employee_id, SUM(orders_count) as total_orders, SUM(income_amount) as total_income')
            ->groupBy('employee_id')
            ->orderByDesc('total_orders')
            ->get();

        return response()->json([
            'period'        => ['from' => $from, 'to' => $to],
            'total_orders'  => $byDriver->sum('total_orders'),
            'total_income'  => $byDriver->sum('total_income'),
            'top_5_drivers' => $byDriver->take(5),
            'all_drivers'   => $byDriver,
        ]);
    }

    /**
     * GET /api/reports/fleet-status
     */
    public function fleetStatus(): JsonResponse
    {
        $vehicles = Vehicle::with(['activeAssignment.employee:id,name', 'activeAssignment.contract:id,name'])
            ->orderBy('status')
            ->orderBy('plate_number')
            ->get()
            ->groupBy('status');

        return response()->json([
            'summary'  => Vehicle::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status'),
            'by_status'=> $vehicles,
        ]);
    }

    /**
     * GET /api/reports/vehicle-profitability?year=2026&month=4
     *
     * Per-vehicle P&L for the given month:
     *  - Income:      SUM(daily_logs.income_amount)
     *  - Maintenance: SUM(maintenance_records.actual_cost) where company paid (approved/completed, NOT driver-liable)
     *  - Violations:  SUM(violations.amount) where company-liable (is_driver_liable = false)
     *  - Net Profit:  income - (maintenance + violations)
     */
    public function vehicleProfitability(Request $request): JsonResponse
    {
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        $vehicles = Vehicle::select('id', 'plate_number', 'make', 'model', 'status')
            ->whereIn('status', ['working', 'available', 'maintenance'])
            ->get();

        $vehicleIds = $vehicles->pluck('id');

        // ── 1. Income per vehicle ─────────────────────────────────────
        $income = DailyLog::whereIn('vehicle_id', $vehicleIds)
            ->whereYear('log_date', $year)
            ->whereMonth('log_date', $month)
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, SUM(income_amount) as total, COUNT(*) as log_count, SUM(orders_count) as total_orders')
            ->pluck('total', 'vehicle_id')
            ->map(fn($v) => (float) $v);

        $orderCounts = DailyLog::whereIn('vehicle_id', $vehicleIds)
            ->whereYear('log_date', $year)
            ->whereMonth('log_date', $month)
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, SUM(orders_count) as total_orders')
            ->pluck('total_orders', 'vehicle_id')
            ->map(fn($v) => (int) $v);

        // ── 2. Company-paid maintenance per vehicle ───────────────────
        // Only approved/completed maintenance where the COMPANY pays (not driver-liable)
        $maintenance = MaintenanceRecord::whereIn('vehicle_id', $vehicleIds)
            ->whereYear('maintenance_date', $year)
            ->whereMonth('maintenance_date', $month)
            ->whereIn('status', ['approved', 'completed'])
            ->where(function ($q) {
                $q->where('is_driver_liable', false)->orWhereNull('is_driver_liable');
            })
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, SUM(COALESCE(actual_cost, estimated_cost)) as total')
            ->pluck('total', 'vehicle_id')
            ->map(fn($v) => (float) $v);

        // ── 3. Company-liable violations per vehicle ──────────────────
        $violations = Violation::whereIn('vehicle_id', $vehicleIds)
            ->whereYear('violation_date', $year)
            ->whereMonth('violation_date', $month)
            ->where('is_driver_liable', false)
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, SUM(amount) as total')
            ->pluck('total', 'vehicle_id')
            ->map(fn($v) => (float) $v);

        // ── 4. Assemble P&L rows ─────────────────────────────────────
        $rows = $vehicles->map(function ($v) use ($income, $maintenance, $violations, $orderCounts) {
            $inc  = $income[$v->id]      ?? 0;
            $mnt  = $maintenance[$v->id] ?? 0;
            $vio  = $violations[$v->id]  ?? 0;
            $net  = $inc - ($mnt + $vio);

            return [
                'vehicle_id'        => $v->id,
                'plate_number'      => $v->plate_number,
                'label'             => trim("{$v->make} {$v->model}"),
                'status'            => $v->status,
                'total_orders'      => $orderCounts[$v->id] ?? 0,
                'total_income'      => round($inc, 3),
                'total_maintenance' => round($mnt, 3),
                'total_violations'  => round($vio, 3),
                'net_profit'        => round($net, 3),
            ];
        })->sortByDesc('net_profit')->values();

        // ── 5. Summary totals ────────────────────────────────────────
        $totals = [
            'total_income'      => round($rows->sum('total_income'), 3),
            'total_maintenance' => round($rows->sum('total_maintenance'), 3),
            'total_violations'  => round($rows->sum('total_violations'), 3),
            'net_profit'        => round($rows->sum('net_profit'), 3),
        ];

        return response()->json([
            'year'     => $year,
            'month'    => $month,
            'vehicles' => $rows,
            'totals'   => $totals,
        ]);
    }

    /**
     * GET /api/reports/driver-status
     * RP-001: Driver status overview — active/probation/on_leave/inactive + overseas pipeline
     */
    public function driverStatus(): JsonResponse
    {
        $total = Employee::count();

        $byStatus = Employee::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $overseasStages = Employee::where('employee_type', 'overseas')
            ->where('status', '!=', 'inactive')
            ->selectRaw("
                SUM(CASE WHEN stage_license_obtained = 1 THEN 1 ELSE 0 END) as licensed,
                SUM(CASE WHEN stage_driving_trial_done = 1 AND (stage_license_obtained = 0 OR stage_license_obtained IS NULL) THEN 1 ELSE 0 END) as in_trial,
                SUM(CASE WHEN stage_work_permit_done = 1 AND (stage_driving_trial_done = 0 OR stage_driving_trial_done IS NULL) THEN 1 ELSE 0 END) as has_permit,
                SUM(CASE WHEN stage_medical_done = 1 AND (stage_work_permit_done = 0 OR stage_work_permit_done IS NULL) THEN 1 ELSE 0 END) as medical_done,
                SUM(CASE WHEN stage_arrived = 1 AND (stage_medical_done = 0 OR stage_medical_done IS NULL) THEN 1 ELSE 0 END) as arrived_only
            ")->first();

        return response()->json([
            'total'           => $total,
            'by_status'       => $byStatus,
            'overseas_stages' => $overseasStages,
        ]);
    }

    /**
     * GET /api/reports/contract-profitability
     * RP-010: Revenue per contract for a given month.
     */
    public function contractProfitability(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', now()->month);
        $year  = (int) $request->query('year', now()->year);

        $contracts = Contract::with('client:id,name')->get();

        $rows = $contracts->map(function ($c) use ($month, $year) {
            $ordersIncome = DailyLog::where('contract_id', $c->id)
                ->whereMonth('log_date', $month)
                ->whereYear('log_date', $year)
                ->sum('income_amount');

            $ordersCount = DailyLog::where('contract_id', $c->id)
                ->whereMonth('log_date', $month)
                ->whereYear('log_date', $year)
                ->sum('orders_count');

            // Fixed-monthly contracts always have at least the fixed amount
            $totalIncome = $c->payment_type === 'fixed_monthly'
                ? max((float) $ordersIncome, (float) $c->fixed_monthly)
                : (float) $ordersIncome;

            return [
                'contract_id'   => $c->id,
                'contract_name' => $c->name,
                'client_name'   => $c->client?->name ?? '—',
                'payment_type'  => $c->payment_type,
                'is_active'     => $c->is_active,
                'total_orders'  => (int) $ordersCount,
                'total_income'  => $totalIncome,
            ];
        })->sortByDesc('total_income')->values();

        $totals = [
            'total_income' => $rows->sum('total_income'),
            'total_orders' => $rows->sum('total_orders'),
        ];

        return response()->json([
            'year'      => $year,
            'month'     => $month,
            'contracts' => $rows,
            'totals'    => $totals,
        ]);
    }
}

