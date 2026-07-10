<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupervisorCostAllocation;
use App\Models\SupervisorAllocationAuditLog;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupervisorAllocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        $allocations = SupervisorCostAllocation::with('contract:id,name,currency')
            ->where('employee_id', $request->employee_id)
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn($item) => $item->effective_date->format('Y-m'));

        $auditLogs = SupervisorAllocationAuditLog::with('user:id,name')
            ->where('employee_id', $request->employee_id)
            ->orderByDesc('changed_at')
            ->get();

        return response()->json([
            'allocations' => $allocations,
            'audit_logs'  => $auditLogs
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        $userId = auth()->id() ?? 1; // Fallback to user ID 1 for tests/system

        $request->validate([
            'employee_id'    => 'required|exists:employees,id',
            'effective_date' => 'required|date',
            'allocations'    => 'required|array|min:1',
            'allocations.*.contract_id' => 'required|exists:contracts,id',
            'allocations.*.allocation_percentage' => 'required|numeric|min:0|max:100',
        ]);

        $employeeId = $request->employee_id;
        $effectiveDate = Carbon::parse($request->effective_date)->startOfMonth()->toDateString();
        $allocationsList = $request->allocations;

        // Check if sum of percentages exceeds 100%
        $sum = collect($allocationsList)->sum('allocation_percentage');
        if ($sum > 100.01) {
            return response()->json([
                'message' => 'يجب ألا يتجاوز مجموع نسب التوزيع 100% بأي حال من الأحوال.',
                'errors'  => ['allocations' => ['مجموع النسب الحالية هو ' . $sum . '% ويجب ألا يتجاوز 100%']]
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Fetch old allocations for audit log
            $oldAllocations = SupervisorCostAllocation::where('employee_id', $employeeId)
                ->where('effective_date', $effectiveDate)
                ->get()
                ->map(fn($item) => [
                    'contract_id' => $item->contract_id,
                    'allocation_percentage' => (float)$item->allocation_percentage
                ])
                ->toArray();

            // Delete existing allocations for this supervisor and effective month
            SupervisorCostAllocation::where('employee_id', $employeeId)
                ->where('effective_date', $effectiveDate)
                ->delete();

            $newAllocations = [];
            foreach ($allocationsList as $alloc) {
                if ($alloc['allocation_percentage'] > 0) {
                    $newAllocations[] = SupervisorCostAllocation::create([
                        'company_id' => $companyId,
                        'employee_id' => $employeeId,
                        'contract_id' => $alloc['contract_id'],
                        'allocation_percentage' => $alloc['allocation_percentage'],
                        'effective_date' => $effectiveDate
                    ]);
                }
            }

            // Create Audit Log
            SupervisorAllocationAuditLog::create([
                'employee_id' => $employeeId,
                'action_by'   => $userId,
                'old_allocation' => $oldAllocations,
                'new_allocation' => collect($allocationsList)->map(fn($item) => [
                    'contract_id' => $item['contract_id'],
                    'allocation_percentage' => (float)$item['allocation_percentage']
                ])->toArray(),
                'changed_at'  => Carbon::now()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'تم حفظ توزيع التكلفة بنجاح.',
                'allocations' => $newAllocations
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'فشل حفظ التوزيع: ' . $e->getMessage()
            ], 500);
        }
    }
}
