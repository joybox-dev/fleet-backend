<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsolidatedPayrollRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'year',
        'month',
        'status',
        'total_drivers',
        'total_orders',
        'total_gross_earnings',
        'total_violations_deductions',
        'total_advances_deductions',
        'total_manual_adjustments',
        'total_final_net_payout',
        'snapshot_data',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'snapshot_data' => 'array',
        'approved_at' => 'datetime',
        'total_gross_earnings' => 'decimal:3',
        'total_violations_deductions' => 'decimal:3',
        'total_advances_deductions' => 'decimal:3',
        'total_manual_adjustments' => 'decimal:3',
        'total_final_net_payout' => 'decimal:3',
    ];

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function advanceDeductions(): HasMany
    {
        return $this->hasMany(AdvanceDeduction::class, 'consolidated_run_id');
    }
}
