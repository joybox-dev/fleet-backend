<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverExpense extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'employee_id',
        'vehicle_id',
        'expense_type_id',
        'expense_type',
        'amount',
        'borne_by',
        'company_amount',
        'driver_amount',
        'expense_date',
        'vendor',
        'receipt_path',
        'description',
        'notes',
        'is_deducted',
        'payroll_slip_id',
    ];

    protected $casts = [
        'amount'         => 'decimal:3',
        'company_amount' => 'decimal:3',
        'driver_amount'  => 'decimal:3',
        'expense_date'   => 'date',
        'is_deducted'    => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(VehicleExpenseType::class, 'expense_type_id');
    }

    public function payrollSlip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }
}
