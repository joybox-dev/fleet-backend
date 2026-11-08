<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Talabat's monthly payment statement for one contract, as imported. `riders_total` — every rider's
 * pickups, drop-offs, service payment and his own deductions — is the month's revenue. The lines
 * Talabat charges the company as a whole (the cash deficit, contract fees) come off what it
 * transfers, `net_payment`, and nothing else (owner, 2026-11-08).
 */
class TalabatStatement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'contract_id', 'year', 'month', 'riders_count', 'deliveries', 'pickups', 'dropoffs',
        'pickup_payment', 'dropoff_payment', 'service_payment', 'rider_adjustments', 'riders_total',
        'company_lines', 'company_total', 'net_payment', 'original_filename', 'file_path', 'imported_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'riders_count' => 'integer',
        'deliveries' => 'integer',
        'pickups' => 'integer',
        'dropoffs' => 'integer',
        'pickup_payment' => 'decimal:3',
        'dropoff_payment' => 'decimal:3',
        'service_payment' => 'decimal:3',
        'rider_adjustments' => 'decimal:3',
        'riders_total' => 'decimal:3',
        'company_lines' => 'array',
        'company_total' => 'decimal:3',
        'net_payment' => 'decimal:3',
    ];

    public function riders(): HasMany
    {
        return $this->hasMany(TalabatStatementRider::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
