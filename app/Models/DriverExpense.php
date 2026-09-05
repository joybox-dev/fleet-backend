<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverExpense extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

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
        'amount' => 'decimal:3',
        'company_amount' => 'decimal:3',
        'driver_amount' => 'decimal:3',
        'expense_date' => 'date',
        'is_deducted' => 'boolean',
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

    /**
     * The `expenseType` relation serialises under the same key as the `expense_type` column and
     * overwrites it. The guard here only caught the case where the relation had loaded a row; when
     * `expense_type_id` is null the relation serialises as null, and that null replaced a column
     * that held a perfectly good value — so an expense saved with a typed-in name came back from
     * the endpoint with no name, and reopening it for edit showed the field blank.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if (array_key_exists('expense_type', $array) && ! is_string($array['expense_type'])) {
            $relation = $array['expense_type'];

            if (is_array($relation)) {
                $array['expense_type_details'] = $relation;
            }

            $array['expense_type'] = $this->attributes['expense_type']
                ?? (is_array($relation) ? ($relation['name_ar'] ?? $relation['name'] ?? '') : null);
        }

        return $array;
    }
}
