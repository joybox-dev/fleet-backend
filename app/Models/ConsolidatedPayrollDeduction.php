<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what an approved consolidated month collected from an employee.
 *
 * Existence of a row is the authoritative answer to "was this already deducted", for every
 * source type, replacing the per-table `is_deducted` booleans that could not say which
 * payroll run had taken the money.
 */
class ConsolidatedPayrollDeduction extends Model
{
    use BelongsToCompany;

    public const SOURCE_VIOLATION = 'violation';

    public const SOURCE_MAINTENANCE = 'maintenance';

    public const SOURCE_CUSTODY = 'custody';

    // There was a SOURCE_LEAVE. Unpaid leave is no longer deducted from a driver: he is paid for the
    // days he worked, so a day of leave already costs him that day, and charging the leave record on
    // top took it off him twice. Administrative staff, on a flat monthly salary, will need it —
    // see the note in CompanyDeductionService.

    public const SOURCE_DRIVER_EXPENSE = 'driver_expense';

    public const SOURCE_ADVANCE = 'advance';

    protected $fillable = [
        'company_id',
        'consolidated_run_id',
        'employee_id',
        'source_type',
        'source_id',
        'amount',
        'label',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'source_id' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPayrollRun::class, 'consolidated_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
