<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryAdvance extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

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
        'status',
        'erp_id',
        'erp_synced_at',
        'erp_sync_status',
        'company_id',
    ];

    protected $casts = [
        'amount'              => 'decimal:3',
        'monthly_installment' => 'decimal:3',
        'remaining_balance'   => 'decimal:3',
        'advance_date'        => 'date',
        'erp_synced_at'       => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(AdvanceDeduction::class);
    }
}
