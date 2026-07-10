<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalAdvanceReturn extends Model
{
    protected $fillable = [
        'operational_advance_id',
        'amount',
        'date'
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'date' => 'date'
    ];

    public function operationalAdvance(): BelongsTo
    {
        return $this->belongsTo(OperationalAdvance::class);
    }
}
