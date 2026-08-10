<?php

namespace App\Services;

use App\Models\User;
use App\Models\Employee;
use App\Models\Role;

/**
 * Resolves effective permissions for a user.
 *
 * How it works:
 * 1. Each role has a default permission set (ROLE_DEFAULTS below).
 * 2. A user can have per-user overrides in their `permissions` JSON column.
 * 3. Effective = role defaults MERGED with user overrides (overrides win).
 *
 * Permission keys follow the pattern: "{module}.{action}"
 *   e.g. "employees.view", "employees.create", "payroll.view"
 *
 * For sidebar visibility, we only check "{module}.view".
 */
class PermissionService
{
    /**
     * All available permissions in the system.
     * This is the single source of truth.
     */
    public const ALL_PERMISSIONS = [
        // Core
        'dashboard.view',
        'clients.view',       'clients.create',    'clients.edit',    'clients.delete',
        'contracts.view',     'contracts.create',  'contracts.edit',  'contracts.delete',

        // HR
        'employees.view',     'employees.create',  'employees.edit',  'employees.delete', 'employees.scope_contracts',
        'driver_expenses.view','driver_expenses.create','driver_expenses.edit','driver_expenses.delete',
        'leaves.view',        'leaves.create',     'leaves.edit',     'leaves.delete',
        'evaluations.view',   'evaluations.create','evaluations.edit','evaluations.delete',
        'custody.view',       'custody.create',    'custody.edit',    'custody.delete',
        'guarantees.view',    'guarantees.create', 'guarantees.edit', 'guarantees.delete',

        // Operations
        'daily_logs.view',    'daily_logs.create', 'daily_logs.edit', 'daily_logs.delete',
        'operations.view',
        'violations.view',    'violations.create', 'violations.edit', 'violations.delete',
        'cash.view',          'cash.create',       'cash.edit',

        // Fleet
        'vehicles.view',      'vehicles.create',   'vehicles.edit',   'vehicles.delete',
        'maintenance.view',   'maintenance.create','maintenance.edit','maintenance.delete',
        'vehicle_expenses.view','vehicle_expenses.create','vehicle_expenses.edit','vehicle_expenses.delete',

        // Finance
        'payroll.view',       'payroll.create',    'payroll.edit',
        'contract_payroll.view','contract_payroll.create','contract_payroll.edit','contract_payroll.approve','contract_payroll.delete',
        'salary_advances.view','salary_advances.create','salary_advances.edit',
        'op_advances.view',   'op_advances.create', 'op_advances.edit', 'op_advances.delete',
        'reports.view',

        // Admin
        'settings.view',      'settings.edit',
    ];

    /**
     * Default permissions per role.
     * true = granted, false/absent = denied.
     */
    public const ROLE_DEFAULTS = [
        'admin' => [
            'dashboard.view' => true,
            'clients.view' => true,    'clients.create' => true,    'clients.edit' => true,    'clients.delete' => true,
            'contracts.view' => true,  'contracts.create' => true,  'contracts.edit' => true,  'contracts.delete' => true,
            'employees.view' => true,  'employees.create' => true,  'employees.edit' => true,  'employees.delete' => true,
            'driver_expenses.view' => true, 'driver_expenses.create' => true, 'driver_expenses.edit' => true, 'driver_expenses.delete' => true,
            'leaves.view' => true,     'leaves.create' => true,     'leaves.edit' => true,     'leaves.delete' => true,
            'evaluations.view' => true,'evaluations.create' => true,'evaluations.edit' => true,'evaluations.delete' => true,
            'custody.view' => true,    'custody.create' => true,    'custody.edit' => true,    'custody.delete' => true,
            'guarantees.view' => true, 'guarantees.create' => true, 'guarantees.edit' => true, 'guarantees.delete' => true,
            'daily_logs.view' => true, 'daily_logs.create' => true, 'daily_logs.edit' => true, 'daily_logs.delete' => true,
            'operations.view' => true,
            'violations.view' => true, 'violations.create' => true, 'violations.edit' => true, 'violations.delete' => true,
            'cash.view' => true,       'cash.create' => true,       'cash.edit' => true,
            'vehicles.view' => true,   'vehicles.create' => true,   'vehicles.edit' => true,   'vehicles.delete' => true,
            'maintenance.view' => true,'maintenance.create' => true,'maintenance.edit' => true,'maintenance.delete' => true,
            'vehicle_expenses.view' => true, 'vehicle_expenses.create' => true, 'vehicle_expenses.edit' => true, 'vehicle_expenses.delete' => true,
            'payroll.view' => true,    'payroll.create' => true,    'payroll.edit' => true,
            'contract_payroll.view' => true, 'contract_payroll.create' => true, 'contract_payroll.edit' => true, 'contract_payroll.approve' => true, 'contract_payroll.delete' => true,
            'salary_advances.view' => true, 'salary_advances.create' => true, 'salary_advances.edit' => true,
            'op_advances.view' => true, 'op_advances.create' => true, 'op_advances.edit' => true, 'op_advances.delete' => true,
            'reports.view' => true,
            'settings.view' => true,   'settings.edit' => true,
        ],

        'operator' => [
            'dashboard.view' => true,
            'daily_logs.view' => true, 'daily_logs.create' => true, 'daily_logs.edit' => true,
            'operations.view' => true,
            'violations.view' => true, 'violations.create' => true, 'violations.edit' => true,
            'cash.view' => true,       'cash.create' => true,       'cash.edit' => true,
            'vehicles.view' => true,
            'maintenance.view' => true,'maintenance.create' => true,
            'leaves.view' => true,     'leaves.create' => true,
            'driver_expenses.view' => true, 'driver_expenses.create' => true,
        ],

        'accountant' => [
            'dashboard.view' => true,
            'vehicle_expenses.view' => true, 'vehicle_expenses.create' => true, 'vehicle_expenses.edit' => true,
            'driver_expenses.view' => true, 'driver_expenses.create' => true, 'driver_expenses.edit' => true,
            'payroll.view' => true,    'payroll.create' => true,    'payroll.edit' => true,
            'contract_payroll.view' => true, 'contract_payroll.create' => true, 'contract_payroll.edit' => true, 'contract_payroll.approve' => true,
            'salary_advances.view' => true, 'salary_advances.create' => true,
            'reports.view' => true,
        ],
    ];

