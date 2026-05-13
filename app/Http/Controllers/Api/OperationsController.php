<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\EmployeeLeave;
use App\Models\VehicleAssignment;
use Illuminate\Http\JsonResponse;

class OperationsController extends Controller
{
    /**
     * GET /api/operations/dashboard
     * Per-contract capacity: required vs available drivers, leave counts, deficit.
     * Optimized: batch queries instead of N+1 per contract.
     */
    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();
        $month = now()->month;
        $year  = now()->year;

        $contracts = Contract::with('client:id,name')
            ->where('is_active', true)
            ->get(['id', 'client_id', 'name', 'required_drivers', 'daily_target', 'monthly_target']);

        $contractIds = $contracts->pluck('id');

        // Batch: active assignments grouped by contract
        $assignmentCounts = VehicleAssignment::whereIn('contract_id', $contractIds)
            ->whereNull('unassigned_at')
            ->selectRaw('contract_id, COUNT(*) as cnt')
            ->groupBy('contract_id')
            ->pluck('cnt', 'contract_id');

        // Batch: employee IDs per contract (for leave lookup)
        $employeesByContract = VehicleAssignment::whereIn('contract_id', $contractIds)
            ->whereNull('unassigned_at')
            ->select('contract_id', 'employee_id')
            ->get()
            ->groupBy('contract_id');

        // Batch: all employees on leave today
        $allAssignedEmployeeIds = $employeesByContract->flatten()->pluck('employee_id')->unique();
        $onLeaveIds = EmployeeLeave::where('status', 'approved')
            ->whereIn('employee_id', $allAssignedEmployeeIds)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->pluck('employee_id')
            ->toArray();

        // Batch: orders today per contract
        $ordersToday = DailyLog::whereIn('contract_id', $contractIds)
            ->whereDate('log_date', $today)
            ->selectRaw('contract_id, SUM(orders_count) as total')
            ->groupBy('contract_id')
            ->pluck('total', 'contract_id');

        // Batch: orders this month per contract
        $ordersMonth = DailyLog::whereIn('contract_id', $contractIds)
            ->whereMonth('log_date', $month)
            ->whereYear('log_date', $year)
            ->selectRaw('contract_id, SUM(orders_count) as total')
            ->groupBy('contract_id')
            ->pluck('total', 'contract_id');

        // Assemble per-contract data (zero queries in loop)
        $result = $contracts->map(function ($c) use ($assignmentCounts, $employeesByContract, $onLeaveIds, $ordersToday, $ordersMonth) {
            $assigned = $assignmentCounts[$c->id] ?? 0;

            $contractEmployeeIds = ($employeesByContract[$c->id] ?? collect())->pluck('employee_id');
            $onLeave = $contractEmployeeIds->intersect($onLeaveIds)->count();

            $available = $assigned - $onLeave;
            $required  = $c->required_drivers ?? 0;
            $deficit   = $required > 0 ? max(0, $required - $available) : 0;

            return [
                'contract_id'       => $c->id,
                'contract_name'     => $c->name,
                'client_name'       => $c->client->name ?? '—',
                'required_drivers'  => $required,
                'assigned_drivers'  => $assigned,
                'on_leave_today'    => $onLeave,
                'available_drivers' => $available,
                'deficit'           => $deficit,
                'has_deficit'       => $deficit > 0,
                'orders_today'      => (int) ($ordersToday[$c->id] ?? 0),
                'daily_target'      => $c->daily_target ?? 0,
                'orders_month'      => (int) ($ordersMonth[$c->id] ?? 0),
                'monthly_target'    => $c->monthly_target ?? 0,
            ];
        });

        return response()->json([
            'date'      => $today,
            'contracts' => $result,
            'totals'    => [
                'total_required'     => $result->sum('required_drivers'),
                'total_assigned'     => $result->sum('assigned_drivers'),
                'total_on_leave'     => $result->sum('on_leave_today'),
                'total_available'    => $result->sum('available_drivers'),
                'total_deficit'      => $result->sum('deficit'),
                'total_orders_today' => $result->sum('orders_today'),
                'total_orders_month' => $result->sum('orders_month'),
            ],
        ]);
    }
}
