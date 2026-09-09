<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;

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
        'driver_expenses.view', 'driver_expenses.create', 'driver_expenses.edit', 'driver_expenses.delete',
        'leaves.view',        'leaves.create',     'leaves.edit',     'leaves.delete',
        'evaluations.view',   'evaluations.create', 'evaluations.edit', 'evaluations.delete',
        'custody.view',       'custody.create',    'custody.edit',    'custody.delete',
        'guarantees.view',    'guarantees.create', 'guarantees.edit', 'guarantees.delete',

        // Operations
        'daily_logs.view',    'daily_logs.create', 'daily_logs.edit', 'daily_logs.delete',
        'operations.view',
        'violations.view',    'violations.create', 'violations.edit', 'violations.delete',
        'cash.view',          'cash.create',       'cash.edit',

        // Fleet
        'vehicles.view',      'vehicles.create',   'vehicles.edit',   'vehicles.delete',
        'maintenance.view',   'maintenance.create', 'maintenance.edit', 'maintenance.delete',
        'vehicle_expenses.view', 'vehicle_expenses.create', 'vehicle_expenses.edit', 'vehicle_expenses.delete',

        // Finance
        'payroll.view',       'payroll.create',    'payroll.edit',
        'contract_payroll.view', 'contract_payroll.create', 'contract_payroll.edit', 'contract_payroll.approve', 'contract_payroll.delete',
        'salary_advances.view', 'salary_advances.create', 'salary_advances.edit',
        'op_advances.view',   'op_advances.create', 'op_advances.edit', 'op_advances.delete',
        'reports.view',

        // Admin
        'settings.view',      'settings.edit',
    ];

    /**
     * The modules a role can be granted, as the role-management screen lists them.
     *
     * @var array<int, array{key: string, label: string, icon: string}>
     */
    public const MODULES = [
        ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊'],
        ['key' => 'clients', 'label' => 'العملاء', 'icon' => '🏢'],
        ['key' => 'contracts', 'label' => 'العقود والشرائح', 'icon' => '📜'],
        ['key' => 'employees', 'label' => 'الموظفين والسائقين', 'icon' => '👥'],
        ['key' => 'driver_expenses', 'label' => 'مصاريف السائقين', 'icon' => '💸'],
        ['key' => 'leaves', 'label' => 'الإجازات والغياب', 'icon' => '📅'],
        ['key' => 'evaluations', 'label' => 'التقييم الأداء', 'icon' => '⭐'],
        ['key' => 'custody', 'label' => 'العهد والأمانات', 'icon' => '📦'],
        ['key' => 'guarantees', 'label' => 'الضمانات المالية', 'icon' => '🔒'],
        ['key' => 'daily_logs', 'label' => 'سجلات العمل التشغيلية', 'icon' => '📝'],
        ['key' => 'operations', 'label' => 'العمليات التشغيلية', 'icon' => '⚡'],
        ['key' => 'violations', 'label' => 'المخالفات المرورية', 'icon' => '⚠️'],
        ['key' => 'cash', 'label' => 'تصفية الكاش والتسويات', 'icon' => '💰'],
        ['key' => 'vehicles', 'label' => 'المركبات والأسطول', 'icon' => '🚗'],
        ['key' => 'maintenance', 'label' => 'الصيانة والورش', 'icon' => '🔧'],
        ['key' => 'vehicle_expenses', 'label' => 'مصاريف المركبات', 'icon' => '⛽'],
        ['key' => 'payroll', 'label' => 'مسير الرواتب', 'icon' => '💵'],
        ['key' => 'contract_payroll', 'label' => 'كشوف رواتب العقود', 'icon' => '📋'],
        ['key' => 'salary_advances', 'label' => 'السلف الشخصية', 'icon' => '🏦'],
        ['key' => 'op_advances', 'label' => 'السلف التشغيلية', 'icon' => '🛠️'],
        ['key' => 'reports', 'label' => 'التقارير والإحصائيات', 'icon' => '📈'],
        ['key' => 'settings', 'label' => 'إعدادات النظام', 'icon' => '⚙️'],
    ];

    private const ACTION_LABELS = [
        'view' => 'عرض',
        'create' => 'إضافة',
        'edit' => 'تعديل',
        'delete' => 'حذف',
        'approve' => 'اعتماد',
        'scope_contracts' => 'تقييد بالعقود',
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
            'evaluations.view' => true, 'evaluations.create' => true, 'evaluations.edit' => true, 'evaluations.delete' => true,
            'custody.view' => true,    'custody.create' => true,    'custody.edit' => true,    'custody.delete' => true,
            'guarantees.view' => true, 'guarantees.create' => true, 'guarantees.edit' => true, 'guarantees.delete' => true,
            'daily_logs.view' => true, 'daily_logs.create' => true, 'daily_logs.edit' => true, 'daily_logs.delete' => true,
            'operations.view' => true,
            'violations.view' => true, 'violations.create' => true, 'violations.edit' => true, 'violations.delete' => true,
            'cash.view' => true,       'cash.create' => true,       'cash.edit' => true,
            'vehicles.view' => true,   'vehicles.create' => true,   'vehicles.edit' => true,   'vehicles.delete' => true,
            'maintenance.view' => true, 'maintenance.create' => true, 'maintenance.edit' => true, 'maintenance.delete' => true,
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
            'maintenance.view' => true, 'maintenance.create' => true,
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
     * What a user resolved to, remembered for the rest of the request.
     *
     * Every can() re-ran two queries — the employee link and the role row — and a single list
     * screen asks four or five times, so a third of its queries were permission lookups. The
     * entry is keyed by the user object and stamped with what it was computed from, so a
     * different role or override set on the same object is computed afresh.
     *
     * @var \WeakMap<User, array{stamp: string, permissions: array<string, bool>}>|null
     */
    private static ?\WeakMap $resolved = null;

    /**
     * Resolve effective permissions for a user.
     *
     * @param  string  $role  The user's role (admin/operator/accountant, or a company-defined name)
     * @param  array<string, mixed>|null  $overrides  Per-user permission overrides from DB
     * @return array<string, bool>
     */
    public static function resolve(string $role, ?array $overrides = null, bool $isSuperAdmin = false, ?User $user = null): array
    {
        // Super admin gets everything
        if ($isSuperAdmin || ($user && $user->isSuperAdmin())) {
            return array_fill_keys(self::ALL_PERMISSIONS, true);
        }

        $user = $user ?? auth()->user();

        $stamp = md5(json_encode([$role, $overrides, $user?->company_id, $user?->email]));
        if ($user) {
            self::$resolved ??= new \WeakMap;
            $remembered = self::$resolved[$user] ?? null;
            if ($remembered && $remembered['stamp'] === $stamp) {
                return $remembered['permissions'];
            }
        }

        $roleModel = null;

        if ($user) {
            // 1. Try finding role model directly from Employee assignment (by user_id)
            $employee = Employee::withoutGlobalScopes()
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                    if (! empty($user->email)) {
                        $q->orWhereHas('user', function ($uq) use ($user) {
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
        if (! $roleModel && $user && ! in_array($role, ['super_admin', 'driver'])) {
            $companyId = $user->company_id ?? app('current_company_id');
            $roleModel = Role::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where(function ($q) use ($role) {
                    $q->where('name', $role)
                        ->orWhere('id', $role);
                })
                ->first();
        }

        if ($roleModel) {
            // The role's own module list decides — including when that list is empty. An empty
            // role used to fall through to the full admin set below, which is how a role
            // configured with no modules at all («أبو حضرم», two logins) was silently an admin.
            $effective = self::fromModules((array) ($roleModel->allowed_modules ?? []));
        } elseif (isset(self::ROLE_DEFAULTS[$role])) {
            $effective = self::ROLE_DEFAULTS[$role];
        } else {
            // A role name nothing recognises grants nothing beyond the dashboard. It used to
            // grant everything.
            $effective = ['dashboard.view' => true];
        }

        // Merge user-level overrides (they win over role defaults)
        if ($overrides) {
            foreach ($overrides as $key => $value) {
                $effective[$key] = (bool) $value;
            }
        }

        if ($user) {
            self::$resolved[$user] = ['stamp' => $stamp, 'permissions' => $effective];
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

        return ! empty($resolved[$permission]);
    }

    /**
     * Forget what was resolved for a user, so the next can() reads the database again.
     */
    public static function forget(User $user): void
    {
        if (self::$resolved !== null) {
            unset(self::$resolved[$user]);
        }
    }

    /**
     * A permission key as the screens name it: «إعدادات النظام: تعديل».
     */
    public static function label(string $permission): string
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, '');

        $moduleLabel = (collect(self::MODULES)->firstWhere('key', $module) ?? [])['label'] ?? $module;
        $actionLabel = self::ACTION_LABELS[$action] ?? $action;

        return trim("{$moduleLabel}: {$actionLabel}", ': ');
    }

    /**
     * The permission set a role's module list grants. A list may name whole modules
     * («contracts») or single permissions («contracts.view»); both forms are honoured.
     *
     * @param  array<int, string>  $modules
     * @return array<string, bool>
     */
    private static function fromModules(array $modules): array
    {
        $effective = ['dashboard.view' => true];

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

        return $effective;
    }
}
