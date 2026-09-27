<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keeta's monthly partner statement for one contract, as imported. `invoice_amount` is what Keeta
 * owes the company for the month and is the month's revenue; tips come on top and belong to the
 * riders, so they are kept apart.
 */
class KeetaInvoice extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'contract_id', 'year', 'month', 'billing_cycle', 'partner_id', 'partner_name',
        'order_pricing', 'experience_incentive', 'capacity_incentive', 'other_income', 'tips', 'deduction',
        'food_compensation', 'other_adjustment', 'withholding', 'invoice_amount', 'total_payable',
        'riders_count', 'valid_riders', 'orders_count', 'original_filename', 'file_path', 'imported_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'order_pricing' => 'decimal:3',
        'experience_incentive' => 'decimal:3',
        'capacity_incentive' => 'decimal:3',
        'other_income' => 'decimal:3',
        'tips' => 'decimal:3',
        'deduction' => 'decimal:3',
        'food_compensation' => 'decimal:3',
        'other_adjustment' => 'decimal:3',
        'withholding' => 'decimal:3',
        'invoice_amount' => 'decimal:3',
        'total_payable' => 'decimal:3',
        'riders_count' => 'integer',
        'valid_riders' => 'integer',
        'orders_count' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function riders(): HasMany
    {
        return $this->hasMany(KeetaInvoiceRider::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KeetaInvoiceLine::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** What Keeta paid per delivered order this month, or null when the statement has no orders. */
    public function averageOrderPrice(): ?float
    {
        return $this->orders_count > 0 ? round((float) $this->order_pricing / $this->orders_count, 4) : null;
    }

    /** Keeta's incentives and rewards for the month: experience, capacity and its other rewards. */
    public function incentives(): float
    {
        return round((float) $this->experience_incentive + (float) $this->capacity_incentive + (float) $this->other_income, 3);
    }

    /** Keeta's own deductions and adjustments, signed as the statement writes them. */
    public function adjustments(): float
    {
        return round((float) $this->deduction + (float) $this->food_compensation + (float) $this->other_adjustment + (float) $this->withholding, 3);
    }

    /**
     * The invoice the way it adds up — orders + incentives + adjustments = invoice amount — with the
     * tips that come on top for the riders.
     *
     * @return array{order_pricing: float, orders_count: int, average_order_price: float|null, incentives: float, adjustments: float, tips: float, invoice_amount: float}
     */
    public function breakdown(): array
    {
        return [
            'order_pricing' => round((float) $this->order_pricing, 3),
            'orders_count' => (int) $this->orders_count,
            'average_order_price' => $this->averageOrderPrice(),
            'incentives' => $this->incentives(),
            'adjustments' => $this->adjustments(),
            'tips' => round((float) $this->tips, 3),
            'invoice_amount' => round((float) $this->invoice_amount, 3),
        ];
    }
}
