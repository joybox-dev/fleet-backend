<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'name', 'name_ar', 'employee_number', 'nationality', 'civil_id', 'phone', 'has_whatsapp', 'whatsapp_company_number', 'whatsapp_language',
        'gender', 'date_of_birth', 'date_of_joining', 'employee_type',
        'status', 'status_reason', 'status_changed_at', 'probation_end_date',
        'pay_type', 'official_salary', 'actual_salary', 'rate_per_order', 'has_end_of_service',
        'health_card_expiry', 'residence_expiry', 'driving_license_expiry', 'work_permit_expiry',
        'stage_arrived', 'stage_medical_done', 'stage_medical_date',
        'stage_work_permit_done', 'stage_work_permit_date',
        'stage_driving_trial_done', 'stage_license_obtained', 'stage_license_date',
        'notes', 'erp_id', 'erp_synced_at', 'erp_sync_status',
        'target_orders_monthly', 'base_commission_rate', 'premium_commission_rate',
        'role_category', 'admin_role_id', 'user_id', 'salary_allocations',
    ];

    protected $casts = [
        'official_salary'       => 'decimal:3',
        'actual_salary'         => 'decimal:3',
        'rate_per_order'        => 'decimal:3',
        'has_end_of_service'    => 'boolean',
        'stage_arrived'         => 'boolean',
        'stage_medical_done'    => 'boolean',
        'stage_work_permit_done'=> 'boolean',
        'stage_driving_trial_done' => 'boolean',
        'stage_license_obtained'=> 'boolean',
        'target_orders_monthly' => 'integer',
        'base_commission_rate'  => 'decimal:3',
        'premium_commission_rate'=> 'decimal:3',
        'admin_role_id'         => 'integer',
        'user_id'               => 'integer',
        'salary_allocations'    => 'array',
    ];

    protected $appends = ['active_assignments', 'email', 'assigned_role_id'];

    public function getActiveAssignmentsAttribute()
    {
        return $this->vehicleAssignments->where('is_active', true)->values();
    }

    public function getEmailAttribute(): ?string
    {
        return $this->user?->email;
    }

    public function getAssignedRoleIdAttribute()
    {
        return $this->admin_role_id;
    }

    public function vehicleAssignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(VehicleAssignment::class)->where('is_active', true);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class);
    }

    public function custodyItems(): HasMany
    {
        return $this->hasMany(CustodyItem::class);
    }

    public function payrollSlips(): HasMany
    {
        return $this->hasMany(PayrollSlip::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    public function salaryAdvances(): HasMany
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function guarantees(): HasMany
    {
        return $this->hasMany(DriverGuarantee::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(EmployeeEvaluation::class);
    }

    public function liableMaintenance(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class, 'liable_employee_id');
    }

    public function contractAssignments(): HasMany
    {
        return $this->hasMany(ContractAssignment::class);
    }

    public function activeContractAssignment(): HasOne
    {
        return $this->hasOne(ContractAssignment::class)->where('status', 'active');
    }

    public function supervisorCostAllocations(): HasMany
    {
        return $this->hasMany(SupervisorCostAllocation::class);
    }

    public function getDeletionBlocks(): array
    {
        $blocks = [];

        if ($this->vehicleAssignments()->where('is_active', true)->exists()) {
            $blocks[] = 'الموظف لديه سيارة معينة نشطة حالياً.';
        }

        if ($this->custodyItems()->whereNull('returned_date')->exists()) {
            $blocks[] = 'الموظف لديه عُهد غير مسترجعة في ذمته.';
        }

        if ($this->salaryAdvances()->where('remaining_balance', '>', 0)->exists()) {
            $blocks[] = 'الموظف لديه سلف مالية نشطة متبقي عليها أرصدة لم تسدد.';
        }

        if ($this->guarantees()->whereNull('returned_date')->where('status', 'received')->exists()) {
            $blocks[] = 'الموظف لديه مستندات ضمان محتجزة لم تُسترجع.';
        }

        if ($this->dailyLogs()->where('cash_pending', '>', 0)->exists()) {
            $blocks[] = 'الموظف لديه كاش معلق في ذمته لم يتم تسويته بعد.';
        }

        if ($this->violations()->where('is_driver_liable', true)->where('is_deducted', false)->exists()) {
            $blocks[] = 'الموظف لديه مخالفات مرورية مستحقة لم يتم استقطاعها أو تسويتها.';
        }

        if ($this->liableMaintenance()->where('is_driver_liable', true)->where('driver_deduction', '>', 0)->where('status', 'approved')->exists()) {
            $blocks[] = 'الموظف لديه مسؤولية مالية عن صيانة سيارات لم تسدد.';
        }

        return $blocks;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adminRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'admin_role_id');
    }

    public function operationalAdvances(): HasMany
    {
        return $this->hasMany(OperationalAdvance::class, 'employee_id');
    }

    /**
     * Retrieve the model for a bound value, including soft-deleted ones.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->withTrashed()
            ->first();
    }
}
