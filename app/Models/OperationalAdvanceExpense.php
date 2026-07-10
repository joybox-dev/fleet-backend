<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalAdvanceExpense extends Model
{
    protected $fillable = [
        'operational_advance_id',
        'amount',
        'date',
        'description',
        'contract_id',
        'receipt_path'
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'date' => 'date',
        'contract_id' => 'integer'
    ];

    public function operationalAdvance(): BelongsTo
    {
        return $this->belongsTo(OperationalAdvance::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
