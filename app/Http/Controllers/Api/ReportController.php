<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\DailyLog;
use App\Models\Violation;
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
                'alerts'       => array_filter([
                    $this->docAlert('تأمين السيارة', $v->insurance_expiry, $today),
                    $this->docAlert('تأمين شامل', $v->comprehensive_insurance_expiry, $today),
                    $this->docAlert('رخصة هيئة الغذاء', $v->food_authority_license_expiry, $today),
                    $this->docAlert('صيانة دورية', $v->next_service_due, $today),
                ]),
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
                'alerts' => array_filter([
                    $this->docAlert('كرت صحي', $e->health_card_expiry, $today),
                    $this->docAlert('إقامة', $e->residence_expiry, $today),
                    $this->docAlert('رخصة قيادة', $e->driving_license_expiry, $today),
                    $this->docAlert('إذن عمل', $e->work_permit_expiry, $today),
                ]),
            ]);

        return response()->json([
            'alert_window_days' => $days,
            'vehicles'          => array_values($vehicles->toArray()),
            'employees'         => array_values($employees->toArray()),
        ]);
    }

    private function docAlert(string $label, ?string $expiry, string $today): ?array
    {
        if (!$expiry) return null;
        $status = $expiry < $today ? 'expired' : 'warning';
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
}
