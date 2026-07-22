<?php

namespace App\Services;

use App\Models\User;
use App\Models\Employee;
use App\Models\SupervisorCostAllocation;

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

        // 2. Find associated employee record
        $employee = Employee::where('user_id', $user->id)->first();
        if (!$employee) {
            // Full Company Admin without employee restrictions sees all contracts
            return null;
        }

        $contractIds = [];

        // Check supervisor cost allocations table
        $supervisorContractIds = SupervisorCostAllocation::where('employee_id', $employee->id)
            ->where('allocation_percentage', '>', 0)
            ->pluck('contract_id')
            ->toArray();

        foreach ($supervisorContractIds as $id) {
            $contractIds[] = (int) $id;
        }

        // Check salary allocations array on employee record
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

        // If employee has specific contract allocations, return those IDs
        if (count($contractIds) > 0) {
            return $contractIds;
        }

        // If no specific allocations are defined for this employee and role is admin, return null (unrestricted)
        if ($user->role === 'admin') {
            return null;
        }

        return $contractIds;
    }
}
