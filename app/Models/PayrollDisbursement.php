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

    /**
     * How much of a month may still go to the bank for one driver: his registered salary — the
     * figure the ministry and the bank know him by — less what this month has already sent there.
     * The owner's rule: a bank transfer never exceeds that salary; whatever a month owes above it
     * is handed over in cash. A driver with no registered salary is paid in cash only. Counted over
     * the whole month, so two transfers cannot add up to more than one may.
     *
     * @return array{salary: float, transferred: float, available: float}
     */
    public static function bankAllowance(Employee $employee, int $runId, ?int $excludeId = null): array
    {
        $salary = round((float) ($employee->official_salary ?? 0), 3);
        $transferred = round((float) static::withoutGlobalScopes()
            ->where('consolidated_run_id', $runId)
            ->where('employee_id', $employee->id)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->sum('bank_amount'), 3);

        return [
            'salary' => $salary,
            'transferred' => $transferred,
            'available' => round(max(0.0, $salary - $transferred), 3),
        ];
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
