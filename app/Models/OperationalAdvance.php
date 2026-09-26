<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalAdvance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'employee_id',
        'funded_by_employee_id',
        'amount',
        'date',
        'reason',
        'status',
        'approved_by',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'date' => 'date',
        'approved_by' => 'integer',
        'funded_by_employee_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The administrative employee whose float paid for this custody; null when the company did. */
    public function fundedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'funded_by_employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OperationalAdvanceExpense::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OperationalAdvanceReturn::class);
    }
}
