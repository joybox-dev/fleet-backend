<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What stood between one driver and the company before his first approved month here: positive is
 * money the company holds for him, negative is money he owes it. One row per driver, and the
 * first line of his running account — PayrollBalanceService starts from it.
 */
class DriverOpeningBalance extends Model
{
    use BelongsToCompany;

    /** How the figure is named wherever the account lists what it is made of. */
    public const LABEL = 'رصيد أول المدة';

    protected $fillable = [
        'company_id',
        'employee_id',
        'amount',
        'balance_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'balance_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'amount' => round((float) $this->amount, 3),
            'balance_date' => $this->balance_date?->toDateString(),
            'notes' => $this->notes,
            'created_by_name' => $this->relationLoaded('createdBy') ? $this->createdBy?->name : null,
            'updated_by_name' => $this->relationLoaded('updatedBy') ? $this->updatedBy?->name : null,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
