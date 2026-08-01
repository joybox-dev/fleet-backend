<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name', 'name_ar', 'code',
        'logo_path', 'branding', 'enabled_modules',
        'phone', 'email', 'address', 'tax_number',
        'currency', 'is_active', 'settings',
        // ERPNext mapping
        'erp_company_name', 'erp_cost_center', 'erp_abbreviation',
        'erp_config', 'erp_sync_status',
    ];

    protected $casts = [
        'branding'        => 'array',
        'enabled_modules' => 'array',
        'settings'        => 'array',
        'erp_config'      => 'array',
        'is_active'       => 'boolean',
    ];

    /**
     * Default modules enabled for new companies.
     */
    public const DEFAULT_MODULES = [
        'dashboard', 'clients', 'contracts', 'employees', 'vehicles',
        'daily_logs', 'violations', 'maintenance', 'cash',
        'custody', 'leaves', 'payroll', 'reports', 'settings',
        'guarantees', 'vehicle_expenses', 'salary_advances', 'op_advances',
    ];

    /**
     * All available modules in the system.
     */
    public const ALL_MODULES = [
        'dashboard', 'clients', 'contracts', 'employees', 'vehicles',
        'daily_logs', 'violations', 'maintenance', 'cash',
        'custody', 'leaves', 'payroll', 'reports', 'settings',
        'guarantees', 'vehicle_expenses', 'salary_advances', 'op_advances',
        'operations', 'hr_documents', 'evaluations',
        'excel_import', 'accounting',
    ];

    /* ── Relationships ── */

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /* ── Helpers ── */

    /**
     * Check if a module is enabled for this company.
     */
    public function hasModule(string $module): bool
    {
        return in_array($module, $this->enabled_modules ?? []);
    }
}
