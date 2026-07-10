<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'payroll_slip_id',
        'amount',
        'date',
        'type',
        'payment_method',
        'audit_reason',
        'notes'
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'date' => 'date'
    ];

    public function payrollSlip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class);
    }
}
