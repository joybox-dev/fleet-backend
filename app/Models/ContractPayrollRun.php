<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractPayrollRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'contract_id', 'year', 'month', 'status',
        'total_drivers', 'total_orders',
        'total_gross_earnings', 'total_violations_deductions', 'total_net_payout',
        'snapshot_data', 'approved_by', 'approved_at', 'notes',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'total_drivers' => 'integer',
        'total_orders' => 'integer',
        'total_gross_earnings' => 'decimal:3',
        'total_violations_deductions' => 'decimal:3',
        'total_net_payout' => 'decimal:3',
        'approved_at' => 'datetime',
        'snapshot_data' => 'array',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
