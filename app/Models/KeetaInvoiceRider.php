<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rider's row of a Keeta statement. `employee_id` is the driver whose Keeta courier id is on
 * his assignment to the contract; null when no driver of ours carries that id.
 */
class KeetaInvoiceRider extends Model
{
    protected $fillable = [
        'keeta_invoice_id', 'courier_id', 'employee_id', 'name', 'phone', 'is_valid', 'reason',
        'valid_days', 'daily_hours', 'peak_hours', 'orders', 'order_pricing', 'experience_incentive',
        'capacity_incentive', 'other_income', 'tips', 'deduction', 'food_compensation', 'other_adjustment',
        'withholding', 'total_payable',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'valid_days' => 'decimal:2',
        'daily_hours' => 'decimal:2',
        'peak_hours' => 'decimal:2',
        'orders' => 'integer',
        'order_pricing' => 'decimal:3',
        'experience_incentive' => 'decimal:3',
        'capacity_incentive' => 'decimal:3',
        'other_income' => 'decimal:3',
        'tips' => 'decimal:3',
        'deduction' => 'decimal:3',
        'food_compensation' => 'decimal:3',
        'other_adjustment' => 'decimal:3',
        'withholding' => 'decimal:3',
        'total_payable' => 'decimal:3',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(KeetaInvoice::class, 'keeta_invoice_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** What the company earns on this rider: everything Keeta pays for him except his tips. */
    public function revenue(): float
    {
        return round((float) $this->total_payable - (float) $this->tips, 3);
    }

    /** His incentives and rewards for the month. */
    public function incentives(): float
    {
        return round((float) $this->experience_incentive + (float) $this->capacity_incentive + (float) $this->other_income, 3);
    }

    /** Keeta's deductions and adjustments on him, signed as the statement writes them. */
    public function adjustments(): float
    {
        return round((float) $this->deduction + (float) $this->food_compensation + (float) $this->other_adjustment + (float) $this->withholding, 3);
    }
}
