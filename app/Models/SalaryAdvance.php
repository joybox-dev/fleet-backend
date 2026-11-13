<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryAdvance extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /** What the advance is for — each its own instalments, stopped or moved on its own. */
    public const PURPOSES = ['cash' => 'نقدية', 'phone' => 'هاتف', 'sim' => 'خط', 'other' => 'أخرى'];

    /** The line was cut: what was taken stays taken, what was left is no longer owed. */
    public const STATUS_STOPPED = 'stopped';

    /** What was left went on to another driver as an advance of its own. */
    public const STATUS_TRANSFERRED = 'transferred';

    protected $fillable = [
        'employee_id',
        'approved_by',
        'amount',
        'monthly_installment',
        'total_installments',
        'paid_installments',
        'remaining_balance',
        'advance_date',
        'reason',
        'voucher_path',
        'status',
        'purpose',
        'closed_at',
        'closed_balance',
        'closed_reason',
        'transferred_from_id',
        'transferred_to_id',
        'erp_id',
        'erp_synced_at',
        'erp_sync_status',
        'company_id',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'monthly_installment' => 'decimal:3',
        'remaining_balance' => 'decimal:3',
        'advance_date' => 'date',
        'closed_at' => 'date',
        'closed_balance' => 'decimal:3',
        'erp_synced_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_to_id')->withTrashed();
    }

    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_id')->withTrashed();
    }

    /** «سلفة» for cash, «سلفة (هاتف)» for the rest — how a sheet line names it. */
    public function purposeLabel(): string
    {
        $purpose = $this->purpose ?: 'cash';

        return $purpose === 'cash' ? 'سلفة' : 'سلفة ('.(self::PURPOSES[$purpose] ?? $purpose).')';
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(AdvanceDeduction::class);
    }
}
