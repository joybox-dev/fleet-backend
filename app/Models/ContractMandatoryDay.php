<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractMandatoryDay extends Model
{
    protected $table = 'contract_mandatory_days';

    protected $fillable = [
        'contract_monthly_parameter_id',
        'start_date',
        'end_date',
        'min_required_days'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'min_required_days' => 'integer'
    ];

    public function monthlyParameter(): BelongsTo
    {
        return $this->belongsTo(ContractMonthlyParameter::class, 'contract_monthly_parameter_id');
    }
}
