<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money the company spent on itself — rent, residencies, printing, bank fees — recorded against an
 * item of its expense tree. The company bears all of it; an employee, vehicle or contract it names
 * is for reference and reporting, never a charge on anyone.
 */
class CompanyExpense extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const PAID_VIA = [
        'cash' => 'نقداً',
        'bank' => 'تحويل بنكي',
        'custody' => 'من عهدة',
    ];

    protected $fillable = [
        'company_id', 'expense_category_id', 'amount', 'expense_date', 'description', 'vendor', 'reference',
        'paid_via', 'employee_id', 'vehicle_id', 'contract_id', 'receipt_path', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'expense_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
