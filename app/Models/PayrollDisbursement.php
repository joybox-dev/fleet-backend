<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment to a driver against one approved consolidated month: so much by bank transfer, so
 * much in cash, on a given day. The payer chooses the amount — the sheet only suggests one — so a
 * row may be less than the month (a debt netted off, a part payment) or more than it.
 */
class PayrollDisbursement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'consolidated_run_id',
        'employee_id',
        'bank_amount',
        'cash_amount',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'bank_amount' => 'decimal:3',
        'cash_amount' => 'decimal:3',
        'paid_at' => 'date',
    ];

    public function total(): float
    {
        return round((float) $this->bank_amount + (float) $this->cash_amount, 3);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPayrollRun::class, 'consolidated_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'consolidated_run_id' => $this->consolidated_run_id,
            'employee_id' => $this->employee_id,
            'bank_amount' => round((float) $this->bank_amount, 3),
            'cash_amount' => round((float) $this->cash_amount, 3),
            'total' => $this->total(),
            'paid_at' => $this->paid_at?->toDateString(),
            'notes' => $this->notes,
            'created_by_name' => $this->relationLoaded('createdBy') ? $this->createdBy?->name : null,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
