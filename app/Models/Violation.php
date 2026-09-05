<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Violation extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'employee_id', 'vehicle_id', 'created_by', 'violation_date',
        'violation_type', 'reference_number', 'amount', 'driver_deduction',
        'photo_path', 'is_driver_liable', 'is_deducted', 'payroll_slip_id',
        'notes', 'erp_id', 'erp_synced_at', 'erp_sync_status',
        'split_mode', 'driver_share', 'contract_share', 'charge_contract_id',
        'manual_audit_reason', 'is_driver_override', 'is_contract_override', 'assignment_override_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'driver_deduction' => 'decimal:3',
        'is_driver_liable' => 'boolean',
        'is_deducted' => 'boolean',
        'driver_share' => 'decimal:3',
        'contract_share' => 'decimal:3',
        'charge_contract_id' => 'integer',
        'is_driver_override' => 'boolean',
        'is_contract_override' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payrollSlip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class);
    }

    public function chargeContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'charge_contract_id');
    }
}
