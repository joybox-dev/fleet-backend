<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSlip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id',
        'base_official', 'base_actual', 'orders_bonus', 'fuel_allowance',
        'other_bonuses', 'total_orders',
        'violations_deduction', 'maintenance_deduction', 'custody_deduction', 'other_deductions',
        'gross_official', 'gross_actual', 'cash_portion',
        'erp_id', 'erp_synced_at', 'erp_sync_status', 'notes',
    ];

    protected $casts = [
        'base_official'          => 'decimal:3',
        'base_actual'            => 'decimal:3',
        'orders_bonus'           => 'decimal:3',
        'fuel_allowance'         => 'decimal:3',
        'violations_deduction'   => 'decimal:3',
        'maintenance_deduction'  => 'decimal:3',
        'custody_deduction'      => 'decimal:3',
        'gross_official'         => 'decimal:3',
        'gross_actual'           => 'decimal:3',
        'cash_portion'           => 'decimal:3',
    ];

    public function payrollRun(): BelongsTo { return $this->belongsTo(PayrollRun::class); }
    public function employee(): BelongsTo   { return $this->belongsTo(Employee::class); }
}
