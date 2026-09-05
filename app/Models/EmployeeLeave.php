<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeLeave extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'employee_id', 'leave_type_id',
        'start_date', 'end_date', 'days_count',
        'status',
        'is_paid', 'daily_rate', 'penalty_multiplier', 'formula_version', 'total_deduction',
        'approved_by', 'approved_at',
        'reason', 'rejection_reason', 'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_paid' => 'boolean',
        'daily_rate' => 'decimal:3',
        'penalty_multiplier' => 'decimal:1',
        'total_deduction' => 'decimal:3',
        'approved_at' => 'datetime',
    ];

    /* ── Relationships ── */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ── Helpers ── */

    /**
     * Calculate the deduction amount for unpaid leaves.
     * Formula: daily_rate × days_count × penalty_multiplier
     * For paid leaves, deduction is always 0.
     */
    public function calculateDeduction(): float
    {
        if ($this->is_paid) {
            return 0;
        }

        return round(
            (float) $this->daily_rate * $this->days_count * (float) $this->penalty_multiplier,
            3
        );
    }
}
