<?php

namespace App\Services;

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
        'employees.view',     'employees.create',  'employees.edit',  'employees.delete',
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
        'vehicle_expenses.view','vehicle_expenses.create','vehicle_expenses.edit',

        // Finance
        'payroll.view',       'payroll.create',    'payroll.edit',
        'salary_advances.view','salary_advances.create','salary_advances.edit',
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
            'vehicle_expenses.view' => true, 'vehicle_expenses.create' => true, 'vehicle_expenses.edit' => true,
            'payroll.view' => true,    'payroll.create' => true,    'payroll.edit' => true,
            'salary_advances.view' => true, 'salary_advances.create' => true, 'salary_advances.edit' => true,
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
        ],

        'accountant' => [
            'dashboard.view' => true,
            'vehicle_expenses.view' => true, 'vehicle_expenses.create' => true, 'vehicle_expenses.edit' => true,
            'payroll.view' => true,    'payroll.create' => true,    'payroll.edit' => true,
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
        if ($isSuperAdmin) {
            return array_fill_keys(self::ALL_PERMISSIONS, true);
        }

        $user = $user ?? auth()->user();
        $roleModel = null;

        // 1. Try finding role model directly from Employee assignment
        if ($user) {
            $employee = \App\Models\Employee::where('user_id', $user->id)->first();
            if ($employee && $employee->admin_role_id) {
                $roleModel = \App\Models\Role::find($employee->admin_role_id);
            }
        }

        // 2. Try finding by name or ID in roles table
        if (!$roleModel) {
            $roleModel = \App\Models\Role::where('name', $role)
                ->orWhere('id', $role)
                ->orWhere('name', 'like', "%{$role}%")
                ->first();
        }

        if ($roleModel && !empty($roleModel->allowed_modules)) {
            $effective = ['dashboard.view' => true];
            $modules = (array) $roleModel->allowed_modules;

            // Auto-map sub-modules
            if (in_array('daily_logs', $modules)) $modules[] = 'operations';
            if (in_array('employees', $modules)) $modules[] = 'evaluations';
            if (in_array('custody', $modules)) $modules[] = 'guarantees';
            if (in_array('payroll', $modules)) $modules[] = 'salary_advances';
            if (in_array('vehicles', $modules)) $modules[] = 'vehicle_expenses';

            foreach (self::ALL_PERMISSIONS as $perm) {
                $mod = explode('.', $perm)[0];
                if (in_array($mod, $modules)) {
                    $effective[$perm] = true;
                }
            }
        } else if (isset(self::ROLE_DEFAULTS[$role])) {
            $effective = self::ROLE_DEFAULTS[$role];
        } else {
            // Fallback to full admin permissions
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
    public static function can(string $role, string $permission, ?array $overrides = null, bool $isSuperAdmin = false): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        // Check override first
        if ($overrides && array_key_exists($permission, $overrides)) {
            return (bool) $overrides[$permission];
        }

        // Fall back to role default
        return (bool) (self::ROLE_DEFAULTS[$role][$permission] ?? false);
    }
}
