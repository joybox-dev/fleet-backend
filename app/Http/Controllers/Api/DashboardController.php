<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\Employee;
use App\Models\DailyLog;
use App\Models\CashSettlement;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
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
        $pendingCash = DailyLog::query()
            ->where('cash_pending', '>', 0)
            ->selectRaw('employee_id, SUM(cash_pending) as total_pending')
            ->with('employee:id,name')
            ->groupBy('employee_id')
            ->get()
            ->map(fn($row) => [
                'employee_id'   => $row->employee_id,
                'employee_name' => $row->employee?->name,
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
              ->orWhere('food_authority_license_expiry', '<=', $alertDate);
        })->count();

        $employeeAlertsCount = Employee::where('status', 'active')
            ->where(function ($q) use ($alertDate) {
                $q->where('health_card_expiry', '<=', $alertDate)
                  ->orWhere('residence_expiry', '<=', $alertDate)
                  ->orWhere('driving_license_expiry', '<=', $alertDate)
                  ->orWhere('work_permit_expiry', '<=', $alertDate);
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
}
