<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverContractOverride extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'contract_assignment_id',
        'custom_order_commission',
        'custom_hourly_rate',
        'custom_fixed_salary',
        'custom_monthly_target',
        'custom_monthly_bonus',
        'custom_valid_days',
        'customization_reason',
        'effective_from',
        'effective_to'
    ];

    protected $casts = [
        'custom_order_commission' => 'float',
        'custom_hourly_rate' => 'float',
        'custom_fixed_salary' => 'float',
        'custom_monthly_target' => 'integer',
        'custom_monthly_bonus' => 'float',
        'custom_valid_days' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function contractAssignment(): BelongsTo
    {
        return $this->belongsTo(ContractAssignment::class);
    }
}
