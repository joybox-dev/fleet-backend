<?php

namespace App\Services;

use App\Models\User;
use App\Models\Employee;
use App\Models\SupervisorCostAllocation;
use App\Models\ContractAssignment;

class ContractScopeService
{
    /**
     * Get the array of contract IDs that the user is allowed to access.
     * Returns null if the user has unrestricted access (sees all contracts).
     * Returns array of integer IDs if the user is restricted to specific contracts.
     */
    public static function getAllocatedContractIds(?User $user = null): ?array
    {
        $user = $user ?? auth()->user();
        if (!$user) {
            return null;
        }

        // 1. Super Admins always see all contracts
        if ($user->isSuperAdmin()) {
            return null;
        }

        // 2. Find associated employee record by user_id or email
        $employee = Employee::where('user_id', $user->id)
            ->orWhere(function($q) use ($user) {
                if (!empty($user->email)) {
                    $q->whereNotNull('email')->where('email', $user->email);
                }
            })
            ->first();

        if (!$employee) {
            // Full Company Admin without employee restrictions sees all contracts
            return null;
        }

        // Auto-heal missing user_id link
        if (empty($employee->user_id) && $user->id) {
            $employee->update(['user_id' => $user->id]);
        }

        $contractIds = [];

        // Source A: Supervisor cost allocations table
        $supervisorContractIds = SupervisorCostAllocation::where('employee_id', $employee->id)
            ->where('allocation_percentage', '>', 0)
            ->pluck('contract_id')
            ->toArray();

        foreach ($supervisorContractIds as $id) {
            $contractIds[] = (int) $id;
        }

        // Source B: Direct Contract Assignments table
        $directContractIds = ContractAssignment::where('employee_id', $employee->id)
            ->pluck('contract_id')
            ->toArray();

        foreach ($directContractIds as $id) {
            $contractIds[] = (int) $id;
        }

        // Source C: Check salary allocations array on employee record
        if (!empty($employee->salary_allocations) && is_array($employee->salary_allocations)) {
            foreach ($employee->salary_allocations as $alloc) {
                if (is_array($alloc) && !empty($alloc['contract_id'])) {
                    $perc = (float) ($alloc['percentage'] ?? $alloc['allocation_percentage'] ?? 1);
                    if ($perc > 0) {
                        $contractIds[] = (int) $alloc['contract_id'];
                    }
                }
            }
        }

        $contractIds = array_values(array_unique(array_filter($contractIds)));

        // If employee has specific contract allocations or assignments, return those IDs
        if (count($contractIds) > 0) {
            return $contractIds;
        }

        // If no specific contract allocations or assignments exist, return null (unrestricted access)
        return null;
    }
}
