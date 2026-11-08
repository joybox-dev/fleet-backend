<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rider of a Talabat statement, his rows for the month added up: what his pickups and drop-offs
 * paid, the service-level payment, his own deductions, and his net — his share of the month.
 * `employee_id` is our driver when his Talabat rider id is on his assignment, or was linked at import.
 */
class TalabatStatementRider extends Model
{
    protected $fillable = [
        'talabat_statement_id', 'rider_id', 'name', 'vehicle', 'employee_id', 'hours', 'deliveries',
        'pickups', 'dropoffs', 'pickup_payment', 'dropoff_payment', 'service_payment', 'adjustments', 'net',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'hours' => 'decimal:2',
        'deliveries' => 'integer',
        'pickups' => 'integer',
        'dropoffs' => 'integer',
        'pickup_payment' => 'decimal:3',
        'dropoff_payment' => 'decimal:3',
        'service_payment' => 'decimal:3',
        'adjustments' => 'decimal:3',
        'net' => 'decimal:3',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(TalabatStatement::class, 'talabat_statement_id');
    }
}
