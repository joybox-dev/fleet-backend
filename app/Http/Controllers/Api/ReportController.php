<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\ContractProfitabilityService;
use App\Services\ContractRevenueService;
use App\Services\DeductionsReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
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
            DeductionsReportService::build($this->currentCompanyId())
        );
    }

    /**
     * GET /api/reports/expiring-docs
     * Vehicles + employees with documents expiring within 60 days.
     * From meeting: red = expired, warning = approaching.
     */
    public function expiringDocs(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 60);
        $alertDate = now()->addDays($days)->toDateString();
        $today = now()->toDateString();

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
            ->map(fn ($v) => [
                'id' => $v->id,
                'plate_number' => $v->plate_number,
                'label' => "{$v->make} {$v->model}",
                'alerts' => array_values(array_filter([
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
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'alerts' => array_values(array_filter([
                    $this->docStatus('كرت صحي', $e->health_card_expiry, $today, $alertDate),
                    $this->docStatus('إقامة', $e->residence_expiry, $today, $alertDate),
                    $this->docStatus('رخصة قيادة', $e->driving_license_expiry, $today, $alertDate),
                    $this->docStatus('إذن عمل', $e->work_permit_expiry, $today, $alertDate),
                ])),
            ]);

        return response()->json([
            'alert_window_days' => $days,
            'vehicles' => array_values($vehicles->toArray()),
            'employees' => array_values($employees->toArray()),
        ]);
    }

    /**
     * 3-tier doc status: expired | warning | valid
     */
    private function docStatus(string $label, ?string $expiry, string $today, string $alertDate): ?array
    {
        if (! $expiry) {
            return null;
        }

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
            ->when($request->year, fn ($q) => $q->whereYear('violation_date', $request->year))
            ->when($request->month, fn ($q) => $q->whereMonth('violation_date', $request->month))
            ->when($request->boolean('driver_liable'), fn ($q) => $q->where('is_driver_liable', true))
            ->orderByDesc('violation_date')
            ->get();

        return response()->json([
            'count' => $violations->count(),
            'total_kwd' => $violations->sum('amount'),
            'violations' => $violations,
        ]);
    }

    /**
     * GET /api/reports/pending-cash
     */
    public function pendingCash(): JsonResponse
    {
        // One row per driver: the cash is his debt, not the vehicle's. Grouping by both split a
        // driver who had changed plates into two rows the report cannot even tell apart — it prints
        // name, amount and oldest date, and no plate.
        $pending = DailyLog::where('cash_pending', '>', 0)
            ->with(['employee:id,name,phone'])
            ->selectRaw('employee_id, SUM(cash_pending) as total, MIN(log_date) as oldest_date')
            ->groupBy('employee_id')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'grand_total' => $pending->sum('total'),
            'entries' => $pending,
        ]);
    }

    /**
     * GET /api/reports/weekly-orders?from=&to=
     * Top 5 drivers + per-driver order count from meeting.
     */
    public function weeklyOrders(Request $request): JsonResponse
    {
        $from = $request->get('from', now()->startOfWeek()->toDateString());
        $to = $request->get('to', now()->endOfWeek()->toDateString());

        $byDriver = DailyLog::with('employee:id,name')
            ->whereBetween('log_date', [$from, $to])
            ->selectRaw('employee_id, SUM(orders_count) as total_orders')
            ->groupBy('employee_id')
            ->orderByDesc('total_orders')
            ->get();

        // Billed from each contract's client rules over the range — the stored per-log income
        // was a flat rate no live contract has, so this read 0.000 on every week.
        $totalIncome = 0.0;
        $logs = DailyLog::with('vehicle:id,vehicle_type_id')
            ->whereBetween('log_date', [$from, $to])
            ->get(['id', 'contract_id', 'vehicle_id', 'orders_count', 'zone', 'notes']);
        $contracts = Contract::whereIn('id', $logs->pluck('contract_id')->filter()->unique())->get()->keyBy('id');
        foreach ($logs->groupBy('contract_id') as $contractId => $contractLogs) {
            $contract = $contracts->get($contractId);
            if ($contract) {
                $totalIncome += ContractRevenueService::forContractMonth($contract, $contractLogs)['revenue'];
            }
        }

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'total_orders' => $byDriver->sum('total_orders'),
            'total_income' => round($totalIncome, 3),
            'top_5_drivers' => $byDriver->take(5),
            'all_drivers' => $byDriver,
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
            'summary' => Vehicle::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status'),
            'by_status' => $vehicles,
        ]);
    }

    /**
     * GET /api/reports/vehicle-profitability?year=2026&month=4
     *
     * Per-vehicle P&L for the month, read from ContractProfitabilityService: the vehicle's share
     * of the revenue and driver pay of every contract it worked, less the repairs and fines the
     * company bore on it. The old report summed `daily_logs.income_amount` — 0.000 on every live
     * log — and showed every vehicle as a loss.
     */
    public function vehicleProfitability(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        $result = ContractProfitabilityService::forVehiclesMonth($this->currentCompanyId(), $year, $month);

        return response()->json([
            'year' => $year,
            'month' => $month,
            'vehicles' => $result['vehicles'],
            'totals' => $result['totals'],
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
            ->selectRaw('
                SUM(CASE WHEN stage_license_obtained = 1 THEN 1 ELSE 0 END) as licensed,
                SUM(CASE WHEN stage_driving_trial_done = 1 AND (stage_license_obtained = 0 OR stage_license_obtained IS NULL) THEN 1 ELSE 0 END) as in_trial,
                SUM(CASE WHEN stage_work_permit_done = 1 AND (stage_driving_trial_done = 0 OR stage_driving_trial_done IS NULL) THEN 1 ELSE 0 END) as has_permit,
                SUM(CASE WHEN stage_medical_done = 1 AND (stage_work_permit_done = 0 OR stage_work_permit_done IS NULL) THEN 1 ELSE 0 END) as medical_done,
                SUM(CASE WHEN stage_arrived = 1 AND (stage_medical_done = 0 OR stage_medical_done IS NULL) THEN 1 ELSE 0 END) as arrived_only
            ')->first();

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'overseas_stages' => $overseasStages,
        ]);
    }

    /**
     * GET /api/reports/contract-profitability
     * RP-010: Full P&L per contract — the same figures the contract's dashboard shows.
     */
    public function contractProfitability(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);

        $rows = collect(ContractProfitabilityService::forCompanyMonth($this->currentCompanyId(), $year, $month))
            ->map(fn (array $row) => [
                'contract_id' => $row['contract_id'],
                'contract_name' => $row['contract_name'],
                'client_name' => $row['client_name'],
                'payment_type' => $row['payment_type'],
                'is_active' => $row['is_active'],
                'total_orders' => $row['orders'],
                'unpriced_orders' => $row['unpriced_orders'],
                'revenue' => $row['revenue'],
                'driver_cost' => $row['driver_cost'],
                'vehicle_costs' => $row['vehicle_costs'],
                'maintenance_cost' => $row['maintenance_cost'],
                'violations_cost' => $row['violations_cost'],
                'supervisors_cost' => $row['supervisors_cost'],
                'net_profit' => $row['profit'],
            ])
            ->sortByDesc('net_profit')
            ->values();

        return response()->json([
            'year' => $year,
            'month' => $month,
            'contracts' => $rows->all(),
            'totals' => [
                'revenue' => round($rows->sum('revenue'), 3),
                'driver_cost' => round($rows->sum('driver_cost'), 3),
                'vehicle_costs' => round($rows->sum('vehicle_costs'), 3),
                'maintenance_cost' => round($rows->sum('maintenance_cost'), 3),
                'violations_cost' => round($rows->sum('violations_cost'), 3),
                'supervisors_cost' => round($rows->sum('supervisors_cost'), 3),
                'net_profit' => round($rows->sum('net_profit'), 3),
                'total_orders' => (int) $rows->sum('total_orders'),
                'unpriced_orders' => (int) $rows->sum('unpriced_orders'),
            ],
        ]);
    }

    /**
     * GET /api/reports/missing-docs
     * Employees with NULL/missing critical documents.
     */
    public function missingDocs(): JsonResponse
    {
        $docFields = [
            'civil_id' => 'البطاقة المدنية',
            'residence_expiry' => 'تاريخ انتهاء الإقامة',
            'work_permit_expiry' => 'تاريخ انتهاء إذن العمل',
            'health_card_expiry' => 'تاريخ انتهاء الكرت الصحي',
            'driving_license_expiry' => 'تاريخ انتهاء رخصة القيادة',
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
                    'id' => $emp->id,
                    'name' => $emp->name,
                    'name_ar' => $emp->name_ar,
                    'employee_number' => $emp->employee_number,
                    'employee_type' => $emp->employee_type,
                    'status' => $emp->status,
                    'missing_docs' => $missing,
                    'missing_count' => count($missing),
                ];
            });

        return response()->json([
            'total_employees' => $employees->count(),
            'total_missing_documents' => $employees->sum('missing_count'),
            'employees' => $employees->values(),
        ]);
    }
}
