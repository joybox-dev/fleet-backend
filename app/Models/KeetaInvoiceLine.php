<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An adjustment or penalty line of a Keeta statement — an incentive, a service-quality deduction, a
 * food-damage compensation with its violation — kept per rider. Per-order lines are not stored.
 */
class KeetaInvoiceLine extends Model
{
    protected $fillable = [
        'keeta_invoice_id', 'courier_id', 'employee_id', 'transaction_type', 'label', 'amount', 'note',
        'ticket_id', 'violation_id', 'violation_type', 'punishment',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(KeetaInvoice::class, 'keeta_invoice_id');
    }
}
