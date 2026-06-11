<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractBonus extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_bonuses';

    protected $fillable = [
        'company_id',
        'contract_id',
        'bonus_name',
        'amount',
        'is_valid_drivers_only'
    ];

    protected $casts = [
        'amount' => 'float',
        'is_valid_drivers_only' => 'boolean',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
