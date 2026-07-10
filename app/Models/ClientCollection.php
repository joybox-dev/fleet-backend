<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientCollection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'contract_id',
        'amount',
        'date',
        'payment_method',
        'notes'
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'date' => 'date'
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
