<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractMonthlyParameter extends Model
{
    use BelongsToCompany;

    protected $with = ['mandatoryDays'];

    protected $fillable = [
        'company_id',
        'contract_id',
        'year',
        'month',
        'min_valid_days',
        'min_completed_orders',
        'daily_active_time_percentage',
        'daily_min_orders',
        'capacity_incentive_rules',
        'experience_incentive_rules'
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'min_valid_days' => 'integer',
        'min_completed_orders' => 'integer',
        'daily_active_time_percentage' => 'float',
        'daily_min_orders' => 'integer',
        'capacity_incentive_rules' => 'array',
        'experience_incentive_rules' => 'array',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function mandatoryDays(): HasMany
    {
        return $this->hasMany(ContractMandatoryDay::class);
    }
}
