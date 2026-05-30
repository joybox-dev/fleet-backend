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

    protected static function booted(): void
    {
        static::saved(function ($advance) {
            self::recalculatePayroll($advance->employee_id, $advance->advance_date);
        });
        static::deleted(function ($advance) {
            self::recalculatePayroll($advance->employee_id, $advance->advance_date);
        });
    }

    private static function recalculatePayroll($employeeId, $dateStr): void
    {
        try {
            $date = \Carbon\Carbon::parse($dateStr);
            $run = \App\Models\PayrollRun::where('year', $date->year)
                ->where('month', $date->month)
                ->where('status', 'draft')
                ->first();
            if ($run) {
                \App\Http\Controllers\Api\PayrollController::recalculateRun($run);
            }
        } catch (\Throwable $e) {
            \Log::error("Recalculate draft payroll failed in SalaryAdvance: " . $e->getMessage());
        }
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
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
