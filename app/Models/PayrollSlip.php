<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSlip extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'payroll_run_id', 'employee_id',
        'base_official', 'base_actual', 'orders_bonus', 'fuel_allowance',
        'other_bonuses', 'total_orders',
        'violations_deduction', 'maintenance_deduction', 'custody_deduction', 'driver_expense_deduction', 'advance_deduction', 'leave_deduction', 'unpaid_leave_days', 'other_deductions',
        'gross_official', 'gross_actual', 'cash_portion',
        'erp_id', 'erp_synced_at', 'erp_sync_status', 'notes',
        'final_monthly_status', 'status_override_reason', 'total_contract_bonuses', 'total_capacity_incentive', 'total_experience_incentive', 'exchange_rate'
    ];

    protected $casts = [
        'base_official'            => 'decimal:3',
        'base_actual'              => 'decimal:3',
        'orders_bonus'             => 'decimal:3',
        'fuel_allowance'           => 'decimal:3',
        'violations_deduction'     => 'decimal:3',
        'maintenance_deduction'    => 'decimal:3',
        'custody_deduction'        => 'decimal:3',
        'driver_expense_deduction' => 'decimal:3',
        'advance_deduction'      => 'decimal:3',
        'leave_deduction'        => 'decimal:3',
        'gross_official'         => 'decimal:3',
        'gross_actual'           => 'decimal:3',
        'cash_portion'           => 'decimal:3',
    ];

    public function payrollRun(): BelongsTo { return $this->belongsTo(PayrollRun::class); }
    public function employee(): BelongsTo   { return $this->belongsTo(Employee::class); }
    public function driverExpenses()        { return $this->hasMany(DriverExpense::class); }
}
