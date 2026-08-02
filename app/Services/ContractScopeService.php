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

        // Super Admins always see all contracts
        if ($user->isSuperAdmin()) {
            return null;
        }

        // If user does NOT have the scope_contracts restriction checked, they see all contracts
        if (!$user->can('employees.scope_contracts')) {
            return null;
        }

        // Find associated employee record by user_id
        $employee = Employee::where('user_id', $user->id)->first();

        if (!$employee) {
            return null;
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

        if (count($contractIds) > 0) {
            return $contractIds;
        }

        // Restricted employee with no contract allocations assigned yet -> sees 0 contracts
        return [0];
    }

    /**
     * Get the array of driver employee IDs that the user is allowed to access.
     * Returns null if the user has unrestricted access (sees all drivers).
     * Returns array of integer IDs if the user is restricted to specific drivers on their allocated contracts.
     */
    public static function getAllocatedDriverIds(?User $user = null): ?array
    {
        $contractIds = self::getAllocatedContractIds($user);

        if ($contractIds === null) {
            return null;
        }

        if (empty($contractIds) || $contractIds === [0]) {
            return [0];
        }

        // Fetch driver employee IDs assigned to these contract IDs
        $driverIds = ContractAssignment::whereIn('contract_id', $contractIds)
            ->where('status', 'active')
            ->pluck('employee_id')
            ->toArray();

        // Also check vehicle assignments linked to these contracts
        $vehicleDriverIds = \App\Models\VehicleAssignment::whereIn('contract_id', $contractIds)
            ->where('is_active', true)
            ->pluck('employee_id')
            ->toArray();

        $allDriverIds = array_values(array_unique(array_filter(array_merge($driverIds, $vehicleDriverIds))));

        if (count($allDriverIds) > 0) {
            return $allDriverIds;
        }

        return [0];
    }
}
