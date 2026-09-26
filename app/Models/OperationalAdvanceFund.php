<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-over of cash to an administrative employee's float, or one take-back (negative).
 */
class OperationalAdvanceFund extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'amount', 'date', 'notes', 'created_by'];

    protected $casts = [
        'amount' => 'decimal:3',
        'date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