    /**
     * Resolve effective permissions for a user.
     *
     * @param  string      $role        The user's role (admin/operator/accountant)
     * @param  array|null  $overrides   Per-user permission overrides from DB
     * @param  bool        $isSuperAdmin
     * @return array<string, bool>
     */
    public static function resolve(string $role, ?array $overrides = null, bool $isSuperAdmin = false, ?User $user = null): array
    {
        // Super admin gets everything
        if ($isSuperAdmin || ($user && $user->isSuperAdmin())) {
            return array_fill_keys(self::ALL_PERMISSIONS, true);
        }

        $user = $user ?? auth()->user();
        $roleModel = null;

        if ($user) {
            // 1. Try finding role model directly from Employee assignment (by user_id)
            $employee = Employee::withoutGlobalScopes()
                ->where(function($q) use ($user) {
                    $q->where('user_id', $user->id);
                    if (!empty($user->email)) {
                        $q->orWhereHas('user', function($uq) use ($user) {
                            $uq->where('email', $user->email);
                        });
                    }
                })
                ->whereNotNull('admin_role_id')
                ->first();

            if ($employee && $employee->admin_role_id) {
                $roleModel = Role::withoutGlobalScopes()->find($employee->admin_role_id);
            }
        }

        // 2. Try finding by custom role ID or custom name in roles table
        if (!$roleModel && $user && !in_array($role, ['super_admin', 'driver'])) {
            $companyId = $user->company_id ?? app('current_company_id');
            $roleModel = Role::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where(function($q) use ($role) {
                    $q->where('name', $role)
                      ->orWhere('id', $role);
                })
                ->first();
        }

        if ($roleModel && !empty($roleModel->allowed_modules)) {
            $effective = ['dashboard.view' => true];
            $modules = (array) $roleModel->allowed_modules;

            // Basic operations tab access if daily_logs or operations is granted
            if (in_array('daily_logs', $modules) || in_array('daily_logs.view', $modules) || in_array('operations', $modules) || in_array('operations.view', $modules)) {
                $effective['operations.view'] = true;
            }

            foreach (self::ALL_PERMISSIONS as $perm) {
                $mod = explode('.', $perm)[0];
                if (in_array($perm, $modules) || in_array($mod, $modules)) {
                    $effective[$perm] = true;
                }
            }
        } else if (isset(self::ROLE_DEFAULTS[$role])) {
            $effective = self::ROLE_DEFAULTS[$role];
        } else {
            // Fallback for company admin users with no custom role assigned
            $effective = self::ROLE_DEFAULTS['admin'];
        }

        // Merge user-level overrides (they win over role defaults)
        if ($overrides) {
            foreach ($overrides as $key => $value) {
                $effective[$key] = (bool) $value;
            }
        }

        return $effective;
    }

    /**
     * Check a single permission for a user.
     */
    public static function can(string $role, string $permission, ?array $overrides = null, bool $isSuperAdmin = false, ?User $user = null): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        $resolved = self::resolve($role, $overrides, $isSuperAdmin, $user);
        return !empty($resolved[$permission]);
    }
}
