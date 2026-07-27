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

        // 1. Contract Scoping for non-super-admins
        $allowedIds = \App\Services\ContractScopeService::getAllocatedContractIds();

        $query = Contract::with('client:id,name')->where('is_active', true);
        if ($allowedIds !== null) {
            $query->whereIn('id', $allowedIds);
        }

        $contracts = $query->get();
        $contractIds = $contracts->pluck('id');

        // Batch: active driver contract assignments (The single source of truth for driver contract assignment)
        $contractAssignments = \App\Models\ContractAssignment::whereIn('contract_id', $contractIds)
            ->where('status', 'active')
            ->select('contract_id', 'employee_id')
            ->get()
            ->groupBy('contract_id');

        // Collect assigned employee IDs per contract
        $allAssignedEmployeeIds = collect();
        $assignedCountsPerContract = [];
        $employeesByContract = [];

        foreach ($contractIds as $cId) {
            $cEmps = ($contractAssignments[$cId] ?? collect())->pluck('employee_id')
                ->filter()
                ->unique()
                ->values();
            
            $assignedCountsPerContract[$cId] = $cEmps->count();
            $employeesByContract[$cId] = $cEmps;
            $allAssignedEmployeeIds = $allAssignedEmployeeIds->merge($cEmps);
        }

        $allAssignedEmployeeIds = $allAssignedEmployeeIds->unique();

        // Batch: all assigned employees on approved leave today
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

        // Assemble per-contract data
        $result = $contracts->map(function ($c) use ($assignedCountsPerContract, $employeesByContract, $onLeaveIds, $ordersToday, $ordersMonth) {
            $assigned = $assignedCountsPerContract[$c->id] ?? 0;

            $contractEmployeeIds = $employeesByContract[$c->id] ?? collect();
            $onLeave = $contractEmployeeIds->intersect($onLeaveIds)->count();

            $available = max(0, $assigned - $onLeave);
            $required  = $c->required_vehicles_count ?? $c->required_drivers ?? 0;
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
                'daily_target'      => $c->default_daily_target ?? $c->daily_target ?? 0,
                'orders_month'      => (int) ($ordersMonth[$c->id] ?? 0),
                'monthly_target'    => $c->default_monthly_target ?? $c->monthly_target ?? 0,
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
