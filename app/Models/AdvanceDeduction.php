<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceDeduction extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'salary_advance_id',
        'payroll_slip_id',
        'amount',
        'deduction_date',
        'company_id',
    ];

    protected $casts = [
        'amount'         => 'decimal:3',
        'deduction_date' => 'date',
    ];

    public function salaryAdvance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class);
    }

    public function payrollSlip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class);
    }
}
