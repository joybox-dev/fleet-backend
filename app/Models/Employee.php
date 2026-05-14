<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    ];

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
}
