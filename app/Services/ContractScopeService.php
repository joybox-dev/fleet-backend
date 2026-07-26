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

        // 2. Find associated employee record by user_id
        $employee = Employee::where('user_id', $user->id)->first();

        if (!$employee) {
            // Full Company Admin without employee restrictions sees all contracts
            return null;
        }

        // Check if the employee's assigned role is main admin
        if ($employee->role_category === 'admin') {
            if (!$employee->admin_role_id) {
                return null;
            }
            $roleModel = \App\Models\Role::find($employee->admin_role_id);
            if ($roleModel && ($roleModel->id === 'role_admin' || str_contains(mb_strtolower($roleModel->name), 'أدمن رئيسي') || str_contains(mb_strtolower($roleModel->name), 'أدمن') || str_contains(mb_strtolower($roleModel->name), 'super admin'))) {
                return null;
            }
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

        // If employee has no contract allocations, restrict access (sees 0 contracts until assigned)
        return [0];
    }
}
