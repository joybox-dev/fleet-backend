<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CurrencyExchangeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'from_currency',
        'to_currency',
        'exchange_rate',
        'year',
        'month'
    ];

    protected $casts = [
        'exchange_rate' => 'float',
        'year' => 'integer',
        'month' => 'integer',
    ];
}
