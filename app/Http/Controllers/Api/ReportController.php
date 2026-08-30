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
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * GET /api/reports/expiring-docs
     * Vehicles + employees with documents expiring within 60 days.
     * From meeting: red = expired, warning = approaching.
     */
    /**
     * GET /api/reports/deductions
     * Every deduction on every employee, and whether it has been taken yet.
     */
    public function deductions(Request $request): JsonResponse
    {
        if (! $request->user()->can('payroll.view')) {
            return response()->json(['message' => 'غير مصرح لك بعرض الخصومات.'], 403);
        }

        return response()->json(
            \App\Services\DeductionsReportService::build(app('current_company_id'))
        );
    }

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
        $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate   = Carbon::parse($startDate)->endOfMonth()->toDateString();

        $vehicles = Vehicle::select('id', 'plate_number', 'make', 'model', 'status')
            ->whereIn('status', ['working', 'available', 'maintenance'])
            ->get();
        $vehicleIds = $vehicles->pluck('id');

        // ── 1. All daily logs for the month ──
        $logs = DailyLog::whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->get();
        $logsByVehicle = $logs->groupBy('vehicle_id');

        // ── 2. Revenue per vehicle (per_order: income_amount, fixed: proportional fixed_monthly) ──
        $contractIds = $logs->pluck('contract_id')->unique();
        $contracts = Contract::whereIn('id', $contractIds)->get()->keyBy('id');
        $revenueMap = [];
        foreach ($logs->groupBy('contract_id') as $cId => $cLogs) {
            $contract = $contracts[$cId] ?? null;
            if (!$contract) continue;
            foreach ($cLogs->groupBy('vehicle_id') as $vId => $vLogs) {
                if ($contract->payment_type === 'fixed') {
                    $totalOnContract = $cLogs->sum('orders_count');
                    $vehicleOrders   = $vLogs->sum('orders_count');
                    $share = $totalOnContract > 0
                        ? (float) $contract->fixed_monthly * ($vehicleOrders / $totalOnContract)
                        : 0;
                    $revenueMap[$vId] = ($revenueMap[$vId] ?? 0) + $share;
                } else {
                    $revenueMap[$vId] = ($revenueMap[$vId] ?? 0) + (float) $vLogs->sum('income_amount');
                }
            }
        }

        // ── 3. Driver cost per vehicle (proportional allocation) ──
        $employeeIds = $logs->pluck('employee_id')->unique();
        $employees   = Employee::whereIn('id', $employeeIds)->get()->keyBy('id');
        $totalOrdersByEmp = DailyLog::whereIn('employee_id', $employeeIds)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(orders_count) as total')
            ->pluck('total', 'employee_id');

        $driverCostMap = [];
        foreach ($logsByVehicle as $vId => $vLogs) {
            $cost = 0;
            foreach ($vLogs->groupBy('employee_id') as $empId => $empLogs) {
                $emp = $employees[$empId] ?? null;
                if (!$emp) continue;
                $ordersHere  = $empLogs->sum('orders_count');
                $totalOrders = $totalOrdersByEmp[$empId] ?? 0;
                $cost += $empLogs->sum('driver_commission');
                if ((float) $emp->actual_salary > 0 && $totalOrders > 0) {
                    $cost += (float) $emp->actual_salary * ($ordersHere / $totalOrders);
                }
            }
            $driverCostMap[$vId] = round($cost, 3);
        }

        // ── 4. Company-paid maintenance ──
        $maintenance = MaintenanceRecord::whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('maintenance_date', [$startDate, $endDate])
            ->whereIn('status', ['approved', 'completed'])
            ->where(fn($q) => $q->where('is_driver_liable', false)->orWhereNull('is_driver_liable'))
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, SUM(COALESCE(actual_cost, estimated_cost)) as total')
            ->pluck('total', 'vehicle_id')->map(fn($v) => (float) $v);

        // ── 5. Company-liable violations ──
        $violations = Violation::whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('violation_date', [$startDate, $endDate])
            ->where('is_driver_liable', false)
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, SUM(amount) as total')
            ->pluck('total', 'vehicle_id')->map(fn($v) => (float) $v);

        // ── 6. Assemble P&L ──
        $rows = $vehicles->map(function ($v) use ($revenueMap, $driverCostMap, $maintenance, $violations, $logsByVehicle) {
            $rev  = round($revenueMap[$v->id] ?? 0, 3);
            $drv  = round($driverCostMap[$v->id] ?? 0, 3);
            $mnt  = round($maintenance[$v->id] ?? 0, 3);
            $vio  = round($violations[$v->id] ?? 0, 3);
            $net  = round($rev - $drv - $mnt - $vio, 3);
            $vLogs = $logsByVehicle[$v->id] ?? collect();
            return [
                'vehicle_id'        => $v->id,
                'plate_number'      => $v->plate_number,
                'label'             => trim("{$v->make} {$v->model}"),
                'status'            => $v->status,
                'total_orders'      => (int) $vLogs->sum('orders_count'),
                'revenue'           => $rev,
                'driver_cost'       => $drv,
                'total_maintenance' => $mnt,
                'total_violations'  => $vio,
                'net_profit'        => $net,
            ];
        })->sortByDesc('net_profit')->values();

        $totals = [
            'revenue'           => round($rows->sum('revenue'), 3),
            'driver_cost'       => round($rows->sum('driver_cost'), 3),
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
        $total = Employee::where('role_category', 'driver')->count();

        $byStatus = Employee::where('role_category', 'driver')
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $overseasStages = Employee::where('role_category', 'driver')
            ->where('employee_type', 'overseas')
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
     * RP-010: Full P&L per contract — Revenue, Driver Cost, Net Profit.
     */
    public function contractProfitability(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', now()->month);
        $year  = (int) $request->query('year', now()->year);
        $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate   = Carbon::parse($startDate)->endOfMonth()->toDateString();

        $contracts = Contract::with('client:id,name')->get();

        // All logs for the month
        $allLogs = DailyLog::whereBetween('log_date', [$startDate, $endDate])->get();
        $logsByContract = $allLogs->groupBy('contract_id');

        // Employees + total orders per employee in the ENTIRE month
        $employeeIds = $allLogs->pluck('employee_id')->unique();
        $employees   = Employee::whereIn('id', $employeeIds)->get()->keyBy('id');
        $totalOrdersByEmp = $allLogs->groupBy('employee_id')
            ->map(fn($logs) => (int) $logs->sum('orders_count'));

        $rows = $contracts->map(function ($c) use ($logsByContract, $employees, $totalOrdersByEmp) {
            $cLogs = $logsByContract[$c->id] ?? collect();
            $ordersCount = (int) $cLogs->sum('orders_count');

            // Revenue (fixed enum: 'fixed' not 'fixed_monthly')
            $revenue = $c->payment_type === 'fixed'
                ? max((float) $cLogs->sum('income_amount'), (float) $c->fixed_monthly)
                : (float) $cLogs->sum('income_amount');

            // Driver cost (exact chronological commissions + proportional base salary allocation)
            $driverCost = 0;
            foreach ($cLogs->groupBy('employee_id') as $empId => $empLogs) {
                $emp = $employees[$empId] ?? null;
                if (!$emp) continue;
                $ordersHere  = $empLogs->sum('orders_count');
                $totalOrders = $totalOrdersByEmp[$empId] ?? 0;
                $driverCost += $empLogs->sum('driver_commission');
                if ((float) $emp->actual_salary > 0 && $totalOrders > 0) {
                    $driverCost += (float) $emp->actual_salary * ($ordersHere / $totalOrders);
                }
            }
            $driverCost = round($driverCost, 3);

            return [
                'contract_id'   => $c->id,
                'contract_name' => $c->name,
                'client_name'   => $c->client?->name ?? '—',
                'payment_type'  => $c->payment_type,
                'is_active'     => $c->is_active,
                'total_orders'  => $ordersCount,
                'revenue'       => round($revenue, 3),
                'driver_cost'   => $driverCost,
                'net_profit'    => round($revenue - $driverCost, 3),
            ];
        })->sortByDesc('net_profit')->values();

        $totals = [
            'revenue'      => round($rows->sum('revenue'), 3),
            'driver_cost'  => round($rows->sum('driver_cost'), 3),
            'net_profit'   => round($rows->sum('net_profit'), 3),
            'total_orders' => $rows->sum('total_orders'),
        ];

        return response()->json([
            'year'      => $year,
            'month'     => $month,
            'contracts' => $rows,
            'totals'    => $totals,
        ]);
    }

    /**
     * GET /api/reports/missing-docs
     * Employees with NULL/missing critical documents.
     */
    public function missingDocs(): JsonResponse
    {
        $docFields = [
            'civil_id'              => 'البطاقة المدنية',
            'residence_expiry'      => 'تاريخ انتهاء الإقامة',
            'work_permit_expiry'    => 'تاريخ انتهاء إذن العمل',
            'health_card_expiry'    => 'تاريخ انتهاء الكرت الصحي',
            'driving_license_expiry'=> 'تاريخ انتهاء رخصة القيادة',
        ];

        $employees = Employee::where('role_category', 'driver')
            ->whereIn('status', ['active', 'probation'])
            ->where(function ($q) use ($docFields) {
                foreach (array_keys($docFields) as $field) {
                    $q->orWhereNull($field);
                }
            })
            ->select('id', 'name', 'name_ar', 'employee_number', 'employee_type', 'status',
                'civil_id', 'residence_expiry', 'work_permit_expiry',
                'health_card_expiry', 'driving_license_expiry')
            ->orderBy('name')
            ->get()
            ->map(function ($emp) use ($docFields) {
                $missing = [];
                foreach ($docFields as $field => $label) {
                    if (is_null($emp->$field)) {
                        $missing[] = $label;
                    }
                }
                return [
                    'id'              => $emp->id,
                    'name'            => $emp->name,
                    'name_ar'         => $emp->name_ar,
                    'employee_number' => $emp->employee_number,
                    'employee_type'   => $emp->employee_type,
                    'status'          => $emp->status,
                    'missing_docs'    => $missing,
                    'missing_count'   => count($missing),
                ];
            });

        return response()->json([
            'total_employees'         => $employees->count(),
            'total_missing_documents' => $employees->sum('missing_count'),
            'employees'               => $employees->values(),
        ]);
    }
}

